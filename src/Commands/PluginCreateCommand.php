<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Webman\Console\Util;
use Webman\Console\Commands\Concerns\PluginCommandHelpers;

#[AsCommand('plugin:create', 'Create plugin')]
class PluginCreateCommand extends Command
{
    use PluginCommandHelpers;

    /**
     * @return void
     */
    protected function configure()
    {
        // Do NOT use "-n": Symfony Console already reserves "-n" for "--no-interaction".
        $this->addArgument('name', InputArgument::OPTIONAL, 'Plugin name, e.g. foo/my-admin');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Plugin name, e.g. foo/my-admin');
        $this->setHelp($this->buildHelpText());
        $this->addUsage('foo/my-admin');
        $this->addUsage('--name foo/my-admin');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $nameArg = $this->normalizePluginName($input->getArgument('name'));
        $nameOpt = $this->normalizePluginName($input->getOption('name'));
        if ($nameArg && $nameOpt && $nameArg !== $nameOpt) {
            $output->writeln($this->pluginMsg('name_conflict', ['{arg}' => $nameArg, '{opt}' => $nameOpt]));
            return Command::FAILURE;
        }
        $nameRaw = $nameOpt ?: $nameArg;
        if (!$nameRaw) {
            $output->writeln($this->pluginMsg('missing_name'));
            return Command::FAILURE;
        }
        if (!$this->isValidComposerPackageName($nameRaw)) {
            $output->writeln($this->pluginMsg('bad_name', ['{name}' => (string)$nameRaw]));
            return Command::FAILURE;
        }

        $output->writeln($this->pluginMsg('create_title', ['{name}' => $nameRaw]));

        $namespace = Util::nameToNamespace($nameRaw);

        // Create dir config/plugin/$name
        if (is_dir($plugin_config_path = config_path()."/plugin/$nameRaw")) {
            $output->writeln($this->pluginMsg('dir_exists', ['{path}' => $this->toRelativePath($plugin_config_path)]));
            return Command::FAILURE;
        }

        if (is_dir($plugin_path = base_path()."/vendor/$nameRaw")) {
            $output->writeln($this->pluginMsg('dir_exists', ['{path}' => $this->toRelativePath($plugin_path)]));
            return Command::FAILURE;
        }

        // Add psr-4
        $output->writeln($this->pluginMsg('step_psr4', [
            '{key}' => rtrim($namespace, '\\') . '\\',
            '{path}' => "vendor/{$nameRaw}/src",
        ]));
        if ($err = $this->addAutoloadToComposerJson($nameRaw, $namespace)) {
            $output->writeln($this->pluginMsg('psr4_failed', ['{error}' => $err]));
            return Command::FAILURE;
        }
        $output->writeln($this->pluginMsg('psr4_ok'));

        $output->writeln($this->pluginMsg('step_config', ['{path}' => $this->toRelativePath($plugin_config_path)]));
        if ($err = $this->createConfigFiles($plugin_config_path)) {
            $output->writeln($this->pluginMsg('create_failed', ['{error}' => $err]));
            return Command::FAILURE;
        }
        $output->writeln($this->pluginMsg('created', ['{path}' => $this->toRelativePath($plugin_config_path . '/app.php')]));

        $output->writeln($this->pluginMsg('step_vendor', ['{path}' => $this->toRelativePath($plugin_path)]));
        if ($err = $this->createVendorFiles($nameRaw, $namespace, $plugin_path, $output)) {
            $output->writeln($this->pluginMsg('create_failed', ['{error}' => $err]));
            return Command::FAILURE;
        }

        $output->writeln($this->pluginMsg('done', ['{name}' => $nameRaw]));
        return Command::SUCCESS;
    }

