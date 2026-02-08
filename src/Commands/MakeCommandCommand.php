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

#[AsCommand('make:command', 'Make command')]
class MakeCommandCommand extends Command
{
    use MakeCommandHelpers;

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Command name');
        $this->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, 'Plugin name under plugin/. e.g. admin');
        $this->addOption('path', 'P', InputOption::VALUE_REQUIRED, 'Target directory (relative to base path). e.g. plugin/admin/app/command');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Override existing file without confirmation.');

        $this->setHelp($this->buildHelpText());

        $this->addUsage('user:list');
        $this->addUsage('user:list -p admin');
        $this->addUsage('user:list -P plugin/admin/app/command');
        $this->addUsage('user:list -f');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $command = trim((string)$input->getArgument('name'));
        $plugin = $this->normalizeOptionValue($input->getOption('plugin'));
        $path = $this->normalizeOptionValue($input->getOption('path'));
        $force = (bool)$input->getOption('force');

        if ($plugin && (str_contains($plugin, '/') || str_contains($plugin, '\\'))) {
            $output->writeln($this->msg('invalid_plugin', ['{plugin}' => $plugin]));
            return Command::FAILURE;
        }

        // make:command 不支持子目录（不允许 / 或 \）
        $command = str_replace(['\\', '/'], '', $command);
        if ($command === '') {
            $output->writeln($this->msg('invalid_command'));
            return Command::FAILURE;
        }

        $class = $this->commandToClassName($command);

        if ($plugin || $path) {
            $resolved = $this->resolveTargetByPluginOrPath(
                $class,
                $plugin,
                $path,
                $output,
                fn(string $p) => $this->getPluginCommandRelativePath($p),
                fn(string $key, array $replace = []) => $this->msg($key, $replace)
            );
            if ($resolved === null) {
                return Command::FAILURE;
            }
            [$class, $namespace, $file] = $resolved;
        } else {
            [$class, $namespace, $file] = $this->resolveAppCommandTarget($class);
        }

        $output->writeln($this->msg('make_command', ['{name}' => $command]));

        if (is_file($file) && !$force) {
            $helper = $this->getHelper('question');
            $relative = $this->toRelativePath($file);
            $prompt = $this->msg('override_prompt', ['{path}' => $relative]);
            $question = new ConfirmationQuestion($prompt, true);
            if (!$helper->ask($input, $output, $question)) {
                return Command::SUCCESS;
            }
        }

        $this->createCommand($class, $namespace, $file, $command);
        $output->writeln($this->msg('created', ['{path}' => $this->toRelativePath($file)]));

        return self::SUCCESS;
    }

    /**
     * Convert a console command name (like "user:list") to a PHP class name (like "UserList").
     *
     * @param string $command
     * @return string
     */
    protected function commandToClassName(string $command): string
    {
        $items = array_values(array_filter(explode(':', $command), static fn($v) => $v !== ''));
        $name = '';
        foreach ($items as $item) {
            // Support kebab/snake: foo-bar => FooBar
            $tmp = Util::nameToClass(str_replace('-', '_', $item));
            $tmp = str_replace(['\\', '/'], '', $tmp);
            $name .= ucfirst($tmp);
        }
        return $name ?: 'ConsoleCommand';
    }

    /**
     * Resolve command namespace/file path under app/ (backward compatible).
     *
     * @param string $class
     * @return array{0:string,1:string,2:string} [class, namespace, file]
     */
    protected function resolveAppCommandTarget(string $class): array
    {
        $commandStr = Util::guessPath(app_path(), 'command');
        if (!$commandStr) {
            $commandStr = Util::guessPath(app_path(), 'controller') === 'Controller' ? 'Command' : 'command';
        }
        $upper = $commandStr === 'Command';
        $file = app_path() . DIRECTORY_SEPARATOR . $commandStr . DIRECTORY_SEPARATOR . "{$class}.php";
        $namespace = $upper ? 'App\Command' : 'app\command';
        return [$class, $namespace, $file];
    }

    /**
     * @param string $plugin
     * @return string relative path
     */
    protected function getPluginCommandRelativePath(string $plugin): string
    {
        $plugin = trim($plugin);
        $appDir = base_path('plugin' . DIRECTORY_SEPARATOR . $plugin . DIRECTORY_SEPARATOR . 'app');
        $commandDir = Util::guessPath($appDir, 'command');
        if (!$commandDir) {
            $commandDir = Util::guessPath($appDir, 'controller') === 'Controller' ? 'Command' : 'command';
        }
        return $this->normalizeRelativePath("plugin/{$plugin}/app/{$commandDir}");
    }

    /**
     * @param $name
     * @param $namespace
     * @param $path
     * @return void
     */
    protected function createCommand($name, $namespace, $file, $command)
    {
        $path = pathinfo($file, PATHINFO_DIRNAME);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $desc = str_replace(':', ' ', $command);
        $command_content = <<<EOF
<?php

namespace $namespace;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('$command', '$desc')]
class $name extends Command
{
    protected function configure(): void
    {
    }

