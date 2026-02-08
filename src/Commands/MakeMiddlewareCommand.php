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
        $zh = [
            'make_middleware' => '<info>创建中间件</info> <comment>{name}</comment>',
            'created' => '<info>已创建：</info> {path}',
            'configured' => '<info>已配置：</info> {class} -> {file}',
            'configured_exists' => '<comment>[Info]</comment> 配置已存在，跳过：{class} -> {file}',
            'override_prompt' => "<question>文件已存在：{path}</question>\n<question>是否覆盖？[Y/n]（回车=Y）</question>\n",
            'invalid_plugin' => '<error>插件名无效：{plugin}。`--plugin/-p` 只能是 plugin/ 目录下的目录名，不能包含 / 或 \\。</error>',
            'plugin_path_conflict' => "<error>`--plugin/-p` 与 `--path/-P` 同时指定且不一致。\n期望路径：{expected}\n实际路径：{actual}\n请二选一或保持一致。</error>",
            'invalid_path' => '<error>路径无效：{path}。`--path/-P` 必须是相对路径（相对于项目根目录），不能是绝对路径。</error>',
        ];

        $en = [
            'make_middleware' => '<info>Make middleware</info> <comment>{name}</comment>',
            'created' => '<info>Created:</info> {path}',
            'configured' => '<info>Configured:</info> {class} -> {file}',
            'configured_exists' => '<comment>[Info]</comment> Already configured, skipped: {class} -> {file}',
            'override_prompt' => "<question>File already exists: {path}</question>\n<question>Override? [Y/n] (Enter = Y)</question>\n",
            'invalid_plugin' => '<error>Invalid plugin name: {plugin}. `--plugin/-p` must be a directory name under plugin/ and must not contain / or \\.</error>',
            'plugin_path_conflict' => "<error>`--plugin/-p` and `--path/-P` are both provided but inconsistent.\nExpected: {expected}\nActual: {actual}\nPlease provide only one, or make them identical.</error>",
            'invalid_path' => '<error>Invalid path: {path}. `--path/-P` must be a relative path (to project root) and must not be an absolute path.</error>',
        ];

        $map = Util::selectLocaleMessages(['zh_CN' => $zh, 'en' => $en]);
        $text = $map[$key] ?? $key;
        return $replace ? strtr($text, $replace) : $text;
    }

    /**
     * Command help text (bilingual).
     *
     * @return string
     */
    protected function buildHelpText(): string
    {
        $zh = <<<'EOF'
生成中间件文件。

推荐用法：
  php webman make:middleware Auth
  php webman make:middleware Auth -p admin
  php webman make:middleware Auth -P plugin/admin/app/middleware
  php webman make:middleware Api/Auth -f

说明：
  - 默认生成到 app/middleware（大小写以现有目录为准）。
  - 使用 -p/--plugin 时默认生成到 plugin/<plugin>/app/middleware。
  - 使用 -P/--path 时生成到指定相对目录（相对于项目根目录）。
  - 文件已存在时默认会提示是否覆盖；使用 -f/--force 可直接覆盖。
EOF;
        $en = <<<'EOF'
Generate a middleware file.

Recommended:
  php webman make:middleware Auth
  php webman make:middleware Auth -p admin
  php webman make:middleware Auth -P plugin/admin/app/middleware
  php webman make:middleware Api/Auth -f

Notes:
  - By default, it generates under app/middleware (case depends on existing directory).
  - With -p/--plugin, it generates under plugin/<plugin>/app/middleware by default.
  - With -P/--path, it generates under the specified relative directory (to project root).
  - If the file already exists, it will ask before overriding; use -f/--force to override directly.
EOF;
        return Util::selectByLocale(['zh_CN' => $zh, 'en' => $en]);
    }

}
