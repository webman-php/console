<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Webman\Console\Util;
use Webman\Console\Messages;
use Webman\Console\Commands\Concerns\MakeCommandHelpers;

#[AsCommand('make:middleware', 'Make middleware')]
class MakeMiddlewareCommand extends Command
{
    use MakeCommandHelpers;

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Middleware name');
        $this->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, 'Plugin name under plugin/. e.g. admin');
        $this->addOption('path', 'P', InputOption::VALUE_REQUIRED, 'Target directory (relative to base path). e.g. plugin/admin/app/middleware');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Override existing file without confirmation.');

        $this->setHelp($this->buildHelpText());

        $this->addUsage('Auth');
        $this->addUsage('Auth -p admin');
        $this->addUsage('Auth -P plugin/admin/app/middleware');
        $this->addUsage('Api/Auth -f');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = Util::nameToClass((string)$input->getArgument('name'));
        $plugin = $this->normalizeOptionValue($input->getOption('plugin'));
        $path = $this->normalizeOptionValue($input->getOption('path'));
        $force = (bool)$input->getOption('force');

        if ($plugin && (str_contains($plugin, '/') || str_contains($plugin, '\\'))) {
            $output->writeln($this->msg('invalid_plugin', ['{plugin}' => $plugin]));
            return Command::FAILURE;
        }

        $name = str_replace('\\', '/', $name);

        if ($plugin || $path) {
            $resolved = $this->resolveTargetByPluginOrPath(
                $name,
                $plugin,
                $path,
                $output,
                fn(string $p) => $this->getPluginMiddlewareRelativePath($p),
                fn(string $key, array $replace = []) => $this->msg($key, $replace)
            );
            if ($resolved === null) {
                return Command::FAILURE;
            }
            [$class, $namespace, $file] = $resolved;
        } else {
            [$class, $namespace, $file] = $this->resolveAppMiddlewareTarget($name);
        }

        $output->writeln($this->msg('make_middleware', ['{name}' => $class]));

        if (is_file($file) && !$force) {
            $helper = $this->getHelper('question');
            $relative = $this->toRelativePath($file);
            $prompt = $this->msg('override_prompt', ['{path}' => $relative]);
            $question = new ConfirmationQuestion($prompt, true);
            if (!$helper->ask($input, $output, $question)) {
                return Command::SUCCESS;
            }
        }

        $this->createMiddleware($class, $namespace, $file);
        $output->writeln($this->msg('created', ['{path}' => $this->toRelativePath($file)]));

        $middlewareClass = "{$namespace}\\{$class}";
        $configFile = $plugin
            ? base_path('plugin' . DIRECTORY_SEPARATOR . $plugin . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'middleware.php')
            : (config_path() . '/middleware.php');
        $changed = $this->addClassToMiddlewareConfig($configFile, $middlewareClass);
        if ($changed) {
            $output->writeln($this->msg('configured', ['{class}' => $middlewareClass, '{file}' => $this->toRelativePath($configFile)]));
        } else {
            $output->writeln($this->msg('configured_exists', ['{class}' => $middlewareClass, '{file}' => $this->toRelativePath($configFile)]));
        }

        return self::SUCCESS;
    }

    /**
     * Resolve middleware namespace/file path under app/ (backward compatible).
     *
     * @param string $name
     * @return array{0:string,1:string,2:string} [class, namespace, file]
     */
    protected function resolveAppMiddlewareTarget(string $name): array
    {
        $middlewareStr = Util::guessPath(app_path(), 'middleware');
        if (!$middlewareStr) {
            $middlewareStr = Util::guessPath(app_path(), 'controller') === 'Controller' ? 'Middleware' : 'middleware';
        }
        $upper = $middlewareStr === 'Middleware';

        if (!($pos = strrpos($name, '/'))) {
            $class = ucfirst($name);
            $file = app_path() . DIRECTORY_SEPARATOR . $middlewareStr . DIRECTORY_SEPARATOR . "{$class}.php";
            $namespace = $upper ? 'App\Middleware' : 'app\middleware';
            return [$class, $namespace, $file];
        }

        $dirPart = substr($name, 0, $pos);
        $realDirPart = Util::guessPath(app_path(), $dirPart);
        if ($realDirPart) {
            $dirPart = str_replace(DIRECTORY_SEPARATOR, '/', $realDirPart);
        } else if ($upper) {
            $dirPart = preg_replace_callback('/\/([a-z])/', static function ($matches) {
                return '/' . strtoupper($matches[1]);
            }, ucfirst($dirPart));
        }

        $path = "{$middlewareStr}/{$dirPart}";
        $class = ucfirst(substr($name, $pos + 1));
        $file = app_path() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path) . DIRECTORY_SEPARATOR . "{$class}.php";
        $namespace = str_replace('/', '\\', ($upper ? 'App/' : 'app/') . $path);
        return [$class, $namespace, $file];
    }

    /**
     * @param string $plugin
     * @return string relative path
     */
    protected function getPluginMiddlewareRelativePath(string $plugin): string
    {
        $plugin = trim($plugin);
        $appDir = base_path('plugin' . DIRECTORY_SEPARATOR . $plugin . DIRECTORY_SEPARATOR . 'app');
        $middlewareDir = Util::guessPath($appDir, 'middleware');
        if (!$middlewareDir) {
            $middlewareDir = Util::guessPath($appDir, 'controller') === 'Controller' ? 'Middleware' : 'middleware';
        }
        return $this->normalizeRelativePath("plugin/{$plugin}/app/{$middlewareDir}");
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
    public function process(Request \$request, callable \$handler) : Response
    {
        return \$handler(\$request);
    }
    
}

EOF;
        file_put_contents($file, $middleware_content);
    }

    /**
     * Hardcoded CLI messages (bilingual) without translation module.
     *
     * @param string $key
     * @param array $replace
     * @return string
     */
    protected function msg(string $key, array $replace = []): string
    {
        return strtr(Util::selectLocaleMessages(Messages::getMakeMiddlewareMessages())[$key] ?? $key, $replace);
    }

    /**
     * Command help text (multilingual).
     *
     * @return string
     */
    protected function buildHelpText(): string
    {
        return Util::selectLocaleMessages(Messages::getMakeMiddlewareHelpText());
    }

}
