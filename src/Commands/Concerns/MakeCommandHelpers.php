<?php

namespace Webman\Console\Commands\Concerns;

use Webman\Console\Util;

trait MakeCommandHelpers
{
    /**
     * Symfony short options on some environments may return value like "=foo" for "-p=foo".
     * Normalize to "foo".
     *
     * @param mixed $value
     * @return string|null
     */
    protected function normalizeOptionValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        $value = ltrim($value, '=');
        return $value === '' ? null : $value;
    }

    /**
     * Normalize a relative path to use "/" separators and no leading/trailing slashes.
     *
     * @param string $path
     * @return string
     */
    protected function normalizeRelativePath(string $path): string
    {
        $path = trim($path);
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#^\\./+#', '', $path);
        $path = trim($path, '/');
        return $path;
    }

    /**
     * @param string $path
     * @return bool
     */
    protected function isAbsolutePath(string $path): bool
    {
        $path = trim($path);
        if ($path === '') {
            return false;
        }
        // Windows drive letter, UNC path, or root slash.
        if (preg_match('/^[a-zA-Z]:[\\\\\\/]/', $path)) {
            return true;
        }
        if (str_starts_with($path, '\\\\') || str_starts_with($path, '//')) {
            return true;
        }
        return str_starts_with($path, '/') || str_starts_with($path, '\\');
    }

    /**
     * Compare two relative paths on Windows-friendly rules.
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    protected function pathsEqual(string $a, string $b): bool
    {
        $a = strtolower($this->normalizeRelativePath($a));
        $b = strtolower($this->normalizeRelativePath($b));
        return $a === $b;
    }

    /**
     * Convert an absolute path to a workspace-relative path for nicer CLI output.
     *
     * @param string $path
     * @return string
     */
    protected function toRelativePath(string $path): string
    {
        $base = base_path();
        $baseNorm = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $base), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $pathNorm = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (str_starts_with(strtolower($pathNorm), strtolower($baseNorm))) {
            $rel = substr($pathNorm, strlen($baseNorm));
        } else {
            $rel = $pathNorm;
        }
        // Use forward slashes for nicer CLI output.
        return str_replace(DIRECTORY_SEPARATOR, '/', $rel);
    }

    /**
     * Get current locale for CLI messages. Delegates to Util::getLocale().
     *
     * @return string
     */
    protected function getLocale(): string
    {
        return Util::getLocale();
    }

    /**
     * Resolve namespace/file path by --plugin/-p or --path/-P.
     * - --path/-P: must be a relative path (to project root).
     * - If both are provided, they must point to the same directory, otherwise it's an error.
     *
     * @param string $name Name like "Admin/User"
     * @param string|null $plugin
     * @param string|null $path
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param callable(string):string $pluginDefaultPathResolver Returns relative dir like "plugin/admin/app/controller"
     * @param callable(string,array<string,string>):string $msg Message resolver: fn($key,$replace)=>string
     * @return array{0:string,1:string,2:string}|null [class, namespace, file]
     */
    protected function resolveTargetByPluginOrPath(
        string $name,
        ?string $plugin,
        ?string $path,
        \Symfony\Component\Console\Output\OutputInterface $output,
        callable $pluginDefaultPathResolver,
        callable $msg
    ): ?array {
        $pathNorm = $path ? $this->normalizeRelativePath($path) : null;
        if ($pathNorm !== null && $this->isAbsolutePath($pathNorm)) {
            $output->writeln($msg('invalid_path', ['{path}' => (string)$path]));
            return null;
        }

        $expected = null;
        if ($plugin) {
            $expected = $pluginDefaultPathResolver($plugin);
        }

        if ($expected && $pathNorm) {
            if (!$this->pathsEqual($expected, $pathNorm)) {
                $output->writeln($msg('plugin_path_conflict', [
                    '{expected}' => $expected,
                    '{actual}' => $pathNorm,
                ]));
                return null;
            }
        }

        $targetRel = $pathNorm ?: $expected;
        if (!$targetRel) {
            return null;
        }

        $targetDir = base_path($targetRel);
        $namespaceRoot = trim(str_replace('/', '\\', $targetRel), '\\');

        if (!($pos = strrpos($name, '/'))) {
            $class = ucfirst($name);
            $subPath = '';
        } else {
            $subPath = substr($name, 0, $pos);
            $class = ucfirst(substr($name, $pos + 1));
        }

        $subDir = $subPath ? str_replace('/', DIRECTORY_SEPARATOR, $subPath) . DIRECTORY_SEPARATOR : '';
        $file = $targetDir . DIRECTORY_SEPARATOR . $subDir . $class . '.php';
        $namespace = $namespaceRoot . ($subPath ? '\\' . str_replace('/', '\\', $subPath) : '');

        return [$class, $namespace, $file];
    }

    /**
     * Load a php config file (return array).
     *
     * @param string $file
     * @return array|null null when file exists but does not return an array or cannot be included
     */
    protected function loadPhpConfigArray(string $file): ?array
    {
        if (!is_file($file)) {
            return [];
        }
        try {
            $data = include $file;
        } catch (\Throwable) {
            return null;
        }
        return is_array($data) ? $data : null;
    }

    /**
     * Get a simple PHP header, preserving the top docblock if present.
     *
     * @param string $file
     * @return string
     */
    protected function getPhpHeaderWithDocblock(string $file): string
    {
        $default = "<?php\n\n";
        if (!is_file($file)) {
            return $default;
        }
        $content = file_get_contents($file);
        if (!is_string($content) || $content === '') {
            return $default;
        }
        if (preg_match('/\A<\?php\s*(\/\*\*[\s\S]*?\*\/\s*)/i', $content, $m)) {
            $doc = rtrim($m[1]) . "\n\n";
            return "<?php\n" . $doc;
        }
        return $default;
    }

    /**
     * Ensure parent directory exists.
     *
     * @param string $file
     * @return void
     */
    protected function ensureParentDir(string $file): void
    {
        $dir = pathinfo($file, PATHINFO_DIRNAME);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    /**
     * Add a class to a flat config list (return [ClassA::class, ...]).
     *
     * @param string $file
     * @param string $classFqn e.g. app\bootstrap\Test
     * @return bool changed or not
     */
    protected function addClassToFlatClassListConfig(string $file, string $classFqn): bool
    {
        $config = $this->loadPhpConfigArray($file);
        if ($config === null) {
            return false;
        }
        $classFqn = ltrim(trim($classFqn), '\\');
        if (in_array($classFqn, $config, true)) {
            return false;
        }
        $config[] = $classFqn;
        $this->ensureParentDir($file);
        $header = $this->getPhpHeaderWithDocblock($file);
        $body = $this->renderFlatClassListConfig($config);
        file_put_contents($file, $header . $body);
        return true;
    }

    /**
     * Add a class to middleware config under empty key ''.
     * Return value format:
     * return [
     *     '' => [
     *         Foo::class,
     *     ],
     * ];
     *
     * @param string $file
     * @param string $classFqn e.g. app\middleware\StaticFile
     * @return bool changed or not
     */
    protected function addClassToMiddlewareConfig(string $file, string $classFqn): bool
    {
        $config = $this->loadPhpConfigArray($file);
        if ($config === null) {
            return false;
        }
        $classFqn = ltrim(trim($classFqn), '\\');

        if (!array_key_exists('', $config)) {
            // Put '' first for readability.
            $config = ['' => []] + $config;
        }
        $list = $config[''];
        if (!is_array($list)) {
            $list = [];
        }
        if (in_array($classFqn, $list, true)) {
            return false;
        }
        $list[] = $classFqn;
        $config[''] = $list;

        $this->ensureParentDir($file);
        $header = $this->getPhpHeaderWithDocblock($file);
        $body = $this->renderMiddlewareConfig($config);
        file_put_contents($file, $header . $body);
        return true;
    }

    /**
     * @param array<int,string> $classes
     * @return string
     */
    protected function renderFlatClassListConfig(array $classes): string
    {
        $lines = [];
        $lines[] = "return [";
        foreach ($classes as $c) {
            $c = ltrim((string)$c, '\\');
            if ($c === '') {
                continue;
            }
            $lines[] = "    {$c}::class,";
        }
        $lines[] = "];\n";
        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $config
     * @return string
     */
    protected function renderMiddlewareConfig(array $config): string
    {
        $lines = [];
        $lines[] = "return [";
        foreach ($config as $key => $value) {
            $keyExport = var_export((string)$key, true);
            $lines[] = "    {$keyExport} => [";
            $list = is_array($value) ? $value : [];
            foreach ($list as $c) {
                $c = ltrim((string)$c, '\\');
                if ($c === '') {
                    continue;
                }
                $lines[] = "        {$c}::class,";
            }
            $lines[] = "    ],";
        }
        $lines[] = "];\n";
        return implode("\n", $lines);
    }
}

