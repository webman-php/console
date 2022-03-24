<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
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
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $class = $input->getArgument('name');
        $class = Util::nameToClass($class);
        $output->writeln("Make model $class");
        if (!($pos = strrpos($class, '/'))) {
            $file = "app/model/$class.php";
            $namespace = 'app\model';
        } else {
            $path = 'app/' . substr($class, 0, $pos) . '/model';
            $class = ucfirst(substr($class, $pos + 1));
            $file = "$path/$class.php";
            $namespace = str_replace('/', '\\', $path);
        }
        if (!config('database') && config('thinkorm')) {
            $this->createTpModel($class, $namespace, $file);
        } else {
            $this->createModel($class, $namespace, $file);
        }

        return self::SUCCESS;
    }

    /**
     * @param $class
     * @param $namespace
     * @param $file
     * @return void
     */
    protected function createModel($class, $namespace, $file)
    {
        $path = pathinfo($file, PATHINFO_DIRNAME);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $table = Util::classToName($class);
        $table_val = 'null';
        $pk = 'id';
        try {
            $prefix = config('database.connections.mysql.prefix') ?? '';
            if (\support\Db::select("show tables like '{$prefix}{$table}s'")) {
                $table = "{$prefix}{$table}s";
            } else if (\support\Db::select("show tables like '{$prefix}{$table}'")) {
                $table_val = "'$table'";
                $table = "{$prefix}{$table}";
            }
            foreach (\support\Db::select("desc `$table`") as $item) {
                if ($item->Key === 'PRI') {
                    $pk = $item->Field;
                    break;
                }
            }
        } catch (\Throwable $e) {}
        $model_content = <<<EOF
<?php

namespace $namespace;

use support\Model;

class $class extends Model
{
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
    }


    /**
     * @param $class
     * @param $namespace
     * @param $path
     * @return void
     */
    protected function createTpModel($class, $namespace, $file)
    {
        $path = pathinfo($file, PATHINFO_DIRNAME);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $table = Util::classToName($class);
        $table_val = 'null';
        $pk = 'id';
        try {
            $prefix = config('thinkorm.connections.mysql.prefix') ?? '';
            if (\think\facade\Db::query("show tables like '{$prefix}{$table}'")) {
                $table = "{$prefix}{$table}";
                $table_val = "'$table'";
            } else if (\think\facade\Db::query("show tables like '{$prefix}{$table}s'")) {
                $table = "{$prefix}{$table}s";
                $table_val = "'$table'";
            }
            foreach (\think\facade\Db::query("desc `$table`") as $item) {
                if ($item['Key'] === 'PRI') {
                    $pk = $item['Field'];
                    break;
                }
            }
        } catch (\Throwable $e) {}
        $model_content = <<<EOF
<?php

namespace $namespace;

use think\Model;

class $class extends Model
{
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

}
