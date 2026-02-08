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

#[AsCommand('make:bootstrap', 'Make a bootstrap.')]
class MakeBootstrapCommand extends Command
{
    use MakeCommandHelpers;

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Bootstrap name');
        $this->addArgument('enable', InputArgument::OPTIONAL, 'Enable or not');
        $this->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, 'Plugin name under plugin/. e.g. admin');
        $this->addOption('path', 'P', InputOption::VALUE_REQUIRED, 'Target directory (relative to base path). e.g. plugin/admin/app/bootstrap');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Override existing file without confirmation.');

        $this->setHelp($this->buildHelpText());

        $this->addUsage('MyBootstrap');
        $this->addUsage('MyBootstrap no');
        $this->addUsage('MyBootstrap -p admin');
        $this->addUsage('MyBootstrap -P plugin/admin/app/bootstrap');
        $this->addUsage('MyBootstrap -f');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = Util::nameToClass((string)$input->getArgument('name'));
        $enable = $this->parseEnableArgument($input->getArgument('enable'));
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
                fn(string $p) => $this->getPluginBootstrapRelativePath($p),
                fn(string $key, array $replace = []) => $this->msg($key, $replace)
            );
            if ($resolved === null) {
                return Command::FAILURE;
            }
            [$class, $namespace, $file] = $resolved;
        } else {
            [$class, $namespace, $file] = $this->resolveAppBootstrapTarget($name);
        }

        $output->writeln($this->msg('make_bootstrap', ['{name}' => $class]));

        if (is_file($file) && !$force) {
            $helper = $this->getHelper('question');
            $relative = $this->toRelativePath($file);
            $prompt = $this->msg('override_prompt', ['{path}' => $relative]);
            $question = new ConfirmationQuestion($prompt, true);
            if (!$helper->ask($input, $output, $question)) {
                return Command::SUCCESS;
            }
        }

        $this->createBootstrap($class, $namespace, $file);
        $output->writeln($this->msg('created', ['{path}' => $this->toRelativePath($file)]));

        if ($enable) {
            $bootstrapClass = "{$namespace}\\{$class}";
            $configFile = $plugin
                ? base_path('plugin' . DIRECTORY_SEPARATOR . $plugin . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bootstrap.php')
                : (config_path() . '/bootstrap.php');

            $changed = $this->addClassToFlatClassListConfig($configFile, $bootstrapClass);
            if ($changed) {
                $output->writeln($this->msg('enabled', ['{class}' => $bootstrapClass]));
            } else {
                $output->writeln($this->msg('enabled_exists', ['{class}' => $bootstrapClass]));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Resolve bootstrap namespace/file path under app/ (backward compatible).
     *
     * @param string $name
     * @return array{0:string,1:string,2:string} [class, namespace, file]
     */
    protected function resolveAppBootstrapTarget(string $name): array
    {
        $bootstrapStr = Util::guessPath(app_path(), 'bootstrap');
        if (!$bootstrapStr) {
            $bootstrapStr = Util::guessPath(app_path(), 'controller') === 'Controller' ? 'Bootstrap' : 'bootstrap';
        }
        $upper = $bootstrapStr === 'Bootstrap';

        if (!($pos = strrpos($name, '/'))) {
            $class = ucfirst($name);
            $file = app_path() . DIRECTORY_SEPARATOR . $bootstrapStr . DIRECTORY_SEPARATOR . "{$class}.php";
            $namespace = $upper ? 'App\Bootstrap' : 'app\bootstrap';
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

        $path = "{$bootstrapStr}/{$dirPart}";
        $class = ucfirst(substr($name, $pos + 1));
        $file = app_path() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path) . DIRECTORY_SEPARATOR . "{$class}.php";
        $namespace = str_replace('/', '\\', ($upper ? 'App/' : 'app/') . $path);
        return [$class, $namespace, $file];
    }

    /**
     * @param string $plugin
     * @return string relative path
     */
    protected function getPluginBootstrapRelativePath(string $plugin): string
    {
        $plugin = trim($plugin);
        $appDir = base_path('plugin' . DIRECTORY_SEPARATOR . $plugin . DIRECTORY_SEPARATOR . 'app');
        $bootstrapDir = Util::guessPath($appDir, 'bootstrap');
        if (!$bootstrapDir) {
            $bootstrapDir = Util::guessPath($appDir, 'controller') === 'Controller' ? 'Bootstrap' : 'bootstrap';
        }
        return $this->normalizeRelativePath("plugin/{$plugin}/app/{$bootstrapDir}");
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

    /**
     * Parse positional `enable` argument (backward compatible).
     *
     * @param mixed $value
     * @return bool
     */
    protected function parseEnableArgument(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        $v = strtolower(trim((string)$value));
        return !in_array($v, ['no', '0', 'false', 'n', 'off', 'disable', 'disabled'], true);
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
            'make_bootstrap' => '<info>创建启动项</info> <comment>{name}</comment>',
            'created' => '<info>已创建：</info> {path}',
            'enabled' => '<info>已启用：</info> {class}',
            'enabled_exists' => '<comment>[Info]</comment> 已存在，无需重复写入：{class}',
            'override_prompt' => "<question>文件已存在：{path}</question>\n<question>是否覆盖？[Y/n]（回车=Y）</question>\n",
            'invalid_plugin' => '<error>插件名无效：{plugin}。`--plugin/-p` 只能是 plugin/ 目录下的目录名，不能包含 / 或 \\。</error>',
            'plugin_path_conflict' => "<error>`--plugin/-p` 与 `--path/-P` 同时指定且不一致。\n期望路径：{expected}\n实际路径：{actual}\n请二选一或保持一致。</error>",
            'invalid_path' => '<error>路径无效：{path}。`--path/-P` 必须是相对路径（相对于项目根目录），不能是绝对路径。</error>',
        ];

        $en = [
            'make_bootstrap' => '<info>Make bootstrap</info> <comment>{name}</comment>',
            'created' => '<info>Created:</info> {path}',
            'enabled' => '<info>Enabled:</info> {class}',
            'enabled_exists' => '<comment>[Info]</comment> Already exists, skipped: {class}',
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
生成 Bootstrap 启动项类（实现 Webman\Bootstrap）。

推荐用法：
  php webman make:bootstrap MyBootstrap
  php webman make:bootstrap MyBootstrap no
  php webman make:bootstrap MyBootstrap -p admin
  php webman make:bootstrap MyBootstrap -P plugin/admin/app/bootstrap
  php webman make:bootstrap MyBootstrap -f

说明：
  - 默认生成到 app/bootstrap（大小写以现有目录为准）。
  - 使用 -p/--plugin 时默认生成到 plugin/<plugin>/app/bootstrap。
  - 使用 -P/--path 时生成到指定相对目录（相对于项目根目录）。
  - enable 位置参数用于控制是否写入 config/bootstrap.php（默认启用；传 no/false/0/off 等表示不启用）。
  - 文件已存在时默认会提示是否覆盖；使用 -f/--force 可直接覆盖。
EOF;
        $en = <<<'EOF'
Generate a Bootstrap class (implements Webman\Bootstrap).

Recommended:
  php webman make:bootstrap MyBootstrap
  php webman make:bootstrap MyBootstrap no
  php webman make:bootstrap MyBootstrap -p admin
  php webman make:bootstrap MyBootstrap -P plugin/admin/app/bootstrap
  php webman make:bootstrap MyBootstrap -f

Notes:
  - By default, it generates under app/bootstrap (case depends on existing directory).
  - With -p/--plugin, it generates under plugin/<plugin>/app/bootstrap by default.
  - With -P/--path, it generates under the specified relative directory (to project root).
  - The positional `enable` argument controls whether to append to config/bootstrap.php (enabled by default; use no/false/0/off to disable).
  - If the file already exists, it will ask before overriding; use -f/--force to override directly.
EOF;
        return Util::selectByLocale(['zh_CN' => $zh, 'en' => $en]);
    }
}