    /**
     * @param string $name
     * @param string $namespace
     * @return string|null error message
     */
    protected function addAutoloadToComposerJson(string $name, string $namespace): ?string
    {
        if (!is_file($composer_json_file = base_path()."/composer.json")) {
            return "$composer_json_file not exists";
        }
        $composer_json_str = file_get_contents($composer_json_file);
        if (!is_string($composer_json_str) || $composer_json_str === '') {
            return "Bad $composer_json_file";
        }
        $composer_json = json_decode($composer_json_str, true);
        if (!$composer_json) {
            return "Bad $composer_json_file";
        }

        $psr4Key = rtrim($namespace, '\\') . "\\";
        $psr4Path = "vendor/$name/src";

        if (isset($composer_json['autoload']['psr-4'][$psr4Key])) {
            return null;
        }

        // Prefer surgical insertion to avoid rewriting whole composer.json format.
        $line = json_encode($psr4Key, JSON_UNESCAPED_SLASHES) . ': ' . json_encode($psr4Path, JSON_UNESCAPED_SLASHES) . ",\n";
        $pattern = '/("psr-4"\s*:\s*\{\s*\n)(\s*)"/';
        if (preg_match($pattern, $composer_json_str)) {
            $patched = preg_replace_callback($pattern, static function ($m) use ($line) {
                return $m[1] . $m[2] . $line . $m[2] . '"';
            }, $composer_json_str, 1);
            if (is_string($patched) && json_decode($patched, true)) {
                file_put_contents($composer_json_file, $patched);
                return null;
            }
        }

        // Fallback: rewrite composer.json (stable, but may change formatting).
        if (!isset($composer_json['autoload']) || !is_array($composer_json['autoload'])) {
            $composer_json['autoload'] = [];
        }
        if (!isset($composer_json['autoload']['psr-4']) || !is_array($composer_json['autoload']['psr-4'])) {
            $composer_json['autoload']['psr-4'] = [];
        }
        $composer_json['autoload']['psr-4'] = [$psr4Key => $psr4Path] + $composer_json['autoload']['psr-4'];
        $encoded = json_encode($composer_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            return "Bad $composer_json_file";
        }
        file_put_contents($composer_json_file, $encoded . "\n");
        return null;
    }

    /**
     * @param string $plugin_config_path
     * @return string|null error message
     */
    protected function createConfigFiles(string $plugin_config_path): ?string
    {
        if (!mkdir($plugin_config_path, 0777, true) && !is_dir($plugin_config_path)) {
            return "Unable to create directory {$plugin_config_path}";
        }
        $app_str = <<<EOF
<?php
return [
    'enable' => true,
];
EOF;
        $ret = file_put_contents("$plugin_config_path/app.php", $app_str);
        if ($ret === false) {
            return "Unable to write file {$plugin_config_path}/app.php";
        }
        return null;
    }

    /**
     * @param string $name
     * @param string $namespace
     * @param string $plugin_path
     * @param OutputInterface $output
     * @return string|null error message
     */
    protected function createVendorFiles(string $name, string $namespace, string $plugin_path, OutputInterface $output): ?string
    {
        if (!mkdir("$plugin_path/src", 0777, true) && !is_dir("$plugin_path/src")) {
            return "Unable to create directory {$plugin_path}/src";
        }
        if (!$this->createComposerJson($name, $namespace, $plugin_path)) {
            return "Unable to write file {$plugin_path}/composer.json";
        }
        $output->writeln($this->pluginMsg('created', ['{path}' => $this->toRelativePath($plugin_path . '/composer.json')]));

        if (!is_callable('exec')) {
            $output->writeln($this->pluginMsg('dumpautoload_manual'));
            return null;
        }
        $cmd = "composer dumpautoload";
        $lines = [];
        $code = 0;
        exec($cmd, $lines, $code);
        if ($code !== 0) {
            $output->writeln($this->pluginMsg('dumpautoload_failed', ['{cmd}' => $cmd]));
            return null;
        }
        $output->writeln($this->pluginMsg('dumpautoload_ok'));
        return null;
    }

    /**
     * @param string $name
     * @param string $namespace
     * @param string $dest
     * @return bool
     */
    protected function createComposerJson(string $name, string $namespace, string $dest): bool
    {
        $namespace = str_replace('\\', '\\\\', $namespace);
        $composer_json_content = <<<EOT
{
  "name": "$name",
  "type": "library",
  "license": "MIT",
  "description": "Webman plugin $name",
  "require": {
  },
  "autoload": {
    "psr-4": {
      "$namespace\\\\": "src"
    }
  }
}
EOT;
        return file_put_contents("$dest/composer.json", $composer_json_content) !== false;
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
创建一个 Webman 插件骨架（composer 包形式）。

用法：
  php webman plugin:create foo/my-admin
  php webman plugin:create --name foo/my-admin

说明：
  - 插件名必须是 composer 包名：vendor/name（全小写）。
  - 会创建目录：
      - config/plugin/<vendor>/<name>
      - vendor/<vendor>/<name>/src
  - 会在项目 composer.json 的 autoload.psr-4 中追加命名空间映射，并尝试执行 `composer dumpautoload`。
EOF;
        }

        return <<<'EOF'
Create a Webman plugin skeleton (as a composer package).

Usage:
  php webman plugin:create foo/my-admin
  php webman plugin:create --name foo/my-admin

Notes:
  - Plugin name must be a composer package name: vendor/name (lowercase).
  - It will create:
      - config/plugin/<vendor>/<name>
      - vendor/<vendor>/<name>/src
  - It will append a PSR-4 mapping into project composer.json and try to run `composer dumpautoload`.
EOF;
    }
}
