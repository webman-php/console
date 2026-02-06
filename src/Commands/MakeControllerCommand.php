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

#[AsCommand('make:controller', 'Make controller')]
class MakeControllerCommand extends Command
{
    use MakeCommandHelpers;

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Controller name');
        $this->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, 'Plugin name under plugin/. e.g. admin');
        $this->addOption('path', 'P', InputOption::VALUE_REQUIRED, 'Target directory (relative to base path). e.g. plugin/admin/app/controller');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Override existing file without confirmation.');

        $this->setHelp($this->buildHelpText());

        $this->addUsage('User');
        $this->addUsage('User -p admin');
        $this->addUsage('User -P plugin/admin/app/controller');
        $this->addUsage('Admin/User -f');
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

        // When "-p/--plugin" is provided, controller suffix should come from the plugin config,
        // not from the main project config.
        if ($plugin) {
            $suffix = (string)config("plugin.$plugin.app.controller_suffix", 'Controller');
        } else {
            $suffix = (string)config('app.controller_suffix', 'Controller');
        }
        $name = str_replace('\\', '/', $name);
        $name = $this->applySuffixToLastSegment($name, $suffix);

        if ($plugin || $path) {
            $resolved = $this->resolveTargetByPluginOrPath(
                $name,
                $plugin,
                $path,
                $output,
                fn(string $p) => $this->getPluginControllerRelativePath($p),
                fn(string $key, array $replace = []) => $this->msg($key, $replace)
            );
            if ($resolved === null) {
                return Command::FAILURE;
            }
            [$class, $namespace, $file] = $resolved;
        } else {
            [$class, $namespace, $file] = $this->resolveAppControllerTarget($name);
        }

        $output->writeln($this->msg('make_controller', ['{name}' => $class]));

        if (is_file($file) && !$force) {
            $helper = $this->getHelper('question');
            $relative = $this->toRelativePath($file);
            $prompt = $this->msg('override_prompt', ['{path}' => $relative]);
            $question = new ConfirmationQuestion($prompt, true);
            if (!$helper->ask($input, $output, $question)) {
                return Command::SUCCESS;
            }
        }

        $this->createController($class, $namespace, $file);
        $output->writeln($this->msg('created', ['{path}' => $this->toRelativePath($file)]));
        return self::SUCCESS;
    }

    /**
     * @param $name
     * @param $namespace
     * @param $file
     * @return void
     */
    protected function createController($name, $namespace, $file)
    {
        $path = pathinfo($file, PATHINFO_DIRNAME);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $controller_content = <<<EOF
<?php

namespace $namespace;

use support\Request;

class $name
{
    public function index(Request \$request)
    {
        return response(__CLASS__);
    }

}

EOF;
        file_put_contents($file, $controller_content);
    }

    /**
     * Apply controller suffix to the last segment only.
     *
     * @param string $name
     * @param string $suffix
     * @return string
     */
    protected function applySuffixToLastSegment(string $name, string $suffix): string
    {
        $suffix = trim($suffix);
        if ($suffix === '') {
            return $name;
        }
        $pos = strrpos($name, '/');
        if ($pos === false) {
            return str_ends_with($name, $suffix) ? $name : ($name . $suffix);
        }
        $prefix = substr($name, 0, $pos + 1);
        $last = substr($name, $pos + 1);
        if (!str_ends_with($last, $suffix)) {
            $last .= $suffix;
        }
        return $prefix . $last;
    }

    /**
     * Resolve controller namespace/file path under app/ (backward compatible).
     *
     * @param string $name
     * @return array{0:string,1:string,2:string} [class, namespace, file]
     */
    protected function resolveAppControllerTarget(string $name): array
    {
        if (!($pos = strrpos($name, '/'))) {
            $class = ucfirst($name);
            $controllerStr = Util::guessPath(app_path(), 'controller') ?: 'controller';
            $file = app_path() . DIRECTORY_SEPARATOR . $controllerStr . DIRECTORY_SEPARATOR . "{$class}.php";
            $namespace = $controllerStr === 'Controller' ? 'App\Controller' : 'app\controller';
            return [$class, $namespace, $file];
        }

        $nameStr = substr($name, 0, $pos);
        $realNameStr = null;
        if ($tmp = Util::guessPath(app_path(), $nameStr)) {
            $realNameStr = $tmp;
            $nameStr = $tmp;
        } else if ($realSectionName = Util::guessPath(app_path(), strstr($nameStr, '/', true))) {
            $upper = strtolower($realSectionName[0]) !== $realSectionName[0];
        } else if ($realBaseController = Util::guessPath(app_path(), 'controller')) {
            $upper = strtolower($realBaseController[0]) !== $realBaseController[0];
        }
        $upper = $upper ?? strtolower($nameStr[0]) !== $nameStr[0];

        if ($upper && !$realNameStr) {
            $nameStr = preg_replace_callback('/\/([a-z])/', static function ($matches) {
                return '/' . strtoupper($matches[1]);
            }, ucfirst($nameStr));
        }

        $path = "{$nameStr}/" . ($upper ? 'Controller' : 'controller');
        $class = ucfirst(substr($name, $pos + 1));
        $file = app_path() . DIRECTORY_SEPARATOR . $path . DIRECTORY_SEPARATOR . "{$class}.php";
        $namespace = str_replace('/', '\\', ($upper ? 'App/' : 'app/') . $path);
        return [$class, $namespace, $file];
    }

    /**
     * @param string $plugin
     * @return string relative path
     */
    protected function getPluginControllerRelativePath(string $plugin): string
    {
        $plugin = trim($plugin);
        $appDir = base_path('plugin' . DIRECTORY_SEPARATOR . $plugin . DIRECTORY_SEPARATOR . 'app');
        $controllerDir = Util::guessPath($appDir, 'controller') ?: 'controller';
        return $this->normalizeRelativePath("plugin/{$plugin}/app/{$controllerDir}");
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
            'make_controller' => '<info>创建控制器</info> <comment>{name}</comment>',
            'created' => '<info>已创建：</info> {path}',
            'override_prompt' => "<fg=blue>文件已存在：{path}\n是否覆盖？[Y/n]（回车=Y）</>\n",
            'invalid_plugin' => '<error>插件名无效：{plugin}。`--plugin/-p` 只能是 plugin/ 目录下的目录名，不能包含 / 或 \\。</error>',
            'plugin_path_conflict' => "<error>`--plugin/-p` 与 `--path/-P` 同时指定且不一致。\n期望路径：{expected}\n实际路径：{actual}\n请二选一或保持一致。</error>",
            'invalid_path' => '<error>路径无效：{path}。`--path/-P` 必须是相对路径（相对于项目根目录），不能是绝对路径。</error>',
        ];

        $en = [
            'make_controller' => '<info>Make controller</info> <comment>{name}</comment>',
            'created' => '<info>Created:</info> {path}',
            'override_prompt' => "<fg=blue>File already exists: {path}\nOverride? [Y/n] (Enter = Y)</>\n",
            'invalid_plugin' => '<error>Invalid plugin name: {plugin}. `--plugin/-p` must be a directory name under plugin/ and must not contain / or \\.</error>',
            'plugin_path_conflict' => "<error>`--plugin/-p` and `--path/-P` are both provided but inconsistent.\nExpected: {expected}\nActual: {actual}\nPlease provide only one, or make them identical.</error>",
            'invalid_path' => '<error>Invalid path: {path}. `--path/-P` must be a relative path (to project root) and must not be an absolute path.</error>',
        ];

        $map = $this->isZhLocale() ? $zh : $en;
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
        if ($this->isZhLocale()) {
            return <<<'EOF'
生成控制器文件。

推荐用法：
  php webman make:controller User
  php webman make:controller User -p admin
  php webman make:controller User -P plugin/admin/app/controller
  php webman make:controller Admin/User -f

说明：
  - 默认生成到 app/controller（大小写以现有目录为准）。
  - 使用 -p/--plugin 时默认生成到 plugin/<plugin>/app/controller。
  - 使用 -P/--path 时生成到指定相对目录（相对于项目根目录）。
  - 文件已存在时默认会提示是否覆盖；使用 -f/--force 可直接覆盖。
EOF;
        }

        return <<<'EOF'
Generate a controller file.

Recommended:
  php webman make:controller User
  php webman make:controller User -p admin
  php webman make:controller User -P plugin/admin/app/controller
  php webman make:controller Admin/User -f

Notes:
  - By default, it generates under app/controller (case depends on existing directory).
  - With -p/--plugin, it generates under plugin/<plugin>/app/controller by default.
  - With -P/--path, it generates under the specified relative directory (to project root).
  - If the file already exists, it will ask before overriding; use -f/--force to override directly.
EOF;
    }

}
