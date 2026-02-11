<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Webman\Console\Commands\Concerns\MakeCommandHelpers;
use Webman\Console\Util;
use Webman\Console\Messages;

#[AsCommand('make:process', 'Make a custom process.')]
class MakeProcessCommand extends Command
{
    use MakeCommandHelpers;

    private const PROTOCOLS = [
        '1' => 'websocket',
        '2' => 'http',
        '3' => 'tcp',
        '4' => 'udp',
        '5' => 'unixsocket',
    ];

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, $this->msg('arg_name'));
        $this->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, $this->msg('opt_plugin'));
        $this->addOption('path', 'P', InputOption::VALUE_REQUIRED, $this->msg('opt_path'));
        $this->addOption('force', 'f', InputOption::VALUE_NONE, $this->msg('opt_force'));

        $this->setHelp($this->buildHelpText());

        $this->addUsage('MyTcp');
        $this->addUsage('MyWebsocket');
        $this->addUsage('MyProcess -p admin');
        $this->addUsage('MyProcess -P plugin/admin/app/process');
        $this->addUsage('MyProcess -f');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawName = (string)$input->getArgument('name');
        $name = Util::nameToClass($rawName);
        $name = str_replace('\\', '/', $name);

        $plugin = $this->normalizeOptionValue($input->getOption('plugin'));
        $path = $this->normalizeOptionValue($input->getOption('path'));
        $force = (bool)$input->getOption('force');

        if ($plugin && (str_contains($plugin, '/') || str_contains($plugin, '\\'))) {
            $output->writeln($this->msg('invalid_plugin', ['{plugin}' => $plugin]));
            return Command::FAILURE;
        }
        if ($plugin && !$this->assertPluginExists($plugin, $output)) {
            return Command::FAILURE;
        }

        if (!$path && $input->isInteractive()) {
            $pathDefault = $plugin ? $this->getPluginProcessRelativePath($plugin) : $this->getAppProcessRelativePath();
            $path = $this->promptForPathWithDefault($input, $output, 'process', $pathDefault);
        }

        $target = $this->resolveTarget($name, $plugin, $path, $output);
        if ($target === null) {
            return Command::FAILURE;
        }
        [$class, $namespace, $file] = $target;

        $snakeKey = $this->toSnakeKey($name);
        $pluginForConfig = $this->inferPluginNameForConfig($plugin, $path);
        $configFile = $pluginForConfig
            ? base_path('plugin' . DIRECTORY_SEPARATOR . $pluginForConfig . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'process.php')
            : (config_path() . DIRECTORY_SEPARATOR . 'process.php');

        $config = $this->loadPhpConfigArray($configFile);
        if ($config === null) {
            $output->writeln($this->msg('invalid_config', ['{path}' => $this->toRelativePath($configFile)]));
            return Command::FAILURE;
        }
        if (array_key_exists($snakeKey, $config)) {
            $handler = $this->stringifyHandler($config[$snakeKey] ?? null);
            $output->writeln($this->msg('config_key_exists', [
                '{key}' => $snakeKey,
                '{handler}' => $handler,
                '{path}' => $this->toRelativePath($configFile),
            ]));
            return Command::FAILURE;
        }

        $helper = $this->getHelper('question');

        $output->writeln($this->msg('make_process', [
            '{class}' => "{$namespace}\\{$class}",
            '{key}' => $snakeKey,
            '{config}' => $this->toRelativePath($configFile),
        ]));

        $listen = null;
        $protocol = null;
        $httpMode = null; // builtin|custom|null

        $qListen = new ConfirmationQuestion($this->msg('ask_listen'), false);
        $shouldListen = (bool)$helper->ask($input, $output, $qListen);

        if (!$shouldListen) {
            // Non-listening process: create class file; if exists, ask overwrite unless -f.
            if (is_file($file) && !$force) {
                $relative = $this->toRelativePath($file);
                $prompt = $this->msg('override_prompt', ['{path}' => $relative]);
                $question = new ConfirmationQuestion($prompt, true);
                if (!$helper->ask($input, $output, $question)) {
                    return Command::SUCCESS;
                }
            }
        } else {
            $protocol = $this->askProtocol($input, $output);

            if ($protocol === 'http') {
                $httpMode = $this->askHttpMode($input, $output);
            }

            $needsFile = $protocol !== 'http' || $httpMode !== 'builtin';
            if ($needsFile && is_file($file) && !$force) {
                $relative = $this->toRelativePath($file);
                $prompt = $this->msg('override_prompt', ['{path}' => $relative]);
                $question = new ConfirmationQuestion($prompt, true);
                if (!$helper->ask($input, $output, $question)) {
                    return Command::SUCCESS;
                }
            }

            $listen = $this->askListenAddress($input, $output, $snakeKey, $protocol);
        }

        $countValue = $this->askProcessCount($input, $output, $protocol, $httpMode);

        $handlerFqn = $this->buildHandlerFqn($namespace, $class, $pluginForConfig, $protocol, $httpMode);
        $configEntry = $this->buildProcessConfigEntry($snakeKey, $handlerFqn, $listen, $countValue, $protocol, $httpMode);

        // Create process file if needed.
        if (!$shouldListen) {
            $this->createProcessFile($protocol, $httpMode, $namespace, $class, $file, $snakeKey, null);
            $output->writeln($this->msg('created', ['{path}' => $this->toRelativePath($file)]));
        } else {
            $needsFile = $protocol !== 'http' || $httpMode !== 'builtin';
            if ($needsFile) {
                $this->createProcessFile($protocol, $httpMode, $namespace, $class, $file, $snakeKey, $listen);
                $output->writeln($this->msg('created', ['{path}' => $this->toRelativePath($file)]));
            } else {
                $output->writeln($this->msg('reuse_builtin_http', ['{handler}' => $handlerFqn . '::class']));
            }
        }

        // Update config file.
        $changed = $this->appendProcessConfigEntry($configFile, $configEntry);
        if (!$changed) {
            $output->writeln($this->msg('write_config_failed', ['{path}' => $this->toRelativePath($configFile)]));
            return Command::FAILURE;
        }
        $output->writeln($this->msg('updated_config', ['{path}' => $this->toRelativePath($configFile), '{key}' => $snakeKey]));

        return Command::SUCCESS;
    }

    /**
     * @param string $name class path like "Admin/MyProcess"
     * @param string|null $plugin
     * @param string|null $path
     * @param OutputInterface $output
     * @return array{0:string,1:string,2:string}|null [class, namespace, file]
     */
    private function resolveTarget(string $name, ?string $plugin, ?string $path, OutputInterface $output): ?array
    {
        if ($plugin || $path) {
            return $this->resolveTargetByPluginOrPath(
                $name,
                $plugin,
                $path,
                $output,
                fn(string $p) => $this->getPluginProcessRelativePath($p),
                fn(string $key, array $replace = []) => $this->msg($key, $replace)
            );
        }
        return $this->resolveAppProcessTarget($name);
    }

    /**
     * Default app process relative path.
     *
     * @return string
     */
    private function getAppProcessRelativePath(): string
    {
        $processStr = Util::guessPath(app_path(), 'process');
        if (!$processStr) {
            $processStr = Util::guessPath(app_path(), 'controller') === 'Controller' ? 'Process' : 'process';
        }
        return $this->normalizeRelativePath("app/{$processStr}");
    }

    /**
     * Resolve process namespace/file path under app/ (backward compatible).
     *
     * @param string $name
     * @return array{0:string,1:string,2:string} [class, namespace, file]
     */
    private function resolveAppProcessTarget(string $name): array
    {
        $processStr = Util::guessPath(app_path(), 'process');
        if (!$processStr) {
            $processStr = Util::guessPath(app_path(), 'controller') === 'Controller' ? 'Process' : 'process';
        }
        $upper = $processStr === 'Process';

        if (!($pos = strrpos($name, '/'))) {
            $class = ucfirst($name);
            $file = app_path() . DIRECTORY_SEPARATOR . $processStr . DIRECTORY_SEPARATOR . "{$class}.php";
            $namespace = $upper ? 'App\Process' : 'app\process';
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

        $path = "{$processStr}/{$dirPart}";
        $class = ucfirst(substr($name, $pos + 1));
        $file = app_path() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path) . DIRECTORY_SEPARATOR . "{$class}.php";
        $namespace = str_replace('/', '\\', ($upper ? 'App/' : 'app/') . $path);
        return [$class, $namespace, $file];
    }

    /**
     * @param string $plugin
     * @return string relative path
     */
    private function getPluginProcessRelativePath(string $plugin): string
    {
        $plugin = trim($plugin);
        $appDir = base_path('plugin' . DIRECTORY_SEPARATOR . $plugin . DIRECTORY_SEPARATOR . 'app');
        $processDir = Util::guessPath($appDir, 'process');
        if (!$processDir) {
            $processDir = Util::guessPath($appDir, 'controller') === 'Controller' ? 'Process' : 'process';
        }
        return $this->normalizeRelativePath("plugin/{$plugin}/app/{$processDir}");
    }

    /**
     * Prompt for path (question style, input on new line). Reuses enter_path_prompt from make:crud.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string $labelKey
     * @param string $defaultPath
     * @return string
     */
    private function promptForPathWithDefault(InputInterface $input, OutputInterface $output, string $labelKey, string $defaultPath): string
    {
        $defaultPath = $this->normalizeRelativePath($defaultPath);
        $label = Util::selectLocaleMessages(Messages::getTypeLabels())[$labelKey] ?? $labelKey;
        $promptText = Util::selectLocaleMessages(Messages::getMakeCrudMessages())['enter_path_prompt']
            ?? 'Enter {label} path (Enter for default: {default}): ';
        $promptText = strtr($promptText, ['{label}' => $label, '{default}' => $defaultPath]);
        $promptText = '<question>' . trim($promptText) . "</question>\n";
        $helper = $this->getHelper('question');
        $question = new Question($promptText, $defaultPath);
        $path = $helper->ask($input, $output, $question);
        $path = is_string($path) ? $path : $defaultPath;
        return $this->normalizeRelativePath($path ?: $defaultPath);
    }

    /**
     * Convert class path to snake key for config.
     *
     * @param string $name class path like "Admin/MyProcess"
     * @return string
     */
    private function toSnakeKey(string $name): string
    {
        $name = trim(str_replace('\\', '/', $name), '/');
        $parts = $name === '' ? [] : explode('/', $name);
        $snakes = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            $classLike = Util::nameToClass($p);
            $snakes[] = trim(Util::classToName(basename(str_replace('\\', '/', $classLike))), '_');
        }
        $key = strtolower(implode('_', $snakes));
        $key = preg_replace('/_+/', '_', $key);
        return $key ?: strtolower(Util::classToName(Util::nameToClass($name)));
    }

    /**
     * Infer plugin name for config update.
     * - Prefer explicit --plugin/-p.
     * - If not provided, but --path/-P points to plugin/<name>/..., infer <name>.
     *
     * @param string|null $plugin
     * @param string|null $path
     * @return string|null
     */
    private function inferPluginNameForConfig(?string $plugin, ?string $path): ?string
    {
        if ($plugin) {
            return trim($plugin);
        }
        if (!$path) {
            return null;
        }
        $pathNorm = $this->normalizeRelativePath($path);
        if (preg_match('#^plugin/([^/]+)/#i', $pathNorm, $m)) {
            $name = trim((string)($m[1] ?? ''));
            return $name !== '' ? $name : null;
        }
        return null;
    }

    /**
     * @param mixed $item
     * @return string
     */
    private function stringifyHandler(mixed $item): string
    {
        if (!is_array($item)) {
            return '(unknown)';
        }
        $handler = $item['handler'] ?? null;
        if (is_string($handler) && $handler !== '') {
            return $handler;
        }
        if (is_object($handler)) {
            return get_class($handler);
        }
        return '(unknown)';
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return string protocol: websocket|http|tcp|udp|unixsocket
     */
    private function askProtocol(InputInterface $input, OutputInterface $output): string
    {
        $helper = $this->getHelper('question');
        $default = '1';
        $prompt = $this->msg('ask_protocol');
        $q = new Question($prompt, $default);
        $q->setValidator(function (mixed $value) {
            $v = strtolower(trim((string)$value));
            $v = ltrim($v, '=');
            if ($v === '') {
                $v = '1';
            }
            if (isset(self::PROTOCOLS[$v])) {
                return self::PROTOCOLS[$v];
            }
            $v = str_replace([' ', '-'], '', $v);
            $aliases = [
                'ws' => 'websocket',
                'websocket' => 'websocket',
                'http' => 'http',
                'tcp' => 'tcp',
                'udp' => 'udp',
                'unix' => 'unixsocket',
                'unixsocket' => 'unixsocket',
                'unixsock' => 'unixsocket',
            ];
            if (isset($aliases[$v])) {
                return $aliases[$v];
            }
            throw new \RuntimeException($this->msg('err_invalid_protocol'));
        });
        $q->setMaxAttempts(3);
        /** @var string $protocol */
        $protocol = $helper->ask($input, $output, $q);
        return $protocol;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return string builtin|custom
     */
    private function askHttpMode(InputInterface $input, OutputInterface $output): string
    {
        $helper = $this->getHelper('question');
        $q = new Question($this->msg('ask_http_mode'), '1');
        $q->setValidator(function (mixed $value) {
            $v = strtolower(trim((string)$value));
            $v = ltrim($v, '=');
            if ($v === '' || $v === '1' || $v === 'builtin') {
                return 'builtin';
            }
            if ($v === '2' || $v === 'custom') {
                return 'custom';
            }
            throw new \RuntimeException($this->msg('err_invalid_http_mode'));
        });
        $q->setMaxAttempts(3);
        /** @var string $mode */
        $mode = $helper->ask($input, $output, $q);
        return $mode;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string $snakeKey
     * @param string $protocol
     * @return string listen string
     */
    private function askListenAddress(InputInterface $input, OutputInterface $output, string $snakeKey, string $protocol): string
    {
        if ($protocol === 'unixsocket') {
            return $this->askUnixSocketListen($input, $output, $snakeKey);
        }
        $ip = $this->askIp($input, $output);
        $port = $this->askPort($input, $output);
        $scheme = match ($protocol) {
            'websocket' => 'websocket://',
            'http' => 'http://',
            'tcp' => 'tcp://',
            'udp' => 'udp://',
            default => '',
        };
        return $scheme . $ip . ':' . $port;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return string ip
     */
    private function askIp(InputInterface $input, OutputInterface $output): string
    {
        $helper = $this->getHelper('question');
        $ips = $this->collectIps();

        $menu = [];
        $menu['1'] = '0.0.0.0';
        $menu['2'] = '127.0.0.1';
        $i = 3;
        if (!empty($ips['lan'])) {
            $menu[(string)$i] = $ips['lan'];
            $i++;
        }
        if (!empty($ips['wan'])) {
            $menu[(string)$i] = $ips['wan'];
            $i++;
        }

        $lines = [];
        $lines[] = $this->msg('ask_ip');
        $lines[] = $this->msg('ask_ip_options', ['{options}' => $this->formatIpOptions($menu, $ips)]);
        $prompt = implode("\n", $lines);

        $q = new Question($prompt, '1');
        $q->setValidator(function (mixed $value) use ($menu) {
            $v = trim((string)$value);
            $v = ltrim($v, '=');
            if ($v === '') {
                $v = '1';
            }
            if (isset($menu[$v])) {
                return $menu[$v];
            }
            // allow manual ip input
            if (filter_var($v, FILTER_VALIDATE_IP)) {
                return $v;
            }
            throw new \RuntimeException($this->msg('err_invalid_ip'));
        });
        $q->setMaxAttempts(3);
        /** @var string $ip */
        $ip = $helper->ask($input, $output, $q);
        return $ip;
    }

    /**
     * @param array<string,string> $menu
     * @param array{lan?:string,wan?:string} $ips
     * @return string
     */
    private function formatIpOptions(array $menu, array $ips): string
    {
        $labels = [];
        foreach ($menu as $k => $ip) {
            $suffix = '';
            if (($ips['lan'] ?? null) === $ip) {
                $suffix = $this->msg('ip_lan_suffix');
            } else if (($ips['wan'] ?? null) === $ip) {
                $suffix = $this->msg('ip_wan_suffix');
            }
            $labels[] = "{$k}) {$ip}{$suffix}";
        }
        $labels[] = $this->msg('ip_manual_hint');
        return implode('  ', $labels);
    }

    /**
     * @return array{lan?:string,wan?:string}
     */
    private function collectIps(): array
    {
        $lan = null;
        try {
            $host = gethostname();
            if ($host) {
                $ip = gethostbyname($host);
                if ($ip && filter_var($ip, FILTER_VALIDATE_IP) && !str_starts_with($ip, '127.')) {
                    $lan = $ip;
                }
            }
        } catch (\Throwable) {
        }

        $wan = null;
        try {
            $context = stream_context_create([
                'http' => ['timeout' => 2],
                'https' => ['timeout' => 2],
            ]);
            $ip = @file_get_contents('https://api.ipify.org', false, $context);
            $ip = is_string($ip) ? trim($ip) : '';
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                $wan = $ip;
            }
        } catch (\Throwable) {
        }

        return array_filter(['lan' => $lan, 'wan' => $wan], static fn($v) => is_string($v) && $v !== '');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int port
     */
    private function askPort(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');
        $q = new Question($this->msg('ask_port'));
        $q->setValidator(function (mixed $value) {
            $v = trim((string)$value);
            $v = ltrim($v, '=');
            if ($v === '' || !ctype_digit($v)) {
                throw new \RuntimeException($this->msg('err_invalid_port'));
            }
            $port = (int)$v;
            if ($port < 1 || $port > 65535) {
                throw new \RuntimeException($this->msg('err_invalid_port_range'));
            }
            return $port;
        });
        $q->setMaxAttempts(3);
        /** @var int $port */
        $port = $helper->ask($input, $output, $q);
        return $port;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string $snakeKey
     * @return string
     */
    private function askUnixSocketListen(InputInterface $input, OutputInterface $output, string $snakeKey): string
    {
        $helper = $this->getHelper('question');
        $default = 'runtime/' . $snakeKey . '.sock';
        $q = new Question($this->msg('ask_unixsocket', ['{default}' => $default]), $default);
        $q->setValidator(function (mixed $value) {
            $v = trim((string)$value);
            $v = ltrim($v, '=');
            if ($v === '') {
                throw new \RuntimeException($this->msg('err_invalid_unixsocket_path'));
            }
            return $v;
        });
        $q->setMaxAttempts(3);
        /** @var string $path */
        $path = $helper->ask($input, $output, $q);

        // Allow user to pass full listen string like unix:///tmp/a.sock
        if (str_contains($path, '://')) {
            return $path;
        }

        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '/')) {
            return 'unix://' . $path;
        }
        $abs = base_path($path);
        $abs = str_replace('\\', '/', $abs);
        // Workerman unix socket typically expects three slashes: unix:///path/to.sock
        if (!str_starts_with($abs, '/')) {
            // Windows path, keep as-is after scheme (for cross-platform config editing)
            return 'unix://' . $abs;
        }
        return 'unix://' . $abs;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string|null $protocol
     * @param string|null $httpMode
     * @return array{kind:string,value:int|string} kind=int|raw
     */
    private function askProcessCount(InputInterface $input, OutputInterface $output, ?string $protocol, ?string $httpMode): array
    {
        $helper = $this->getHelper('question');
        $default = '1';
        $defaultRaw = null;
        if ($protocol === 'http' && $httpMode === 'builtin') {
            $default = '';
            $defaultRaw = 'cpu_count() * 4';
        }

        $prompt = $this->msg('ask_count', [
            '{default}' => $defaultRaw ?: $default,
        ]);
        $q = new Question($prompt, $default);
        $q->setValidator(function (mixed $value) use ($defaultRaw, $default) {
            $v = trim((string)$value);
            $v = ltrim($v, '=');
            if ($v === '') {
                if ($defaultRaw) {
                    return ['kind' => 'raw', 'value' => $defaultRaw];
                }
                return ['kind' => 'int', 'value' => (int)$default];
            }
            if (!ctype_digit($v)) {
                throw new \RuntimeException($this->msg('err_invalid_count_int'));
            }
            $count = (int)$v;
            if ($count < 1) {
                throw new \RuntimeException($this->msg('err_invalid_count_min'));
            }
            return ['kind' => 'int', 'value' => $count];
        });
        $q->setMaxAttempts(3);
        /** @var array{kind:string,value:int|string} $count */
        $count = $helper->ask($input, $output, $q);
        return $count;
    }

    /**
     * Build handler FQN.
     *
     * @param string $namespace
     * @param string $class
     * @param string|null $pluginForConfig
     * @param string|null $protocol
     * @param string|null $httpMode
     * @return string class FQN without leading backslash
     */
    private function buildHandlerFqn(string $namespace, string $class, ?string $pluginForConfig, ?string $protocol, ?string $httpMode): string
    {
        if ($protocol === 'http' && $httpMode === 'builtin') {
            // Always reuse the project built-in http process class.
            return 'app\process\Http';
        }
        return trim($namespace . '\\' . $class, '\\');
    }

    /**
     * @param string $key
     * @param string $handlerFqn
     * @param string|null $listen
     * @param array{kind:string,value:int|string} $countValue
     * @param string|null $protocol
     * @param string|null $httpMode
     * @return string config entry text with indentation (4 spaces)
     */
    private function buildProcessConfigEntry(
        string $key,
        string $handlerFqn,
        ?string $listen,
        array $countValue,
        ?string $protocol,
        ?string $httpMode
    ): string {
        $eol = "\n";
        $lines = [];
        $lines[] = "    " . var_export($key, true) . " => [";
        $lines[] = "        'handler' => {$handlerFqn}::class,";

        if ($listen !== null && $listen !== '') {
            $lines[] = "        'listen' => " . var_export($listen, true) . ",";
        }

        // count (optional in webman, but we always write for clarity)
        if (($countValue['kind'] ?? '') === 'raw') {
            $lines[] = "        'count' => {$countValue['value']},";
        } else {
            $lines[] = "        'count' => " . (int)$countValue['value'] . ",";
        }

        if ($protocol === 'http' && $httpMode === 'builtin') {
            // Align with webman built-in http process config style.
            $lines[] = "        'user' => '',";
            $lines[] = "        'group' => '',";
            $lines[] = "        'reusePort' => false,";
            $lines[] = "        'eventLoop' => '',";
            $lines[] = "        'context' => [],";
            $lines[] = "        'constructor' => [";
            $lines[] = "            'requestClass' => \\support\\Request::class,";
            $lines[] = "            'logger' => \\support\\Log::channel('default'),";
            $lines[] = "            'appPath' => app_path(),";
            $lines[] = "            'publicPath' => public_path(),";
            $lines[] = "        ],";
        }

        $lines[] = "    ],";
        return implode($eol, $lines);
    }

    /**
     * Append a single entry to process config file without rewriting the whole file
     * (to preserve existing code, e.g. global $argv usage).
     *
     * @param string $configFile
     * @param string $entryText
     * @return bool
     */
    private function appendProcessConfigEntry(string $configFile, string $entryText): bool
    {
        $eol = "\n";
        if (is_file($configFile)) {
            $content = file_get_contents($configFile);
            if (!is_string($content) || $content === '') {
                return false;
            }
            if (str_contains($content, "\r\n")) {
                $eol = "\r\n";
                $entryText = str_replace("\n", "\r\n", $entryText);
            }

            $pos = strrpos($content, '];');
            if ($pos === false) {
                return false;
            }

            $head = substr($content, 0, $pos);
            $tail = substr($content, $pos); // starts with ];
            $headTrim = rtrim($head);
            $needsComma = !str_ends_with($headTrim, ',');
            $insert = ($needsComma ? ',' : '') . $eol . $entryText . $eol;
            $newContent = $headTrim . $insert . $tail;
            file_put_contents($configFile, $newContent);
            return true;
        }

        // Create new config file.
        $this->ensureParentDir($configFile);
        $header = "<?php{$eol}{$eol}";
        $body = "return [{$eol}{$entryText}{$eol}];{$eol}";
        file_put_contents($configFile, $header . $body);
        return true;
    }

    /**
     * @param string|null $protocol
     * @param string|null $httpMode
     * @param string $namespace
     * @param string $class
     * @param string $file
     * @param string $snakeKey
     * @param string|null $listen
     * @return void
     */
    private function createProcessFile(
        ?string $protocol,
        ?string $httpMode,
        string $namespace,
        string $class,
        string $file,
        string $snakeKey,
        ?string $listen
    ): void {
        $this->ensureParentDir($file);
        $content = $this->renderProcessClass($protocol, $httpMode, $namespace, $class, $snakeKey, $listen);
        file_put_contents($file, $content);
    }

    /**
     * @param string|null $protocol
     * @param string|null $httpMode
     * @param string $namespace
     * @param string $class
     * @param string $snakeKey
     * @param string|null $listen
     * @return string
     */
    private function renderProcessClass(
        ?string $protocol,
        ?string $httpMode,
        string $namespace,
        string $class,
        string $snakeKey,
        ?string $listen
    ): string {
        $protocol = $protocol ?: 'none';

        // Non-listening or unknown: generate a minimal template.
        if ($protocol === 'none') {
            return $this->renderNonListeningProcess($namespace, $class);
        }

        if ($protocol === 'http' && $httpMode === 'custom') {
            return $this->renderCustomHttpProcess($namespace, $class);
        }

        if ($protocol === 'websocket') {
            return $this->renderWebsocketProcess($namespace, $class);
        }

        if ($protocol === 'udp') {
            return $this->renderUdpProcess($namespace, $class);
        }

        // tcp/unixsocket use TcpConnection template
        return $this->renderTcpProcess($namespace, $class);
    }

    private function renderNonListeningProcess(string $namespace, string $class): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use Workerman\\Worker;

class {$class}
{
    /**
     * Called when the worker process starts.
     *
     * @param Worker \$worker
     * @return void
     */
    public function onWorkerStart(Worker \$worker)
    {
        // TODO: Write your business logic here.
    }
}

PHP;
    }

    private function renderCustomHttpProcess(string $namespace, string $class): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use Workerman\\Connection\\TcpConnection;
use Workerman\\Protocols\\Http\\Request;
use Workerman\\Worker;

class {$class}
{
    /**
     * Called when the worker process starts.
     *
     * @param Worker \$worker
     * @return void
     */
    public function onWorkerStart(Worker \$worker)
    {
    }

    /**
     * Called when a HTTP request is received.
     *
     * @param TcpConnection \$connection
     * @param Request \$request
     * @return void
     */
    public function onMessage(TcpConnection \$connection, Request \$request)
    {
        \$connection->send('Hello $class');
    }
}

PHP;
    }

    private function renderTcpProcess(string $namespace, string $class): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use Workerman\\Connection\\TcpConnection;
use Workerman\\Worker;

class {$class}
{
    /**
     * Called when the worker process starts.
     *
     * @param Worker \$worker
     * @return void
     */
    public function onWorkerStart(Worker \$worker)
    {
    }

    /**
     * Called when a new connection is established.
     *
     * @param TcpConnection \$connection
     * @return void
     */
    public function onConnect(TcpConnection \$connection)
    {
    }

    /**
     * Called when a message is received from the client.
     *
     * @param TcpConnection \$connection
     * @param string \$data
     * @return void
     */
    public function onMessage(TcpConnection \$connection, string \$data)
    {
        \$connection->send(\$data);
    }

    /**
     * Called when the connection is closed.
     *
     * @param TcpConnection \$connection
     * @return void
     */
    public function onClose(TcpConnection \$connection)
    {
    }
}

PHP;
    }

    private function renderUdpProcess(string $namespace, string $class): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use Workerman\\Connection\\UdpConnection;
use Workerman\\Worker;

class {$class}
{
    /**
     * Called when the worker process starts.
     *
     * @param Worker \$worker
     * @return void
     */
    public function onWorkerStart(Worker \$worker)
    {
    }

    /**
     * Called when a message is received from the client.
     *
     * @param UdpConnection \$connection
     * @param string \$data
     * @return void
     */
    public function onMessage(UdpConnection \$connection, string \$data)
    {
        \$connection->send(\$data);
    }
}

PHP;
    }

    private function renderWebsocketProcess(string $namespace, string $class): string
    {
        return <<<PHP
<?php

namespace {$namespace};

use Workerman\\Connection\\TcpConnection;
use Workerman\\Protocols\\Http\\Request;
use Workerman\\Worker;

class {$class}
{
    /**
     * Called when the worker process starts.
     *
     * @param Worker \$worker
     * @return void
     */
    public function onWorkerStart(Worker \$worker)
    {
    }

    /**
     * Called when a new connection is established.
     *
     * @param TcpConnection \$connection
     * @return void
     */
    public function onConnect(TcpConnection \$connection)
    {
    }

    /**
     * Called when WebSocket handshake is completed.
     *
     * @param TcpConnection \$connection
     * @param Request \$request
     * @return void
     */
    public function onWebSocketConnect(TcpConnection \$connection, Request \$request)
    {        
    }

    /**
     * Called when a message is received from the client.
     *
     * @param TcpConnection \$connection
     * @param string \$data
     * @return void
     */
    public function onMessage(TcpConnection \$connection, string \$data)
    {
        \$connection->send(\$data);
    }

    /**
     * Called when the connection is closed.
     *
     * @param TcpConnection \$connection
     * @return void
     */
    public function onClose(TcpConnection \$connection)
    {
    }
}

PHP;
    }

    /**
     * @return string
     */
    protected function buildHelpText(): string
    {
        return Util::selectByLocale(Messages::getMakeProcessHelpText());
    }

    protected function msg(string $key, array $replace = []): string
    {
        return strtr(Util::selectLocaleMessages(Messages::getMakeProcessMessages())[$key] ?? $key, $replace);
    }
}
