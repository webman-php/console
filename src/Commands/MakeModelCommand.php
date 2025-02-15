<?php

namespace Webman\Console\Commands;

use Doctrine\Inflector\InflectorFactory;
use support\Db;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Webman\Console\Util;


class MakeModelCommand extends Command
{
    protected static $defaultName = 'make:model';
    protected static $defaultDescription = 'Make model';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Model name');
        $this->addArgument('type', InputArgument::OPTIONAL, 'Type');
        $this->addOption('connection', 'c', InputOption::VALUE_OPTIONAL, 'Select database connection. ');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $name = Util::nameToClass($name);
        $suffix = config('app.model_suffix', '');
        if ($suffix && !strpos($name, $suffix)) {
            $name .= $suffix;
        }

        $type = $input->getArgument('type');
        $connection = $input->getOption('connection');
        $output->writeln("Make model $name");
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
            $path = "$name_str/" . ($upper ? 'Model' : 'model');
            $name = ucfirst(substr($name, $pos + 1));
            $file = app_path() . DIRECTORY_SEPARATOR . $path . DIRECTORY_SEPARATOR . "$name.php";
            $namespace = str_replace('/', '\\', ($upper ? 'App/' : 'app/') . $path);
        }
        if (!$type) {
            $database = config('database');
            if (isset($database['default']) && strpos($database['default'], 'plugin.') === 0) {
                $database = false;
            }
            $thinkorm = config('thinkorm');
            if (isset($thinkorm['default']) && strpos($thinkorm['default'], 'plugin.') === 0) {
                $thinkorm = false;
            }
            $type = !$database && $thinkorm ? 'tp' : 'laravel';
        }

        if (is_file($file)) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion("$file already exists. Do you want to override it? (yes/no)", false);
            if (!$helper->ask($input, $output, $question)) {
                return Command::SUCCESS;
            }
        }

        if ($type == 'tp') {
            $this->createTpModel($name, $namespace, $file, $connection);
        } else {
            $this->createModel($name, $namespace, $file, $connection);
        }

        return self::SUCCESS;
    }

    /**
     * @param $class
     * @param $namespace
     * @param $file
     * @param string|null $connection
     * @return void
     */
    protected function createModel($class, $namespace, $file, $connection = null)
    {
        $path = pathinfo($file, PATHINFO_DIRNAME);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $table = Util::classToName($class);
        $table = $this->getTable($table); // 移除模型后缀的表名
        $table_val = 'null';
        $pk = 'id';
        $properties = '';
        $connection = $connection ?: 'mysql';
        try {
            $prefix = config("database.connections.$connection.prefix") ?? '';
            $database = config("database.connections.$connection.database");
            $inflector = InflectorFactory::create()->build();
            $table_plura = $inflector->pluralize($inflector->tableize($table));
            $con = Db::connection($connection);
            if ($con->select("show tables like '{$prefix}{$table_plura}'")) {
                $table_val = "'$table_plura'";
                $table = "{$prefix}{$table_plura}";
            } else if ($con->select("show tables like '{$prefix}{$table}'")) {
                $table_val = "'$table'";
                $table = "{$prefix}{$table}";
            }
            $tableComment = $con->select('SELECT table_comment FROM information_schema.`TABLES` WHERE table_schema = ? AND table_name = ?', [$database, $table]);
            if (!empty($tableComment)) {
                $comments = $tableComment[0]->table_comment ?? $tableComment[0]->TABLE_COMMENT;
                $properties .= " * {$table} {$comments}" . PHP_EOL;
            }
            foreach ($con->select("select COLUMN_NAME,DATA_TYPE,COLUMN_KEY,COLUMN_COMMENT from INFORMATION_SCHEMA.COLUMNS where table_name = '$table' and table_schema = '$database' ORDER BY ordinal_position") as $item) {
                if ($item->COLUMN_KEY === 'PRI') {
                    $pk = $item->COLUMN_NAME;
                    $item->COLUMN_COMMENT .= "(主键)";
                }
                $type = $this->getType($item->DATA_TYPE);
                $properties .= " * @property $type \${$item->COLUMN_NAME} {$item->COLUMN_COMMENT}\n";
            }
        } catch (\Throwable $e) {
            echo $e->getMessage() . PHP_EOL;
        }
        $properties = rtrim($properties) ?: ' *';
        list($file, $class, $codeFile, $codeClass) = $this->getModelSeparateInfo($class, $file);
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
    public \$timestamps = false;
    
    
}

