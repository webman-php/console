<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;


class MakeBootstrapCommand extends Command
{
    protected static $defaultName = 'make:bootstrap';
    protected static $defaultDescription = 'Make bootstrap';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Bootstrap name');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('name');
        $output->writeln("Make bootstrap $name");
        if (!($pos = strrpos($name, '/'))) {
            $name = ucfirst($name);
            $file = "app/bootstrap/$name.php";
            $namespace = 'app\bootstrap';
        } else {
            $path = 'app/' . substr($name, 0, $pos) . '/bootstrap';
            $name = ucfirst(substr($name, $pos + 1));
            $file = "$path/$name.php";
            $namespace = str_replace('/', '\\', $path);
        }
        $this->createBootstrap($name, $namespace, $file);
        //$this->addConfig("$namespace\\$name", config_path() . '/bootstrap.php');

        return self::SUCCESS;
    }

    /**
     * @param $name
     * @param $namespace
     * @param $file
     * @return void
     */
    protected function createBootstrap($name, $namespace, $file)
    {
        $path = pathinfo($file, PATHINFO_DIRNAME);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $bootstrap_content = <<<EOF
<?php

namespace $namespace;

use Webman\Bootstrap;

class $name implements Bootstrap
{
    public static function start(\$worker)
    {
        // Is it console environment ?
        \$is_console = !\$worker;
        if (\$is_console) {
            // If you do not want to execute this in console, just return.
            return;
        }


    }

}

EOF;
        file_put_contents($file, $bootstrap_content);
    }

    public function addConfig($class, $config_file)
    {
        $config = include $config_file;
        if(!in_array($class, $config ?? [])) {
            $config_file_content = file_get_contents($config_file);
            $config_file_content = preg_replace('/\];/', "    $class::class,\n];", $config_file_content);
            file_put_contents($config_file, $config_file_content);
        }
    }
}
