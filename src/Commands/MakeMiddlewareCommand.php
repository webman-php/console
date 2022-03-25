<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;


class MakeMiddlewareCommand extends Command
{
    protected static $defaultName = 'make:middleware';
    protected static $defaultDescription = 'Make middleware';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Middleware name');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $output->writeln("Make middleware $name");
        if (!($pos = strrpos($name, '/'))) {
            $name = ucfirst($name);
            $file = "app/middleware/$name.php";
            $namespace = 'app\middleware';
        } else {
            $path = 'app/middleware/' . substr($name, 0, $pos);
            $name = ucfirst(substr($name, $pos + 1));
            $file = "$path/$name.php";
            $namespace = str_replace('/', '\\', $path);
        }
        $this->createMiddleware($name, $namespace, $file);

        return self::SUCCESS;
    }


    /**
     * @param $name
     * @param $namespace
     * @param $path
     * @return void
     */
    protected function createMiddleware($name, $namespace, $file)
    {
        $path = pathinfo($file, PATHINFO_DIRNAME);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $middleware_content = <<<EOF
<?php
namespace $namespace;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class $name implements MiddlewareInterface
{
    public function process(Request \$request, callable \$next) : Response
    {
        return \$next(\$request);
    }
    
}

EOF;
        file_put_contents($file, $middleware_content);
    }

}