EOF;
        file_put_contents($file, $model_content);
        $this->createSeparateModel($codeClass, $codeFile, $namespace, $class);
    }


    /**
     * @param $class
     * @param $namespace
     * @param $file
     * @param string|null $connection
     * @return void
     */
    protected function createTpModel($class, $namespace, $file, $connection = null)
    {
        $path = pathinfo($file, PATHINFO_DIRNAME);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $table = Util::classToName($class);
        $table = $this->getTable($table); // 移除模型后缀的表名
        $table_val = 'null';
        $pk = 'id';
        $properties = '';
        $connection = $connection ?: 'mysql';
        try {
            $prefix = config("thinkorm.connections.$connection.prefix") ?? '';
            $database = config("thinkorm.connections.$connection.database");
            $con = \think\facade\Db::connect($connection);
            if ($con->query("show tables like '{$prefix}{$table}'")) {
                $table = "{$prefix}{$table}";
                $table_val = "'$table'";
            } else if ($con->query("show tables like '{$prefix}{$table}s'")) {
                $table = "{$prefix}{$table}s";
                $table_val = "'$table'";
            }
            $tableComment = $con->query('SELECT table_comment FROM information_schema.`TABLES` WHERE table_schema = ? AND table_name = ?', [$database, $table]);
            if (!empty($tableComment)) {
                $comments = $tableComment[0]['table_comment'] ?? $tableComment[0]['TABLE_COMMENT'];
                $properties .= " * {$table} {$comments}" . PHP_EOL;
            }
            foreach ($con->query("select COLUMN_NAME,DATA_TYPE,COLUMN_KEY,COLUMN_COMMENT from INFORMATION_SCHEMA.COLUMNS where table_name = '$table' and table_schema = '$database' ORDER BY ordinal_position") as $item) {
                if ($item['COLUMN_KEY'] === 'PRI') {
                    $pk = $item['COLUMN_NAME'];
                    $item['COLUMN_COMMENT'] .= "(主键)";
                }
                $type = $this->getType($item['DATA_TYPE']);
                $properties .= " * @property $type \${$item['COLUMN_NAME']} {$item['COLUMN_COMMENT']}\n";
            }
        } catch (\Throwable $e) {
            echo $e->getMessage() . PHP_EOL;
        }
        $properties = rtrim($properties) ?: ' *';
        list($file, $class, $codeFile, $codeClass) = $this->getModelSeparateInfo($class, $file);
        $model_content = <<<EOF
<?php

namespace $namespace;

use think\Model;

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
        $this->createSeparateModel($codeClass, $codeFile, $namespace, $class);
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
                return 'string';
            case 'boolean':
                return 'integer';
            case 'float':
                return 'float';
            default:
                return 'mixed';
        }
    }


    /**
     * 去掉模型后缀的表名
     * @param $table
     * @return string
     */
    protected function getTable($table)
    {
        $suffix = config('app.model_suffix', '');
        if ($suffix) {
            $table = substr($table, 0, strlen($table) - strlen($suffix) - 1);
        }
        return $table;
    }

    /**
     * 获取模型分离信息
     * @param $class
     * @param $file
     * @return array
     */
    protected function getModelSeparateInfo($class, $file)
    {
        $configSuffix = config('app.model_config_suffix', '');
        $suffix = config('app.model_suffix', '');
        if (empty($configSuffix)) {
            return [$file, $class, null, null];
        }

        $codeFile    = $file;   // 模型业务代码文件
        $codeClass   = $class;  // 模型业务代码类名
        $configClass = rtrim($class, $suffix) . $suffix . $configSuffix;         // 模型配置类名
        $configFile  = str_replace($class . '.php', $configClass . '.php', $file);  // 模型配置文件

        return [$configFile, $configClass, $codeFile, $codeClass];
    }


    /**
     * 生成模型分离文件
     * @param $codeClass
     * @param $codeFile
     * @param $namespace
     * @param $class
     */
    protected function createSeparateModel($codeClass, $codeFile, $namespace, $class)
    {
        // 生成业务逻辑文件, 如果文件已经存在，则不生成
        if ($codeClass && !is_file($codeFile)) {
            $codeModeContent = <<<EOF
<?php

namespace $namespace;

/**
* $codeClass 业务逻辑
*/
class $codeClass extends $class
{

    // todo something

}

EOF;
            file_put_contents($codeFile, $codeModeContent);
        }
    }
}
