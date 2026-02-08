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
        $this->addArgument('name', InputArgument::REQUIRED, 'Process class name, e.g. MyProcess');
        $this->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, 'Plugin name under plugin/. e.g. admin');
        $this->addOption('path', 'P', InputOption::VALUE_REQUIRED, 'Target directory (relative to base path). e.g. plugin/admin/app/process');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Override existing file without confirmation.');

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
    private function buildHelpText(): string
    {
        $zh = <<<'EOF'
交互式创建自定义进程，并自动写入对应的 process 配置。

推荐用法：
  php webman make:process MyProcess
  php webman make:process MyProcess -p admin
  php webman make:process MyProcess -P plugin/admin/app/process
  php webman make:process MyProcess -f

说明：
  - 会先把进程名转换为 snake 作为配置 key，例如 MyTcp => my_tcp。
  - 若配置 key 已存在，会提示已存在并显示 handler，然后退出。
  - 若需要生成进程类文件且文件已存在，会提示是否覆盖；使用 -f/--force 可直接覆盖。
  - 未指定 -p 时，如果 -P 指向 plugin/<name>/...，会自动推断写入 plugin/<name>/config/process.php。
EOF;
        $en = <<<'EOF'
Interactively create a custom process and append it into process config.

Recommended:
  php webman make:process MyProcess
  php webman make:process MyProcess -p admin
  php webman make:process MyProcess -P plugin/admin/app/process
  php webman make:process MyProcess -f

Notes:
  - The process name will be converted to snake_case as config key, e.g. MyTcp => my_tcp.
  - If the config key already exists, it will print the existing handler and exit.
  - If a process class file already exists, it will ask before overriding; use -f/--force to override directly.
  - If -p is not provided but -P points to plugin/<name>/..., it will infer the plugin name and write to plugin/<name>/config/process.php.
EOF;
        return Util::selectByLocale([
            'zh_CN' => $zh, 'zh_TW' => $zh, 'en' => $en,
            'ja' => "対話形式でカスタムプロセスを作成し、process 設定に追記。\n\n推奨：\n  php webman make:process MyProcess\n  php webman make:process MyProcess -p admin\n  php webman make:process MyProcess -P plugin/admin/app/process\n  php webman make:process MyProcess -f\n\n説明：\n  - プロセス名は snake_case に変換されて config key になります（例 MyTcp => my_tcp）。\n  - config key が既に存在する場合は既存 handler を表示して終了。\n  - プロセスクラスファイルが既にある場合は上書き確認；-f/--force で直接上書き。\n  - -p を指定せず -P が plugin/<name>/... の場合はプラグイン名を推定し plugin/<name>/config/process.php に書き込み。",
            'ko' => "대화형으로 커스텀 프로세스를 만들고 process 설정에 추가.\n\n권장:\n  php webman make:process MyProcess\n  php webman make:process MyProcess -p admin\n  php webman make:process MyProcess -P plugin/admin/app/process\n  php webman make:process MyProcess -f\n\n참고:\n  - 프로세스 이름은 snake_case로 변환되어 config key가 됩니다(예: MyTcp => my_tcp).\n  - config key가 이미 있으면 기존 handler를 출력하고 종료.\n  - 프로세스 클래스 파일이 있으면 덮어쓸지 묻고, -f/--force로 직접 덮어쓰기.\n  - -p 없이 -P가 plugin/<name>/... 이면 플러그인 이름을 추론해 plugin/<name>/config/process.php에 기록.",
            'fr' => "Créer interactivement un processus personnalisé et l'ajouter à la config process.\n\nRecommandé :\n  php webman make:process MyProcess\n  php webman make:process MyProcess -p admin\n  php webman make:process MyProcess -P plugin/admin/app/process\n  php webman make:process MyProcess -f\n\nNotes :\n  - Le nom du processus est converti en snake_case comme clé de config (ex. MyTcp => my_tcp).\n  - Si la clé existe déjà, affiche le handler existant et quitte.\n  - Si le fichier de classe existe, demande avant d'écraser ; -f/--force pour écraser directement.\n  - Sans -p, si -P pointe vers plugin/<name>/..., déduit le plugin et écrit dans plugin/<name>/config/process.php.",
            'de' => "Interaktiv einen benutzerdefinierten Prozess anlegen und in die process-Config eintragen.\n\nEmpfohlen:\n  php webman make:process MyProcess\n  php webman make:process MyProcess -p admin\n  php webman make:process MyProcess -P plugin/admin/app/process\n  php webman make:process MyProcess -f\n\nHinweise:\n  - Prozessname wird als Config-Key in snake_case umgewandelt (z. B. MyTcp => my_tcp).\n  - Wenn der Key bereits existiert, wird der bestehende Handler ausgegeben und beendet.\n  - Bei existierender Prozessklassendatei wird vor Überschreiben gefragt; -f/--force überschreibt direkt.\n  - Ohne -p, wenn -P auf plugin/<name>/... zeigt, wird der Plugin-Name erkannt und plugin/<name>/config/process.php geschrieben.",
            'es' => "Crear de forma interactiva un proceso personalizado y añadirlo a la config process.\n\nRecomendado:\n  php webman make:process MyProcess\n  php webman make:process MyProcess -p admin\n  php webman make:process MyProcess -P plugin/admin/app/process\n  php webman make:process MyProcess -f\n\nNotas:\n  - El nombre del proceso se convierte a snake_case como clave de config (ej. MyTcp => my_tcp).\n  - Si la clave ya existe, muestra el handler existente y sale.\n  - Si el archivo de clase ya existe, pregunta antes de sobrescribir; -f/--force sobrescribe directamente.\n  - Sin -p, si -P apunta a plugin/<name>/..., infiere el plugin y escribe en plugin/<name>/config/process.php.",
            'pt_BR' => "Criar interativamente um processo personalizado e adicionar à config process.\n\nRecomendado:\n  php webman make:process MyProcess\n  php webman make:process MyProcess -p admin\n  php webman make:process MyProcess -P plugin/admin/app/process\n  php webman make:process MyProcess -f\n\nNotas:\n  - O nome do processo é convertido para snake_case como chave de config (ex. MyTcp => my_tcp).\n  - Se a chave já existir, imprime o handler existente e sai.\n  - Se o arquivo da classe já existir, pergunta antes de sobrescrever; -f/--force sobrescreve diretamente.\n  - Sem -p, se -P apontar para plugin/<name>/..., infere o plugin e grava em plugin/<name>/config/process.php.",
            'ru' => "Интерактивно создать пользовательский процесс и добавить в config process.\n\nРекомендуется:\n  php webman make:process MyProcess\n  php webman make:process MyProcess -p admin\n  php webman make:process MyProcess -P plugin/admin/app/process\n  php webman make:process MyProcess -f\n\nПримечания:\n  - Имя процесса преобразуется в snake_case как ключ config (напр. MyTcp => my_tcp).\n  - Если ключ уже существует, выводится существующий handler и выход.\n  - Если файл класса уже есть, запрашивается подтверждение перезаписи; -f/--force перезаписывает сразу.\n  - Без -p при -P вида plugin/<name>/... подставляется имя плагина и запись в plugin/<name>/config/process.php.",
            'vi' => "Tạo process tùy chỉnh theo tương tác và ghi vào config process.\n\nKhuyến nghị:\n  php webman make:process MyProcess\n  php webman make:process MyProcess -p admin\n  php webman make:process MyProcess -P plugin/admin/app/process\n  php webman make:process MyProcess -f\n\nLưu ý:\n  - Tên process được chuyển thành snake_case làm config key (vd. MyTcp => my_tcp).\n  - Nếu config key đã tồn tại sẽ in handler hiện có rồi thoát.\n  - Nếu file lớp process đã có sẽ hỏi trước khi ghi đè; -f/--force ghi đè trực tiếp.\n  - Không dùng -p mà -P trỏ tới plugin/<name>/... thì suy ra tên plugin và ghi vào plugin/<name>/config/process.php.",
            'tr' => "Etkileşimli özel proses oluştur ve process config'e ekle.\n\nÖnerilen:\n  php webman make:process MyProcess\n  php webman make:process MyProcess -p admin\n  php webman make:process MyProcess -P plugin/admin/app/process\n  php webman make:process MyProcess -f\n\nNotlar:\n  - Proses adı config key olarak snake_case'e dönüştürülür (örn. MyTcp => my_tcp).\n  - Key zaten varsa mevcut handler yazdırılır ve çıkılır.\n  - Proses sınıf dosyası varsa üzerine yazmadan önce sorar; -f/--force doğrudan üzerine yazar.\n  - -p verilmez ve -P plugin/<name>/... ise plugin adı çıkarılıp plugin/<name>/config/process.php'ye yazılır.",
            'id' => "Buat proses kustom secara interaktif dan tambahkan ke config process.\n\nDirekomendasikan:\n  php webman make:process MyProcess\n  php webman make:process MyProcess -p admin\n  php webman make:process MyProcess -P plugin/admin/app/process\n  php webman make:process MyProcess -f\n\nCatatan:\n  - Nama proses dikonversi ke snake_case sebagai config key (mis. MyTcp => my_tcp).\n  - Jika key sudah ada, cetak handler yang ada dan keluar.\n  - Jika file kelas proses sudah ada akan ditanya sebelum menimpa; -f/--force menimpa langsung.\n  - Tanpa -p, jika -P mengarah ke plugin/<name>/..., infer nama plugin dan tulis ke plugin/<name>/config/process.php.",
            'th' => "สร้าง process แบบกำหนดเองแบบโต้ตอบและเขียนลง config process\n\nแนะนำ:\n  php webman make:process MyProcess\n  php webman make:process MyProcess -p admin\n  php webman make:process MyProcess -P plugin/admin/app/process\n  php webman make:process MyProcess -f\n\nหมายเหตุ:\n  - ชื่อ process จะถูกแปลงเป็น snake_case เป็น config key (เช่น MyTcp => my_tcp)\n  - ถ้า config key มีอยู่แล้วจะแสดง handler ที่มีและออก\n  - ถ้ามีไฟล์คลาสอยู่แล้วจะถามก่อนเขียนทับ -f/--force เขียนทับโดยตรง\n  - ถ้าไม่ใส่ -p แต่ -P ชี้ไป plugin/<name>/... จะสรุปชื่อปลั๊กอินและเขียนไปที่ plugin/<name>/config/process.php",
        ]);
    }

    /**
     * CLI messages (multilingual).
     *
     * @param string $key
     * @param array $replace
     * @return string
     */
    private function msg(string $key, array $replace = []): string
    {
        $zh = [
            'make_process' => "<info>创建进程</info> <comment>{class}</comment>\n<info>配置 key：</info> <comment>{key}</comment>\n<info>配置文件：</info> <comment>{config}</comment>",
            'invalid_plugin' => '<error>插件名无效：{plugin}。`--plugin/-p` 只能是 plugin/ 目录下的目录名，不能包含 / 或 \\。</error>',
            'plugin_path_conflict' => "<error>`--plugin/-p` 与 `--path/-P` 同时指定且不一致。\n期望路径：{expected}\n实际路径：{actual}\n请二选一或保持一致。</error>",
            'invalid_path' => '<error>路径无效：{path}。`--path/-P` 必须是相对路径（相对于项目根目录），不能是绝对路径。</error>',
            'invalid_config' => '<error>无法读取配置文件（必须 return 数组）：{path}</error>',
            'config_key_exists' => "<error>进程配置已存在：</error> <comment>{key}</comment>\n<info>handler：</info> <comment>{handler}</comment>\n<info>文件：</info> <comment>{path}</comment>",
            'ask_listen' => "<question>是否监听端口？</question> [y/N]（回车=N）\n",
            'ask_protocol' => "<question>请选择协议</question>（可输入数字或协议名）\n  1) websocket  2) http  3) tcp  4) udp  5) unixsocket\n> ",
            'ask_http_mode' => "<question>HTTP 进程类型</question>\n  1) 新增 webman 内置 http 进程（复用 app\\process\\Http，不创建新文件）\n  2) 自定义 http 进程（生成进程类文件）\n> ",
            'ask_ip' => "<question>请选择监听地址</question>（可输入数字或手动输入 IP）",
            'ask_ip_options' => "{options}\n> ",
            'ip_lan_suffix' => '（本机内网）',
            'ip_wan_suffix' => '（本机外网）',
            'ip_manual_hint' => '也可直接输入 IP',
            'ask_port' => "<question>请输入端口</question>\n> ",
            'ask_unixsocket' => "<question>请输入 unixsocket 路径</question>（可输入完整 listen，如 unix:///tmp/a.sock）\n默认：{default}\n> ",
            'ask_count' => "<question>进程数</question>（回车=默认 {default}）\n> ",
            'process_file_exists' => "<error>进程文件已存在：</error> {path}",
            'override_prompt' => "<question>文件已存在：{path}</question>\n<question>是否覆盖？[Y/n]（回车=Y）</question>\n",
            'created' => '<info>已创建：</info> {path}',
            'reuse_builtin_http' => '<comment>[Info]</comment> 已选择复用内置 HTTP 进程：{handler}',
            'updated_config' => '<info>已写入配置：</info> {path}  <comment>({key})</comment>',
            'write_config_failed' => '<error>写入配置失败：</error> {path}',
            'err_invalid_protocol' => '协议无效，请输入 1-5 或协议名（websocket/http/tcp/udp/unixsocket）',
            'err_invalid_http_mode' => '选项无效，请输入 1 或 2（builtin/custom）',
            'err_invalid_ip' => 'IP 无效，请重新输入',
            'err_invalid_port' => '端口无效，请输入 1-65535 的整数',
            'err_invalid_port_range' => '端口范围必须在 1-65535',
            'err_invalid_unixsocket_path' => '路径不能为空',
            'err_invalid_count_int' => '进程数必须是整数',
            'err_invalid_count_min' => '进程数必须 >= 1',
        ];

        $en = [
            'make_process' => "<info>Make process</info> <comment>{class}</comment>\n<info>Config key:</info> <comment>{key}</comment>\n<info>Config file:</info> <comment>{config}</comment>",
            'invalid_plugin' => '<error>Invalid plugin name: {plugin}. `--plugin/-p` must be a directory name under plugin/ and must not contain / or \\.</error>',
            'plugin_path_conflict' => "<error>`--plugin/-p` and `--path/-P` are both provided but inconsistent.\nExpected: {expected}\nActual: {actual}\nPlease provide only one, or make them identical.</error>",
            'invalid_path' => '<error>Invalid path: {path}. `--path/-P` must be a relative path (to project root) and must not be an absolute path.</error>',
            'invalid_config' => '<error>Unable to read config file (must return an array): {path}</error>',
            'config_key_exists' => "<error>Process config already exists:</error> <comment>{key}</comment>\n<info>handler:</info> <comment>{handler}</comment>\n<info>file:</info> <comment>{path}</comment>",
            'ask_listen' => "<question>Listen on a port?</question> [y/N] (Enter = N)\n",
            'ask_protocol' => "<question>Select protocol</question> (number or name)\n  1) websocket  2) http  3) tcp  4) udp  5) unixsocket\n> ",
            'ask_http_mode' => "<question>HTTP process type</question>\n  1) Add built-in webman HTTP process (reuse app\\process\\Http, no new file)\n  2) Custom HTTP process (generate a new process class file)\n> ",
            'ask_ip' => "<question>Select listen address</question> (number or input IP manually)",
            'ask_ip_options' => "{options}\n> ",
            'ip_lan_suffix' => ' (LAN)',
            'ip_wan_suffix' => ' (WAN)',
            'ip_manual_hint' => 'Or type an IP directly',
            'ask_port' => "<question>Enter port</question>\n> ",
            'ask_unixsocket' => "<question>Enter unixsocket path</question> (you may input full listen like unix:///tmp/a.sock)\nDefault: {default}\n> ",
            'ask_count' => "<question>Process count</question> (Enter = default {default})\n> ",
            'process_file_exists' => "<error>Process file already exists:</error> {path}",
            'override_prompt' => "<question>File already exists: {path}</question>\n<question>Override? [Y/n] (Enter = Y)</question>\n",
            'created' => '<info>Created:</info> {path}',
            'reuse_builtin_http' => '<comment>[Info]</comment> Using built-in HTTP process: {handler}',
            'updated_config' => '<info>Config updated:</info> {path}  <comment>({key})</comment>',
            'write_config_failed' => '<error>Failed to write config:</error> {path}',
            'err_invalid_protocol' => 'Invalid protocol. Please input 1-5 or a protocol name (websocket/http/tcp/udp/unixsocket).',
            'err_invalid_http_mode' => 'Invalid option. Please input 1 or 2 (builtin/custom).',
            'err_invalid_ip' => 'Invalid IP address.',
            'err_invalid_port' => 'Invalid port. Please input an integer between 1 and 65535.',
            'err_invalid_port_range' => 'Port must be between 1 and 65535.',
            'err_invalid_unixsocket_path' => 'Path must not be empty.',
            'err_invalid_count_int' => 'Process count must be an integer.',
            'err_invalid_count_min' => 'Process count must be >= 1.',
        ];

        $map = Util::selectLocaleMessages([
            'zh_CN' => $zh, 'zh_TW' => $zh, 'en' => $en, 'ja' => $en, 'ko' => $en, 'fr' => $en,
            'de' => $en, 'es' => $en, 'pt_BR' => $en, 'ru' => $en, 'vi' => $en, 'tr' => $en,
            'id' => $en, 'th' => $en,
        ]);
        $text = $map[$key] ?? $key;
        return $replace ? strtr($text, $replace) : $text;
    }
}

