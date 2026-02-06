<?php

namespace Webman\Console\Commands;

use Doctrine\Inflector\InflectorFactory;
use support\Db;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Webman\Console\Util;
use Webman\Console\Commands\Concerns\MakeCommandHelpers;

#[AsCommand('make:model', 'Make model')]
class MakeModelCommand extends Command
{
    use MakeCommandHelpers;

    private const ORM_LARAVEL = 'laravel';
    private const ORM_THINKORM = 'tp';

    /**
     * @return void
     */
    protected function configure()
    {
        /**
         * Usage quick notes:
         * - Prefer explicit `--table/-t` when table name does not follow conventions.
         * - Interactive table picker is available only when terminal supports input; press Enter for more, 0 for empty model, /keyword to filter.
         *
         * 常用提示：
         * - 表名不符合约定时建议显式使用 `--table/-t`。
         * - 交互式选表仅在支持输入的终端下启用：回车=更多，0=空模型，/关键字=过滤。
         */
        $this->addArgument('name', InputArgument::REQUIRED, 'Model name');
        /**
         * @deprecated 过时的设计：`type` 位置参数易混淆且扩展性差，请使用 `--orm`/`-o`。
         * 兼容保留：`php webman make:model User tp`
         */
        $this->addArgument('type', InputArgument::OPTIONAL, 'Type (deprecated, use --orm/-o instead)');
        $this->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, 'Plugin name under plugin/. e.g. admin');
        $this->addOption('path', 'P', InputOption::VALUE_REQUIRED, 'Target directory (relative to base path). e.g. plugin/admin/app/model');
        $this->addOption('table', 't', InputOption::VALUE_REQUIRED, 'Specify table name. e.g. wa_users');
        $this->addOption('orm', 'o', InputOption::VALUE_REQUIRED, 'Select orm: laravel|thinkorm');
        $this->addOption('connection', 'c', InputOption::VALUE_OPTIONAL, 'Select database connection.');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Override existing file without confirmation.');

        // Symfony Console built-in help:
        // - php webman make:model --help
        // - php webman help make:model
        $this->setHelp($this->buildHelpText());

        // Display examples in synopsis (shown in --help).
        $this->addUsage('User');
        $this->addUsage('User -p admin');
        $this->addUsage('User -P plugin/admin/app/model');
        $this->addUsage('User -t wa_users -o laravel');
        $this->addUsage('User -t=wa_users -o=thinkorm -f');
        $this->addUsage('Admin/User --table=wa_admin_users --orm=laravel --connection=mysql');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = Util::nameToClass($input->getArgument('name'));
        $type = $input->getArgument('type');
        $plugin = $this->normalizeOptionValue($input->getOption('plugin'));
        $path = $this->normalizeOptionValue($input->getOption('path'));
        $orm = $this->normalizeOptionValue($input->getOption('orm'));
        $connection = $this->normalizeOptionValue($input->getOption('connection'));
        $table = $this->normalizeOptionValue($input->getOption('table'));
        $force = (bool)$input->getOption('force');

        if ($plugin && (str_contains($plugin, '/') || str_contains($plugin, '\\'))) {
            $output->writeln($this->msg('invalid_plugin', ['{plugin}' => $plugin]));
            return Command::FAILURE;
        }

        if ($plugin || $path) {
            $resolved = $this->resolveModelTargetByPluginOrPath($name, $plugin, $path, $output);
            if ($resolved === null) {
                return Command::FAILURE;
            }
            [$name, $namespace, $file] = $resolved;
        } else {
            // Original behavior for app models (backward compatible)
            if (!($pos = strrpos($name, '/'))) {
                $name = ucfirst($name);
                $model_str = Util::guessPath(app_path(), 'model') ?: 'model';
                $file = app_path() . DIRECTORY_SEPARATOR .  $model_str . DIRECTORY_SEPARATOR . "$name.php";
                $namespace = $model_str === 'Model' ? 'App\Model' : 'app\model';
            } else {
                $name_str = substr($name, 0, $pos);
                if ($real_name_str = Util::guessPath(app_path(), $name_str)) {
                    $name_str = $real_name_str;
                } else if ($real_section_name = Util::guessPath(app_path(), strstr($name_str, '/', true))) {
                    $upper = strtolower($real_section_name[0]) !== $real_section_name[0];
                } else if ($real_base_controller = Util::guessPath(app_path(), 'controller')) {
                    $upper = strtolower($real_base_controller[0]) !== $real_base_controller[0];
                }
                $upper = $upper ?? strtolower($name_str[0]) !== $name_str[0];
                if ($upper && !$real_name_str) {
                    $name_str = preg_replace_callback('/\/([a-z])/', function ($matches) {
                        return '/' . strtoupper($matches[1]);
                    }, ucfirst($name_str));
                }
                $path_for_file = "$name_str/" . ($upper ? 'Model' : 'model');
                $name = ucfirst(substr($name, $pos + 1));
                $file = app_path() . DIRECTORY_SEPARATOR . $path_for_file . DIRECTORY_SEPARATOR . "$name.php";
                $namespace = str_replace('/', '\\', ($upper ? 'App/' : 'app/') . $path_for_file);
            }
        }

        $output->writeln($this->msg('make_model', ['{name}' => $name]));
        $type = $this->resolveOrm($orm, $type, $output);
        // Resolve & validate DB connection:
        // - When --plugin/-p is provided and --connection/-c is provided, use the plugin's connection config.
        //   e.g. "-p admin -c mysql" => "plugin.admin.mysql"
        // - When no plugin is provided, validate that the given --connection/-c exists.
        // - When --connection/-c is omitted, prefer plugin default connection when plugin has DB config.
        [$ok, $connection] = $this->resolveAndValidateConnection($type, $plugin, $connection, $output);
        if (!$ok) {
            return Command::FAILURE;
        }

        if (is_file($file) && !$force) {
            $helper = $this->getHelper('question');
            $relative = $this->toRelativePath($file);
            $prompt = $this->msg('override_prompt', ['{path}' => $relative]);
            // Default: yes (Enter = yes)
            $question = new ConfirmationQuestion($prompt, true);
            if (!$helper->ask($input, $output, $question)) {
                return Command::SUCCESS;
            }
        }

        if (!$table) {
            $table = $this->promptForTableIfNeeded($input, $output, $type, $connection, $name) ?: null;
        }

        if ($type === self::ORM_THINKORM) {
            $this->createTpModel($name, $namespace, $file, $connection, $table, $output);
        } else {
            $this->createModel($name, $namespace, $file, $connection, $table, $output);
        }

