<?php

namespace Webman\Console\Commands\Concerns;

use Doctrine\Inflector\InflectorFactory;
use support\Db;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Webman\Console\Util;

trait OrmTableCommandHelpers
{
    protected const ORM_LARAVEL = 'laravel';
    protected const ORM_THINKORM = 'tp';

    /**
     * Resolve orm type.
     *
     * @param string|null $ormOption
     * @return string
     */
    protected function resolveOrm(?string $ormOption): string
    {
        $ormOption = $ormOption !== null ? strtolower(trim((string)$ormOption)) : null;
        if ($ormOption) {
            $ormOption = ltrim($ormOption, '=');
            if ($ormOption === 'thinkorm' || $ormOption === 'tp') {
                return self::ORM_THINKORM;
            }
            return self::ORM_LARAVEL;
        }

        // Auto detect
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
     * Resolve the database connection name with plugin priority.
     *
     * Rules:
     * - If user explicitly provides --database/-d, always respect it.
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
        $connections = config("$configName.connections", []);
        if (!is_array($connections)) {
            return [];
        }
        $cfg = $connections[$connectionName] ?? null;
        return is_array($cfg) ? $cfg : [];
    }

    /**
     * When no `--table/-t` is provided and convention-based guessing fails,
     * provide a good interactive UX if supported; otherwise fall back to empty model.
     *
     * - Non-interactive: return null, caller will handle the fallback.
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
        return $this->promptForTableInternal($input, $output, $ormType, $connection, $class, true);
    }

    /**
     * Always prompt to select a table (no convention shortcut).
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string $ormType
     * @param string|null $connection
     * @param string $class
     * @return string|null
     */
    protected function promptForTable(InputInterface $input, OutputInterface $output, string $ormType, ?string $connection, string $class = 'Model'): ?string
    {
        return $this->promptForTableInternal($input, $output, $ormType, $connection, $class, false);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string $ormType
     * @param string|null $connection
     * @param string $class
     * @param bool $skipIfConventionMatch
     * @return string|null
     */
    private function promptForTableInternal(
        InputInterface $input,
        OutputInterface $output,
        string $ormType,
        ?string $connection,
        string $class,
        bool $skipIfConventionMatch
    ): ?string {
        if (!$this->isTerminalInteractive($input)) {
            return null;
        }

        if ($skipIfConventionMatch) {
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
}
