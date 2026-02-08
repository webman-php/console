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
     * Command help text (multilingual).
     *
     * @return string
     */
    protected function buildHelpText(): string
    {
        return Util::selectLocaleMessages(Messages::getMakeCommandHelpText());
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
        return strtr(Util::selectLocaleMessages(Messages::getMakeCommandMessages()[$key] ?? $key), $replace);
    }
}