    protected function execute(InputInterface \$input, OutputInterface \$output): int
    {
        \$output->writeln('<info>Hello</info> <comment>' . \$this->getName() . '</comment>');
        return self::SUCCESS;
    }
}

EOF;
        file_put_contents($file, $command_content);
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
            'make_command' => '<info>创建命令</info> <comment>{name}</comment>',
            'created' => '<info>已创建：</info> {path}',
            'override_prompt' => "<question>文件已存在：{path}</question>\n<question>是否覆盖？[Y/n]（回车=Y）</question>\n",
            'invalid_plugin' => '<error>插件名无效：{plugin}。`--plugin/-p` 只能是 plugin/ 目录下的目录名，不能包含 / 或 \\。</error>',
            'plugin_path_conflict' => "<error>`--plugin/-p` 与 `--path/-P` 同时指定且不一致。\n期望路径：{expected}\n实际路径：{actual}\n请二选一或保持一致。</error>",
            'invalid_path' => '<error>路径无效：{path}。`--path/-P` 必须是相对路径（相对于项目根目录），不能是绝对路径。</error>',
            'invalid_command' => '<error>命令名不能为空。</error>',
        ];

        $en = [
            'make_command' => '<info>Make command</info> <comment>{name}</comment>',
            'created' => '<info>Created:</info> {path}',
            'override_prompt' => "<question>File already exists: {path}</question>\n<question>Override? [Y/n] (Enter = Y)</question>\n",
            'invalid_plugin' => '<error>Invalid plugin name: {plugin}. `--plugin/-p` must be a directory name under plugin/ and must not contain / or \\.</error>',
            'plugin_path_conflict' => "<error>`--plugin/-p` and `--path/-P` are both provided but inconsistent.\nExpected: {expected}\nActual: {actual}\nPlease provide only one, or make them identical.</error>",
            'invalid_path' => '<error>Invalid path: {path}. `--path/-P` must be a relative path (to project root) and must not be an absolute path.</error>',
            'invalid_command' => '<error>Command name cannot be empty.</error>',
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
生成 Console 命令类（Symfony Console）。

推荐用法：
  php webman make:command user:list
  php webman make:command user:list -p admin
  php webman make:command user:list -P plugin/admin/app/command
  php webman make:command user:list -f

说明：
  - 命令名支持冒号分段（例如 user:list），生成类名会自动转为驼峰（UserList）。
  - 默认生成到 app/command（大小写以现有目录为准）。
  - 使用 -p/--plugin 时默认生成到 plugin/<plugin>/app/command。
  - 使用 -P/--path 时生成到指定相对目录（相对于项目根目录）。
  - 文件已存在时默认会提示是否覆盖；使用 -f/--force 可直接覆盖。
EOF;
        $en = <<<'EOF'
Generate a Console command class (Symfony Console).

Recommended:
  php webman make:command user:list
  php webman make:command user:list -p admin
  php webman make:command user:list -P plugin/admin/app/command
  php webman make:command user:list -f

Notes:
  - Command name supports colon segments (e.g. user:list). The class name will be camel-cased (UserList).
  - By default, it generates under app/command (case depends on existing directory).
  - With -p/--plugin, it generates under plugin/<plugin>/app/command by default.
  - With -P/--path, it generates under the specified relative directory (to project root).
  - If the file already exists, it will ask before overriding; use -f/--force to override directly.
EOF;
        return Util::selectByLocale(['zh_CN' => $zh, 'en' => $en]);
    }

}