        $output->writeln($this->msg('created', ['{path}' => $this->toRelativePath($file)]));
        return self::SUCCESS;
    }

    /**
     * Resolve orm type with backward compatibility.
     *
     * @param string|null $ormOption
     * @param string|null $deprecatedTypeArg
     * @param OutputInterface $output
     * @return string
     */
    protected function resolveOrm(?string $ormOption, ?string $deprecatedTypeArg, OutputInterface $output): string
    {
        $ormOption = $ormOption !== null ? strtolower(trim((string)$ormOption)) : null;
        $deprecatedTypeArg = $deprecatedTypeArg !== null ? strtolower(trim((string)$deprecatedTypeArg)) : null;

        if ($ormOption) {
            $ormOption = ltrim($ormOption, '=');
            if ($ormOption === 'thinkorm' || $ormOption === 'tp') {
                return self::ORM_THINKORM;
            }
            return self::ORM_LARAVEL;
        }

        if ($deprecatedTypeArg) {
            // Deprecated positional argument, keep compatible.
            $output->writeln($this->msg('deprecated_type'));
            if ($deprecatedTypeArg === 'thinkorm' || $deprecatedTypeArg === 'tp') {
                return self::ORM_THINKORM;
            }
            return self::ORM_LARAVEL;
        }

        // Auto detect for backward compatibility
        $database = config('database');
        if (isset($database['default']) && strpos($database['default'], 'plugin.') === 0) {
            $database = false;
        }
        $thinkorm = config('think-orm') ?: config('thinkorm');
        if (isset($thinkorm['default']) && strpos($thinkorm['default'], 'plugin.') === 0) {
            $thinkorm = false;
        }
        return !$database && $thinkorm ? self::ORM_THINKORM : self::ORM_LARAVEL;
    }

    /**
     * @param $class
     * @param $namespace
     * @param $file
     * @param string|null $connection
     * @param string|null $table
     * @param OutputInterface|null $output
     * @return void
     */
    protected function createModel($class, $namespace, $file, $connection = null, $table = null, ?OutputInterface $output = null)
    {
        $path = pathinfo($file, PATHINFO_DIRNAME);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $table_base = Util::classToName($class);
        $table_val = 'null';
        $meta_table = null;
        $pk = 'id';
        $properties = '';
        $connection = $connection ?: config('database.default');
        $timestamps = 'false';
        $hasCreatedAt = false;
        $hasUpdatedAt = false;
        try {
            $connectionConfig = $this->getLaravelConnectionConfig((string)$connection);
            $prefix = (string)($connectionConfig['prefix'] ?? '');
            $database = (string)($connectionConfig['database'] ?? '');
            $driver = (string)($connectionConfig['driver'] ?? 'mysql');
            $inflector = InflectorFactory::create()->build();
            $table_plura = $inflector->pluralize($inflector->tableize($class));
            $con = Db::connection($connection);

            // Table resolve
            if ($table) {
                $table = ltrim(trim((string)$table), '=');
                $table_for_model = ($prefix && str_starts_with($table, $prefix)) ? substr($table, strlen($prefix)) : $table;
                $table_val = "'" . $table_for_model . "'";
                $meta_table = $prefix . $table_for_model;
            } else {
                // 检查表是否存在（兼容MySQL和PostgreSQL）
                if ($driver === 'pgsql') {
                    $schema = (string)($connectionConfig['schema'] ?? 'public');
                    $exists_plura = $con->select("SELECT to_regclass('{$schema}.{$prefix}{$table_plura}') as table_exists");
                    $exists = $con->select("SELECT to_regclass('{$schema}.{$prefix}{$table_base}') as table_exists");

                    if (!empty($exists_plura[0]->table_exists)) {
                        $table_val = "'$table_plura'";
                        $meta_table = "{$prefix}{$table_plura}";
                    } else if (!empty($exists[0]->table_exists)) {
                        $table_val = "'$table_base'";
                        $meta_table = "{$prefix}{$table_base}";
                    }
                } else {
                    if ($con->select("show tables like '{$prefix}{$table_plura}'")) {
                        $table_val = "'$table_plura'";
                        $meta_table = "{$prefix}{$table_plura}";
                    } else if ($con->select("show tables like '{$prefix}{$table_base}'")) {
                        $table_val = "'$table_base'";
                        $meta_table = "{$prefix}{$table_base}";
                    }
                }
            }

            // 获取表注释和列信息（兼容MySQL和PostgreSQL）
            if ($meta_table) {
                if ($driver === 'pgsql') {
                    $schema = (string)($connectionConfig['schema'] ?? 'public');
                    $tableComment = $con->select("SELECT obj_description('{$schema}.{$meta_table}'::regclass) as table_comment");
                    if (!empty($tableComment) && !empty($tableComment[0]->table_comment)) {
                        $comments = $tableComment[0]->table_comment;
                        $properties .= " * {$meta_table} {$comments}" . PHP_EOL;
                    }

                    $columns = $con->select("
                        SELECT 
                            a.attname as column_name,
                            format_type(a.atttypid, a.atttypmod) as data_type,
                            CASE WHEN con.contype = 'p' THEN 'PRI' ELSE '' END as column_key,
                            d.description as column_comment
                        FROM pg_catalog.pg_attribute a
                        LEFT JOIN pg_catalog.pg_description d ON d.objoid = a.attrelid AND d.objsubid = a.attnum
                        LEFT JOIN pg_catalog.pg_constraint con ON con.conrelid = a.attrelid AND a.attnum = ANY(con.conkey) AND con.contype = 'p'
                        WHERE a.attrelid = '{$schema}.{$meta_table}'::regclass
                        AND a.attnum > 0 AND NOT a.attisdropped
                        ORDER BY a.attnum
                    ");

                    foreach ($columns as $item) {
                        if ($item->column_key === 'PRI') {
                            $pk = $item->column_name;
                            $item->column_comment = ($item->column_comment ? $item->column_comment . ' ' : '') . "(主键)";
                        }
                        $type = $this->getType($item->data_type);
                        if ($item->column_name === 'created_at') {
                            $hasCreatedAt = true;
                        }
                        if ($item->column_name === 'updated_at') {
                            $hasUpdatedAt = true;
                        }
                        $properties .= " * @property $type \${$item->column_name} " . ($item->column_comment ?? '') . "\n";
                    }

                } else {
                    $tableComment = $con->select('SELECT table_comment FROM information_schema.`TABLES` WHERE table_schema = ? AND table_name = ?', [$database, $meta_table]);
                    if (!empty($tableComment)) {
                        $comments = $tableComment[0]->table_comment ?? $tableComment[0]->TABLE_COMMENT;
                        $properties .= " * {$meta_table} {$comments}" . PHP_EOL;
                    }

                    foreach ($con->select("select COLUMN_NAME,DATA_TYPE,COLUMN_KEY,COLUMN_COMMENT from INFORMATION_SCHEMA.COLUMNS where table_name = '$meta_table' and table_schema = '$database' ORDER BY ordinal_position") as $item) {
                        if ($item->COLUMN_KEY === 'PRI') {
                            $pk = $item->COLUMN_NAME;
                            $item->COLUMN_COMMENT .= "(主键)";
                        }
                        $type = $this->getType($item->DATA_TYPE);
                        if ($item->COLUMN_NAME === 'created_at') {
                            $hasCreatedAt = true;
                        }
                        if ($item->COLUMN_NAME === 'updated_at') {
                            $hasUpdatedAt = true;
                        }
                        $properties .= " * @property $type \${$item->COLUMN_NAME} {$item->COLUMN_COMMENT}\n";
                    }
                }
            } else if ($table) {
                $output?->writeln($this->msg('table_not_found_schema', ['{table}' => (string)$table]));
            }
        } catch (\Throwable $e) {
            $this->reportException($e, $output);
        }
        if (!$table && !$meta_table) {
            $output?->writeln($this->msg('table_not_found_empty'));
        }
        $properties = rtrim($properties) ?: ' *';
        $timestamps = $hasCreatedAt && $hasUpdatedAt ? 'true' : 'false';
        $model_content = <<<EOF
<?php

namespace $namespace;

use support\Model;

/**
$properties
 */
class $class extends Model
{
    /**
     * The connection name for the model.
     *
     * @var string|null
     */
    protected \$connection = '$connection';
    
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected \$table = $table_val;

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected \$primaryKey = '$pk';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public \$timestamps = $timestamps;
    
    
}

EOF;
        file_put_contents($file, $model_content);
    }


    /**
     * @param $class
     * @param $namespace
     * @param $file
     * @param string|null $connection
     * @param string|null $table
     * @param OutputInterface|null $output
     * @return void
     */
    protected function createTpModel($class, $namespace, $file, $connection = null, $table = null, ?OutputInterface $output = null)
    {
        $path = pathinfo($file, PATHINFO_DIRNAME);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $table_base = Util::classToName($class);
        $is_thinkorm_v2 = class_exists(\support\think\Db::class);
        $table_val = 'null';
        $meta_table = null;
        $pk = 'id';
        $properties = '';
        try {
            $config_name = $is_thinkorm_v2 ? 'think-orm' : 'thinkorm';
            $connection = $connection ?: (config("$config_name.default") ?: 'mysql');
            $connectionConfig = $this->getThinkOrmConnectionConfig($config_name, (string)$connection);
            $prefix = (string)($connectionConfig['prefix'] ?? '');
            $database = (string)($connectionConfig['database'] ?? '');
            $driver = (string)($connectionConfig['type'] ?? 'mysql');
            $inflector = InflectorFactory::create()->build();
            $table_plural = $inflector->pluralize($inflector->tableize($class));

            if ($is_thinkorm_v2) {
                $con = \support\think\Db::connect($connection);
            } else {
                $con = \think\facade\Db::connect($connection);
            }

            // Table resolve (ThinkORM Model `$table` will be treated as an exact table name and will NOT apply prefix automatically)
            if ($table) {
                $table = ltrim(trim((string)$table), '=');
                if ($prefix && !str_starts_with($table, $prefix)) {
                    $table = $prefix . $table;
                }
                $table_val = "'" . $table . "'";
                $meta_table = $table;
            } else {
                // 检查表是否存在（兼容MySQL和PostgreSQL）
                if ($driver === 'pgsql') {
                    $schema = (string)($connectionConfig['schema'] ?? 'public');
                    $exists_plural = $con->query("SELECT to_regclass('{$schema}.{$prefix}{$table_plural}') as table_exists");
                    $exists = $con->query("SELECT to_regclass('{$schema}.{$prefix}{$table_base}') as table_exists");

                    if (!empty($exists_plural[0]['table_exists'])) {
                        $meta_table = "{$prefix}{$table_plural}";
                        $table_val = "'" . $meta_table . "'";
                    } else if (!empty($exists[0]['table_exists'])) {
                        $meta_table = "{$prefix}{$table_base}";
                        $table_val = "'" . $meta_table . "'";
                    }
                } else {
                    if ($con->query("show tables like '{$prefix}{$table_plural}'")) {
                        $meta_table = "{$prefix}{$table_plural}";
                        $table_val = "'" . $meta_table . "'";
                    } else if ($con->query("show tables like '{$prefix}{$table_base}'")) {
                        $meta_table = "{$prefix}{$table_base}";
                        $table_val = "'" . $meta_table . "'";
                    }
                }
            }

            // 获取表注释和列信息（兼容MySQL和PostgreSQL）
            if ($meta_table) {
                if ($driver === 'pgsql') {
                    $schema = (string)($connectionConfig['schema'] ?? 'public');
                    $tableComment = $con->query("SELECT obj_description('{$schema}.{$meta_table}'::regclass) as table_comment");
                    if (!empty($tableComment) && !empty($tableComment[0]['table_comment'])) {
                        $comments = $tableComment[0]['table_comment'];
                        $properties .= " * {$meta_table} {$comments}" . PHP_EOL;
                    }

                    $columns = $con->query("
                        SELECT 
                            a.attname as column_name,
                            format_type(a.atttypid, a.atttypmod) as data_type,
                            CASE WHEN con.contype = 'p' THEN 'PRI' ELSE '' END as column_key,
                            d.description as column_comment
                        FROM pg_catalog.pg_attribute a
                        LEFT JOIN pg_catalog.pg_description d ON d.objoid = a.attrelid AND d.objsubid = a.attnum
                        LEFT JOIN pg_catalog.pg_constraint con ON con.conrelid = a.attrelid AND a.attnum = ANY(con.conkey) AND con.contype = 'p'
                        WHERE a.attrelid = '{$schema}.{$meta_table}'::regclass
                        AND a.attnum > 0 AND NOT a.attisdropped
                        ORDER BY a.attnum
                    ");

                    foreach ($columns as $item) {
                        if ($item['column_key'] === 'PRI') {
                            $pk = $item['column_name'];
                            $item['column_comment'] = ($item['column_comment'] ? $item['column_comment'] . ' ' : '') . "(主键)";
                        }
                        $type = $this->getType($item['data_type']);
                        $properties .= " * @property $type \${$item['column_name']} " . ($item['column_comment'] ?? '') . "\n";
                    }
                } else {
                    $tableComment = $con->query('SELECT table_comment FROM information_schema.`TABLES` WHERE table_schema = ? AND table_name = ?', [$database, $meta_table]);
                    if (!empty($tableComment)) {
                        $comments = $tableComment[0]['table_comment'] ?? $tableComment[0]['TABLE_COMMENT'];
                        $properties .= " * {$meta_table} {$comments}" . PHP_EOL;
                    }

                    foreach ($con->query("select COLUMN_NAME,DATA_TYPE,COLUMN_KEY,COLUMN_COMMENT from INFORMATION_SCHEMA.COLUMNS where table_name = '$meta_table' and table_schema = '$database' ORDER BY ordinal_position") as $item) {
                        if ($item['COLUMN_KEY'] === 'PRI') {
                            $pk = $item['COLUMN_NAME'];
                            $item['COLUMN_COMMENT'] .= "(主键)";
                        }
                        $type = $this->getType($item['DATA_TYPE']);
                        $properties .= " * @property $type \${$item['COLUMN_NAME']} {$item['COLUMN_COMMENT']}\n";
                    }
                }
            } else if ($table) {
                $output?->writeln($this->msg('table_not_found_schema', ['{table}' => (string)$table]));
            }
        } catch (\Throwable $e) {
            $this->reportException($e, $output);
        }
        if (!$table && !$meta_table) {
            $output?->writeln($this->msg('table_not_found_empty'));
        }
        $properties = rtrim($properties) ?: ' *';
        $modelNamespace = $is_thinkorm_v2 ? 'support\think\Model' : 'think\Model';
        $model_content = <<<EOF
<?php

namespace $namespace;

use $modelNamespace;

/**
$properties
 */
class $class extends Model
{
    /**
     * The connection name for the model.
     *
     * @var string|null
     */
    protected \$connection = '$connection';
    
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected \$table = $table_val;

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected \$pk = '$pk';

    
}

EOF;
        file_put_contents($file, $model_content);
    }

    /**
     * @param \Throwable $e
     * @param OutputInterface|null $output
     * @return void
     */
    protected function reportException(\Throwable $e, ?OutputInterface $output = null): void
    {
        if ($output) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return;
        }
        echo $e->getMessage() . PHP_EOL;
    }

    /**
     * Resolve model namespace/file path by --plugin/-p or --path/-P.
     * - --plugin/-p: generate under plugin/<plugin>/app/model by default.
     * - --path/-P: generate under the specified directory (relative to base path).
     * - If both are provided, they must point to the same directory, otherwise it's an error.
     *
     * @param string $name
     * @param string|null $plugin
     * @param string|null $path
     * @param OutputInterface $output
     * @return array{0:string,1:string,2:string}|null [class, namespace, file]
     */
    protected function resolveModelTargetByPluginOrPath(string $name, ?string $plugin, ?string $path, OutputInterface $output): ?array
    {
        $pathNorm = $path ? $this->normalizeRelativePath($path) : null;
        if ($pathNorm !== null && $this->isAbsolutePath($pathNorm)) {
            $output->writeln($this->msg('invalid_path', ['{path}' => $path]));
            return null;
        }

        $expected = null;
        if ($plugin) {
            $expected = $this->getPluginModelRelativePath($plugin);
        }

        if ($expected && $pathNorm) {
            if (!$this->pathsEqual($expected, $pathNorm)) {
                $output->writeln($this->msg('plugin_path_conflict', [
                    '{expected}' => $expected,
                    '{actual}' => $pathNorm,
                ]));
                return null;
            }
        }

        $targetRel = $pathNorm ?: $expected;
        if (!$targetRel) {
            return null;
        }

        $targetDir = base_path($targetRel);
        $namespaceRoot = trim(str_replace('/', '\\', $targetRel), '\\');

        if (!($pos = strrpos($name, '/'))) {
            $class = ucfirst($name);
            $subPath = '';
        } else {
            $subPath = substr($name, 0, $pos);
            $class = ucfirst(substr($name, $pos + 1));
        }

        $subDir = $subPath ? str_replace('/', DIRECTORY_SEPARATOR, $subPath) . DIRECTORY_SEPARATOR : '';
        $file = $targetDir . DIRECTORY_SEPARATOR . $subDir . $class . '.php';
        $namespace = $namespaceRoot . ($subPath ? '\\' . str_replace('/', '\\', $subPath) : '');

        return [$class, $namespace, $file];
    }

    /**
     * @param string $plugin
     * @return string relative path
     */
    protected function getPluginModelRelativePath(string $plugin): string
    {
        $plugin = trim($plugin);
        $appDir = base_path('plugin' . DIRECTORY_SEPARATOR . $plugin . DIRECTORY_SEPARATOR . 'app');
        $modelDir = Util::guessPath($appDir, 'model') ?: 'model';
        return $this->normalizeRelativePath("plugin/{$plugin}/app/{$modelDir}");
    }

    /**
     * When no `--table/-t` is provided and convention-based guessing fails,
     * provide a good interactive UX if supported; otherwise fall back to empty model.
     *
     * - Non-interactive: return null, caller will generate empty model and exit SUCCESS.
     * - Interactive and DB reachable and has tables: show up to 20 candidates and allow
     *   selecting by number or typing a table name. Press Enter to skip.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string $ormType
     * @param string|null $connection
     * @param string $class
     * @return string|null
     */
    protected function promptForTableIfNeeded(InputInterface $input, OutputInterface $output, string $ormType, ?string $connection, string $class): ?string
    {
        if (!$this->isTerminalInteractive($input)) {
            return null;
        }

        // If table can be guessed by convention, don't interrupt.
        try {
            if ($this->canGuessTableByConvention($ormType, $connection, $class)) {
                return null;
            }
        } catch (\Throwable $e) {
            // If we cannot even guess due to DB errors, do not block; fall back to empty model.
            $output->writeln($this->msg('db_unavailable'));
            return null;
        }

        try {
            $tables = $this->listTables($ormType, $connection);
        } catch (\Throwable $e) {
            $output->writeln($this->msg('table_list_failed'));
            return null;
        }

        if (!$tables) {
            return null;
        }

        $prefix = $this->getConnectionPrefix($ormType, $connection);
        $orderedAll = $this->rankCandidateTables($tables, $prefix, $class);

        $batchSize = 20;
        $filterKeyword = null;
        $ordered = $orderedAll;
        $offset = 0;
        $indexToTable = [];

        $output->writeln($this->msg('no_match'));
        $output->writeln($this->msg('prompt_help'));

        $helper = $this->getHelper('question');
        $question = new Question('> ');

        while (true) {
            $slice = array_slice($ordered, $offset, $batchSize);
            if ($slice) {
                foreach ($slice as $t) {
                    $indexToTable[] = $t;
                }
                $this->printTableList($output, $indexToTable, $offset, $slice);
                $offset += count($slice);
            } else {
                $output->writeln($this->msg('no_more'));
            }

            $answerRaw = $helper->ask($input, $output, $question);
            $answerRaw = is_string($answerRaw) ? $answerRaw : '';
            $answer = trim($answerRaw);

            // Enter => more
            if ($answer === '') {
                if ($offset >= count($ordered)) {
                    // Nothing more; keep waiting for user input.
                    $output->writeln($this->msg('end_of_list'));
                }
                continue;
            }

            // 0 => empty model
            if ($answer === '0') {
                return null;
            }

            // /keyword filter
            if (str_starts_with($answer, '/')) {
                $kw = trim(substr($answer, 1));
                if ($kw === '') {
                    // Clear filter
                    $filterKeyword = null;
                    $ordered = $orderedAll;
                    $offset = 0;
                    $indexToTable = [];
                    $output->writeln($this->msg('filter_cleared'));
                    continue;
                }
                $filterKeyword = $kw;
                $ordered = $this->filterTables($orderedAll, $filterKeyword);
                $offset = 0;
                $indexToTable = [];
                if (!$ordered) {
                    $output->writeln($this->msg('filter_no_match', ['{keyword}' => $filterKeyword]));
                    // Keep filter active but list is empty.
                } else {
                    $output->writeln($this->msg('filter_applied', ['{keyword}' => $filterKeyword]));
                }
                continue;
            }

            // numeric selection (from already shown list)
            if (ctype_digit($answer)) {
                $n = (int)$answer;
                if ($n >= 1 && $n <= count($indexToTable)) {
                    return $indexToTable[$n - 1];
                }
                $output->writeln($this->msg('selection_out_of_range'));
                continue;
            }

            // manual input table name, validate if possible
            if (!in_array($answer, $tables, true)) {
                $output->writeln($this->msg('table_not_in_list', ['{table}' => $answer]));
            }
            return $answer;
        }
    }

    /**
     * @param InputInterface $input
     * @return bool
     */
    protected function isTerminalInteractive(InputInterface $input): bool
    {
        if (!$input->isInteractive()) {
            return false;
        }
        // When stdin is not a TTY (e.g. piped), do not prompt to avoid blocking.
        if (\defined('STDIN') && \function_exists('stream_isatty')) {
            try {
                return stream_isatty(STDIN);
            } catch (\Throwable) {
                // ignore
            }
        }
        return true;
    }

    /**
     * Hardcoded CLI messages without translation module.
     *
     * @param string $key
     * @param array $replace
     * @return string
     */
    protected function msg(string $key, array $replace = []): string
    {
        $zh = [
            'make_model' => "<info>创建模型</info> <comment>{name}</comment>",
            'created' => '<info>已创建：</info> {path}',
            'deprecated_type' => '<comment>[Deprecated]</comment> `type` 位置参数已过时，请使用 `--orm`/`-o`，例如：php webman make:model User -o=thinkorm',
            'override_prompt' => "<fg=blue>文件已存在：{path}\n是否覆盖？[Y/n]（回车=Y）</>\n",
            'invalid_plugin' => '<error>插件名无效：{plugin}。`--plugin/-p` 只能是 plugin/ 目录下的目录名，不能包含 / 或 \\。</error>',
            'plugin_path_conflict' => "<error>`--plugin/-p` 与 `--path/-P` 同时指定且不一致。\n期望路径：{expected}\n实际路径：{actual}\n请二选一或保持一致。</error>",
            'invalid_path' => '<error>路径无效：{path}。`--path/-P` 必须是相对路径（相对于项目根目录），不能是绝对路径。</error>',
            'connection_not_found' => '<error>数据库连接不存在：{connection}</error>',
            'connection_not_found_plugin' => '<error>插件 {plugin} 未配置数据库连接：{connection}</error>',
            'connection_plugin_mismatch' => '<error>数据库连接与插件不匹配：当前插件={plugin}，连接={connection}</error>',
            'plugin_default_connection_invalid' => '<error>插件 {plugin} 的默认数据库连接无效：{connection}</error>',
            'db_unavailable' => '<comment>[Warning]</comment> 数据库不可用或无权限读取表信息，将生成空模型（可使用 -t/--table 指定）。',
            'table_list_failed' => '<comment>[Warning]</comment> 无法获取数据表列表，将生成空模型（可使用 -t/--table 指定）。',
            'no_match' => '<comment>[Info]</comment> 未找到与模型名匹配的表（按约定推断失败）。',
            'prompt_help' => '<comment>[Info]</comment> 输入序号选择；输入表名；回车=更多；输入 0=空模型；输入 /关键字 过滤（输入 / 清除过滤）。',
            'no_more' => '<comment>[Info]</comment> 没有更多表可显示。',
            'end_of_list' => '<comment>[Info]</comment> 已到列表末尾。可输入表名、序号、0（空模型）或 /关键字。',
            'filter_cleared' => '<comment>[Info]</comment> 已清除过滤条件。',
            'filter_applied' => '<comment>[Info]</comment> 已应用过滤：`{keyword}`。',
            'filter_no_match' => '<comment>[Warning]</comment> 没有表匹配过滤 `{keyword}`。输入 / 清除过滤或换个关键字。',
            'selection_out_of_range' => '<comment>[Warning]</comment> 序号超出范围。可回车查看更多或输入有效序号。',
            'table_not_in_list' => '<comment>[Warning]</comment> 表 `{table}` 不在当前数据库列表中，将继续尝试生成（注释可能为空）。',
            'table_not_found_schema' => "<comment>[Warning]</comment> 表 `{table}` 未找到，将生成无注释的模型。",
            'table_not_found_empty' => '<comment>[Warning]</comment> 未找到数据表，将生成空模型（可使用 -t/--table 指定，交互终端下也可选择）。',
            'showing_range' => '<comment>[Info]</comment> 当前已显示 {start}-{end}（累计 {shown}）。',
        ];

        $en = [
            'make_model' => "<info>Make model</info> <comment>{name}</comment>",
            'created' => '<info>Created:</info> {path}',
            'deprecated_type' => '<comment>[Deprecated]</comment> The positional `type` argument is deprecated. Please use `--orm`/`-o` instead. Example: php webman make:model User -o=thinkorm',
            'override_prompt' => "<fg=blue>File already exists: {path}\nOverride? [Y/n] (Enter = Y)</>\n",
            'invalid_plugin' => '<error>Invalid plugin name: {plugin}. `--plugin/-p` must be a directory name under plugin/ and must not contain / or \\.</error>',
            'plugin_path_conflict' => "<error>`--plugin/-p` and `--path/-P` are both provided but inconsistent.\nExpected: {expected}\nActual: {actual}\nPlease provide only one, or make them identical.</error>",
            'invalid_path' => '<error>Invalid path: {path}. `--path/-P` must be a relative path (to project root) and must not be an absolute path.</error>',
            'connection_not_found' => '<error>Database connection not found: {connection}</error>',
            'connection_not_found_plugin' => '<error>Plugin {plugin} has no database connection configured: {connection}</error>',
            'connection_plugin_mismatch' => '<error>Database connection does not match plugin: plugin={plugin}, connection={connection}</error>',
            'plugin_default_connection_invalid' => '<error>Invalid default database connection for plugin {plugin}: {connection}</error>',
            'db_unavailable' => '<comment>[Warning]</comment> Database is not accessible or permission denied. Generating an empty model (use -t/--table to specify).',
            'table_list_failed' => '<comment>[Warning]</comment> Unable to fetch table list. Generating an empty model (use -t/--table to specify).',
            'no_match' => '<comment>[Info]</comment> No table matched the model name by convention.',
            'prompt_help' => '<comment>[Info]</comment> Enter a number to select, type a table name, press Enter for more, enter 0 for an empty model, or use /keyword to filter (use / to clear).',
            'no_more' => '<comment>[Info]</comment> No more tables to show.',
            'end_of_list' => '<comment>[Info]</comment> End of list. Type a table name, a number, 0 for empty, or /keyword.',
            'filter_cleared' => '<comment>[Info]</comment> Filter cleared.',
            'filter_applied' => '<comment>[Info]</comment> Filter applied: `{keyword}`.',
            'filter_no_match' => '<comment>[Warning]</comment> No tables matched filter `{keyword}`. Use / to clear or try another keyword.',
            'selection_out_of_range' => '<comment>[Warning]</comment> Selection out of range. Press Enter for more, or choose a valid number.',
            'table_not_in_list' => '<comment>[Warning]</comment> Table `{table}` is not in the current database list. Will try to generate anyway (schema annotations may be empty).',
            'table_not_found_schema' => "<comment>[Warning]</comment> Table `{table}` not found, generating model without schema annotations.",
            'table_not_found_empty' => '<comment>[Warning]</comment> Table not found. Generating an empty model (use -t/--table to specify, or choose interactively in a supported terminal).',
            'showing_range' => '<comment>[Info]</comment> Showing {start}-{end} (total shown: {shown}).',
        ];

        $map = $this->isZhLocale() ? $zh : $en;
        $text = $map[$key] ?? $key;
        return $replace ? strtr($text, $replace) : $text;
    }

    /**
     * Command help text (bilingual, no translation module required).
     *
     * @return string
     */
    protected function buildHelpText(): string
    {
        if ($this->isZhLocale()) {
            return <<<'EOF'
生成模型文件，并在表存在时自动读取表结构生成 @property 注释。

推荐用法：
  php webman make:model User
  php webman make:model User -p admin
  php webman make:model User -P plugin/admin/app/model
  php webman make:model User -t wa_users -o laravel
  php webman make:model User -t wa_users -o thinkorm
  php webman make:model User -f

交互式选表（仅支持交互终端且推断失败时出现）：
  - 回车：显示更多表
  - 输入数字：选择已展示表
  - 输入表名：直接使用
  - 输入 0：生成空模型
  - 输入 /关键字：过滤（输入 / 清除过滤）
EOF;
        }

        return <<<'EOF'
Generate a model file and (when the table exists) generate @property annotations from the table schema.

Recommended:
  php webman make:model User
  php webman make:model User -p admin
  php webman make:model User -P plugin/admin/app/model
  php webman make:model User -t wa_users -o laravel
  php webman make:model User -t wa_users -o thinkorm
  php webman make:model User -f

Interactive table picker (only in interactive terminals when guessing fails):
  - Enter: show more tables
  - Number: select from shown list
  - Table name: use directly
  - 0: generate an empty model
  - /keyword: filter (use / to clear)
EOF;
    }

    /**
     * Print the newly appended slice with global indexes.
     *
     * @param OutputInterface $output
     * @param array $allShown
     * @param int $offsetBefore
     * @param array $newSlice
     * @return void
     */
    protected function printTableList(OutputInterface $output, array $allShown, int $offsetBefore, array $newSlice): void
    {
        $startIndex = $offsetBefore + 1;
        $endIndex = $offsetBefore + count($newSlice);
        $output->writeln($this->msg('showing_range', [
            '{start}' => (string)$startIndex,
            '{end}' => (string)$endIndex,
            '{shown}' => (string)count($allShown),
        ]));
        foreach ($newSlice as $i => $t) {
            $num = $offsetBefore + $i + 1;
            $numStr = str_pad((string)$num, 3, ' ', STR_PAD_LEFT);
            $output->writeln("  <info>{$numStr}</info>. {$t}");
        }
    }

    /**
     * @param array $tables
     * @param string $keyword
     * @return array
     */
    protected function filterTables(array $tables, string $keyword): array
    {
        $keyword = strtolower($keyword);
        return array_values(array_filter($tables, static function ($t) use ($keyword) {
            return str_contains(strtolower((string)$t), $keyword);
        }));
    }

    /**
     * @param string $ormType
     * @param string|null $connection
     * @param string $class
     * @return bool
     */
    protected function canGuessTableByConvention(string $ormType, ?string $connection, string $class): bool
    {
        $inflector = InflectorFactory::create()->build();
        $tableBase = Util::classToName($class);
        $tablePlural = $inflector->pluralize($inflector->tableize($class));

        if ($ormType === self::ORM_THINKORM) {
            return $this->thinkOrmTableExists($connection, $tablePlural) || $this->thinkOrmTableExists($connection, $tableBase);
        }
        return $this->laravelTableExists($connection, $tablePlural) || $this->laravelTableExists($connection, $tableBase);
    }

    /**
     * List all tables for the connection.
     *
     * @param string $ormType
     * @param string|null $connection
     * @return array
     */
    protected function listTables(string $ormType, ?string $connection): array
    {
        return $ormType === self::ORM_THINKORM
            ? $this->listThinkOrmTables($connection)
            : $this->listLaravelTables($connection);
    }

    /**
     * @param string|null $connection
     * @return array
     */
    protected function listLaravelTables(?string $connection): array
    {
        $connection = $connection ?: config('database.default');
        $conn = $this->getLaravelConnectionConfig((string)$connection);
        $driver = (string)($conn['driver'] ?? 'mysql');
        $con = Db::connection($connection);

        if ($driver === 'pgsql') {
            $schema = (string)($conn['schema'] ?? 'public');
            $rows = $con->select('SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = ?', [$schema]);
            return array_values(array_filter(array_map(static function ($row) {
                $arr = (array)$row;
                return $arr['tablename'] ?? (array_values($arr)[0] ?? null);
            }, $rows)));
        }

        // default mysql
        $rows = $con->select('SHOW TABLES');
        return array_values(array_filter(array_map(static function ($row) {
            $arr = (array)$row;
            return array_values($arr)[0] ?? null;
        }, $rows)));
    }

    /**
     * @param string|null $connection
     * @return array
     */
    protected function listThinkOrmTables(?string $connection): array
    {
        $is_thinkorm_v2 = class_exists(\support\think\Db::class);
        $config_name = $is_thinkorm_v2 ? 'think-orm' : 'thinkorm';
        $connection = $connection ?: (config("$config_name.default") ?: 'mysql');
        $conn = $this->getThinkOrmConnectionConfig($config_name, (string)$connection);
        $driver = (string)($conn['type'] ?? 'mysql');

        if ($is_thinkorm_v2) {
            $con = \support\think\Db::connect($connection);
        } else {
            $con = \think\facade\Db::connect($connection);
        }

        if ($driver === 'pgsql') {
            $schema = (string)($conn['schema'] ?? 'public');
            $rows = $con->query('SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = ?', [$schema]);
            return array_values(array_filter(array_map(static function ($row) {
                return $row['tablename'] ?? (array_values($row)[0] ?? null);
            }, $rows)));
        }

        $rows = $con->query('SHOW TABLES');
        return array_values(array_filter(array_map(static function ($row) {
            return array_values($row)[0] ?? null;
        }, $rows)));
    }

    /**
     * Rank candidate tables based on model class name.
     *
     * @param array $tables
     * @param string $prefix
     * @param string $class
     * @return array
     */
    protected function rankCandidateTables(array $tables, string $prefix, string $class): array
    {
        $inflector = InflectorFactory::create()->build();
        $tableBase = Util::classToName($class);
        $tablePlural = $inflector->pluralize($inflector->tableize($class));

        $scores = [];
        foreach ($tables as $t) {
            $raw = (string)$t;
            $cmp = $this->stripPrefix($raw, $prefix);
            $score = 0;
            if ($cmp === $tableBase) {
                $score += 100;
            }
            if ($cmp === $tablePlural) {
                $score += 95;
            }
            if (str_contains($cmp, $tableBase)) {
                $score += 40;
            }
            if (str_contains($cmp, $tablePlural)) {
                $score += 35;
            }
            // small heuristic: closer string distance gets higher score
            $score += max(0, 20 - levenshtein($cmp, $tableBase));
            $scores[$raw] = $score;
        }

        arsort($scores);
        return array_keys($scores);
    }

    /**
     * @param string $value
     * @param string $prefix
     * @return string
     */
    protected function stripPrefix(string $value, string $prefix): string
    {
        if ($prefix && str_starts_with($value, $prefix)) {
            return substr($value, strlen($prefix));
        }
        return $value;
    }

    /**
     * @param string $ormType
     * @param string|null $connection
     * @return string
     */
    protected function getConnectionPrefix(string $ormType, ?string $connection): string
    {
        if ($ormType === self::ORM_THINKORM) {
            $is_thinkorm_v2 = class_exists(\support\think\Db::class);
            $config_name = $is_thinkorm_v2 ? 'think-orm' : 'thinkorm';
            $connection = $connection ?: (config("$config_name.default") ?: 'mysql');
            $conn = $this->getThinkOrmConnectionConfig($config_name, (string)$connection);
            return (string)($conn['prefix'] ?? '');
        }
        $connection = $connection ?: config('database.default');
        $conn = $this->getLaravelConnectionConfig((string)$connection);
        return (string)($conn['prefix'] ?? '');
    }

    /**
     * @param string|null $connection
     * @param string $tableNoPrefix
     * @return bool
     */
    protected function laravelTableExists(?string $connection, string $tableNoPrefix): bool
    {
        $connection = $connection ?: config('database.default');
        $conn = $this->getLaravelConnectionConfig((string)$connection);
        $driver = (string)($conn['driver'] ?? 'mysql');
        $prefix = (string)($conn['prefix'] ?? '');
        $con = Db::connection($connection);

        if ($driver === 'pgsql') {
            $schema = (string)($conn['schema'] ?? 'public');
            $rows = $con->select("SELECT to_regclass('{$schema}.{$prefix}{$tableNoPrefix}') as table_exists");
            return !empty($rows[0]->table_exists);
        }
        return (bool)$con->select("SHOW TABLES LIKE '{$prefix}{$tableNoPrefix}'");
    }

    /**
     * @param string|null $connection
     * @param string $tableNoPrefix
     * @return bool
     */
    protected function thinkOrmTableExists(?string $connection, string $tableNoPrefix): bool
    {
        $is_thinkorm_v2 = class_exists(\support\think\Db::class);
        $config_name = $is_thinkorm_v2 ? 'think-orm' : 'thinkorm';
        $connection = $connection ?: (config("$config_name.default") ?: 'mysql');
        $conn = $this->getThinkOrmConnectionConfig($config_name, (string)$connection);
        $driver = (string)($conn['type'] ?? 'mysql');
        $prefix = (string)($conn['prefix'] ?? '');

        if ($is_thinkorm_v2) {
            $con = \support\think\Db::connect($connection);
        } else {
            $con = \think\facade\Db::connect($connection);
        }

        if ($driver === 'pgsql') {
            $schema = (string)($conn['schema'] ?? 'public');
            $rows = $con->query("SELECT to_regclass('{$schema}.{$prefix}{$tableNoPrefix}') as table_exists");
            return !empty($rows[0]['table_exists']);
        }
        return (bool)$con->query("SHOW TABLES LIKE '{$prefix}{$tableNoPrefix}'");
    }

    /**
     * Resolve the database connection name with plugin priority.
     *
     * Rules:
     * - If user explicitly provides --connection/-c, always respect it.
     * - If --plugin/-p is provided and that plugin has DB config, prefer the plugin default connection.
     * - If plugin has no DB config, fall back to main project config.
     *
     * Note:
     * Plugin connections are merged into global config with names like "plugin.<plugin>.<connection>".
     *
     * @param string $ormType
     * @param string|null $plugin
     * @param string|null $connectionOption
     * @return string|null
     */
    protected function resolveConnectionByPlugin(string $ormType, ?string $plugin, ?string $connectionOption): ?string
    {
        $connectionOption = $this->normalizeOptionValue($connectionOption);
        if ($connectionOption) {
            return $connectionOption;
        }
        $plugin = $this->normalizeOptionValue($plugin);
        if (!$plugin) {
            return null;
        }

        if ($ormType === self::ORM_THINKORM) {
            $is_thinkorm_v2 = class_exists(\support\think\Db::class);
            $configName = $is_thinkorm_v2 ? 'think-orm' : 'thinkorm';
            $pluginDefault = $this->normalizeOptionValue(config("plugin.$plugin.$configName.default"));
            if ($pluginDefault) {
                return str_starts_with($pluginDefault, 'plugin.') ? $pluginDefault : "plugin.$plugin.$pluginDefault";
            }
            $pluginConnections = config("plugin.$plugin.$configName.connections", []);
            if (is_array($pluginConnections) && $pluginConnections) {
                $first = array_key_first($pluginConnections);
                return $first ? "plugin.$plugin.$first" : null;
            }
            return null;
        }

        // Laravel ORM (webman/database)
        $pluginDefault = $this->normalizeOptionValue(config("plugin.$plugin.database.default"));
        if ($pluginDefault) {
            return str_starts_with($pluginDefault, 'plugin.') ? $pluginDefault : "plugin.$plugin.$pluginDefault";
        }
        $pluginConnections = config("plugin.$plugin.database.connections", []);
        if (is_array($pluginConnections) && $pluginConnections) {
            $first = array_key_first($pluginConnections);
            return $first ? "plugin.$plugin.$first" : null;
        }
        return null;
    }

    /**
     * Resolve and validate the final connection name for model generation.
     *
     * @param string $ormType
     * @param string|null $plugin
     * @param string|null $connectionOption
     * @param OutputInterface $output
     * @return array{0:bool,1:?string} [ok, connectionName]
     */
    protected function resolveAndValidateConnection(string $ormType, ?string $plugin, ?string $connectionOption, OutputInterface $output): array
    {
        $plugin = $this->normalizeOptionValue($plugin);
        $connectionOption = $this->normalizeOptionValue($connectionOption);

        // Determine which config set to validate against.
        $isThink = $ormType === self::ORM_THINKORM;
        $thinkConfigName = null;
        if ($isThink) {
            $is_thinkorm_v2 = class_exists(\support\think\Db::class);
            $thinkConfigName = $is_thinkorm_v2 ? 'think-orm' : 'thinkorm';
        }

        // Case A: plugin + explicit connection => MUST use plugin connection config.
        if ($plugin && $connectionOption) {
            if (str_starts_with($connectionOption, 'plugin.')) {
                if (!str_starts_with($connectionOption, "plugin.$plugin.")) {
                    $output->writeln($this->msg('connection_plugin_mismatch', [
                        '{plugin}' => $plugin,
                        '{connection}' => $connectionOption,
                    ]));
                    return [false, null];
                }
                $final = $connectionOption;
            } else {
                $final = "plugin.$plugin.$connectionOption";
            }

            $exists = $isThink
                ? (bool)$this->getThinkOrmConnectionConfig((string)$thinkConfigName, $final)
                : (bool)$this->getLaravelConnectionConfig($final);

            if (!$exists) {
                $output->writeln($this->msg('connection_not_found_plugin', [
                    '{plugin}' => $plugin,
                    '{connection}' => $final,
                ]));
                return [false, null];
            }
            return [true, $final];
        }

        // Case B: no plugin + explicit connection => validate it exists.
        if (!$plugin && $connectionOption) {
            $final = $connectionOption;
            $exists = $isThink
                ? (bool)$this->getThinkOrmConnectionConfig((string)$thinkConfigName, $final)
                : (bool)$this->getLaravelConnectionConfig($final);
            if (!$exists) {
                $output->writeln($this->msg('connection_not_found', [
                    '{connection}' => $final,
                ]));
                return [false, null];
            }
            return [true, $final];
        }

        // Case C: no explicit connection => prefer plugin default (if plugin has DB config), else null.
        $final = $this->resolveConnectionByPlugin($ormType, $plugin, null);
        if ($plugin && $final) {
            $exists = $isThink
                ? (bool)$this->getThinkOrmConnectionConfig((string)$thinkConfigName, $final)
                : (bool)$this->getLaravelConnectionConfig($final);
            if (!$exists) {
                $output->writeln($this->msg('plugin_default_connection_invalid', [
                    '{plugin}' => $plugin,
                    '{connection}' => $final,
                ]));
                return [false, null];
            }
        }
        return [true, $final];
    }

    /**
     * Get laravel database connection config by name (dot-safe).
     *
     * @param string $connectionName
     * @return array
     */
    protected function getLaravelConnectionConfig(string $connectionName): array
    {
        $all = config('database.connections', []);
        if (!is_array($all)) {
            return [];
        }
        $cfg = $all[$connectionName] ?? null;
        return is_array($cfg) ? $cfg : [];
    }

    /**
     * Get thinkorm connection config by name (dot-safe).
     *
     * @param string $configName "think-orm" or "thinkorm"
     * @param string $connectionName
     * @return array
     */
    protected function getThinkOrmConnectionConfig(string $configName, string $connectionName): array
    {
        $all = config("$configName.connections", []);
        if (!is_array($all)) {
            return [];
        }
        $cfg = $all[$connectionName] ?? null;
        return is_array($cfg) ? $cfg : [];
    }

    /**
     * @param string $type
     * @return string
     */
    protected function getType(string $type)
    {
        if (strpos($type, 'int') !== false) {
            return 'integer';
        }

        if (strpos($type, 'character varying') !== false || strpos($type, 'varchar') !== false) {
            return 'string';
        }

        if (strpos($type, 'timestamp') !== false) {
            return 'string';
        }

        switch ($type) {
            case 'varchar':
            case 'string':
            case 'text':
            case 'date':
            case 'time':
            case 'guid':
            case 'datetimetz':
            case 'datetime':
            case 'decimal':
            case 'enum':
            case 'character':   // PostgreSQL类型
            case 'char':        // PostgreSQL类型
            case 'json':        // PostgreSQL类型
            case 'jsonb':       // PostgreSQL类型
            case 'uuid':        // PostgreSQL类型
            case 'timestamptz': // PostgreSQL类型
            case 'citext':      // PostgreSQL类型
                return 'string';
            case 'boolean':
            case 'bool':        // PostgreSQL类型
                return 'bool';
            case 'float':
            case 'float4':      // PostgreSQL类型 (real)
            case 'float8':      // PostgreSQL类型 (double precision)
                return 'float';
            case 'numeric':     // PostgreSQL类型
                return 'string';
            default:
                return 'mixed';
        }
    }

}
