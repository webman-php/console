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

#[AsCommand('plugin:export', 'Plugin export')]
class PluginExportCommand extends Command
{
    use PluginCommandHelpers;

    /**
     * @return void
     */
    protected function configure()
    {
        // Do NOT use "-n": Symfony Console already reserves "-n" for "--no-interaction".
        $this->addArgument('name', InputArgument::OPTIONAL, 'Plugin name, for example foo/my-admin');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Plugin name, for example foo/my-admin');
        $this->addOption('source', 's', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Directories to export');
        $this->setHelp($this->buildHelpText());
        $this->addUsage('foo/my-admin --source app --source config');
        $this->addUsage('--name foo/my-admin --source app --source config');
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
            return Command::INVALID;
        }
        if (!$this->isValidComposerPackageName($nameRaw)) {
            $output->writeln($this->pluginMsg('bad_name', ['{name}' => (string)$nameRaw]));
            return Command::INVALID;
        }

        $output->writeln($this->pluginMsg('export_title', ['{name}' => $nameRaw]));

        $namespace = Util::nameToNamespace($nameRaw);

        $pathRelations = $input->getOption('source');
        $pathRelations = is_array($pathRelations) ? $pathRelations : [];
        $pathRelations = array_values(array_filter(array_map('trim', $pathRelations), static fn($v) => $v !== ''));

        $pluginConfigDir = "config/plugin/{$nameRaw}";
        if (!in_array($pluginConfigDir, $pathRelations, true) && is_dir($pluginConfigDir)) {
            $pathRelations[] = $pluginConfigDir;
        }

        $originalDest = base_path() . "/vendor/{$nameRaw}";
        $dest = $originalDest . '/src';

        $this->writeInstallFile($namespace, $pathRelations, $dest);
        $output->writeln($this->pluginMsg('export_install_created', ['{path}' => $this->toRelativePath($dest . '/Install.php')]));

        foreach ($pathRelations as $source) {
            $source = $this->normalizeRelativePath((string)$source);
            if ($source === '' || (!is_dir($source) && !is_file($source))) {
                $output->writeln($this->pluginMsg('export_skip_missing', ['{path}' => $source]));
                continue;
            }
            $basePath = pathinfo("{$dest}/{$source}", PATHINFO_DIRNAME);
            if (!is_dir($basePath)) {
                mkdir($basePath, 0777, true);
            }
            $output->writeln($this->pluginMsg('export_copy', [
                '{src}' => $source,
                '{dest}' => $this->toRelativePath("{$dest}/{$source}"),
            ]));
            copy_dir($source, "{$dest}/{$source}");
        }

        $output->writeln($this->pluginMsg('export_saved', [
            '{name}' => $nameRaw,
            '{dest}' => $this->toRelativePath($originalDest),
        ]));
        return Command::SUCCESS;
    }

    /**
     * @param $namespace
     * @param $path_relations
     * @param $dest_dir
     * @return void
     */
    protected function writeInstallFile($namespace, $path_relations, $dest_dir)
    {
        if (!is_dir($dest_dir)) {
           mkdir($dest_dir, 0777, true);
        }
        $relations = [];
        foreach($path_relations as $relation) {
            $relations[$relation] = $relation;
        }
        $relations = var_export($relations, true);
        $install_php_content = <<<EOT
<?php
namespace $namespace;

class Install
{
    const WEBMAN_PLUGIN = true;

    /**
     * @var array
     */
    protected static \$pathRelation = $relations;

    /**
     * Install
     * @return void
     */
    public static function install()
    {
        static::installByRelation();
    }

    /**
     * Uninstall
     * @return void
     */
    public static function uninstall()
    {
        self::uninstallByRelation();
    }

    /**
     * installByRelation
     * @return void
     */
    public static function installByRelation()
    {
        foreach (static::\$pathRelation as \$source => \$dest) {
            if (\$pos = strrpos(\$dest, '/')) {
                \$parent_dir = base_path().'/'.substr(\$dest, 0, \$pos);
                if (!is_dir(\$parent_dir)) {
                    mkdir(\$parent_dir, 0777, true);
                }
            }
            //symlink(__DIR__ . "/\$source", base_path()."/\$dest");
            copy_dir(__DIR__ . "/\$source", base_path()."/\$dest");
            echo "Create \$dest\r\n";
        }
    }

    /**
     * uninstallByRelation
     * @return void
     */
    public static function uninstallByRelation()
    {
        foreach (static::\$pathRelation as \$source => \$dest) {
            \$path = base_path()."/\$dest";
            if (!is_dir(\$path) && !is_file(\$path)) {
                continue;
            }
            echo "Remove \$dest\r\n";
            if (is_file(\$path) || is_link(\$path)) {
                unlink(\$path);
                continue;
            }
            remove_dir(\$path);
        }
    }
    
}
EOT;
        file_put_contents("$dest_dir/Install.php", $install_php_content);
    }

    protected function buildHelpText(): string
    {
        $zh = <<<'EOF'
将指定目录打包导出到 vendor/<vendor>/<name>/src，并生成 Install.php（用于 plugin:install / plugin:uninstall）。

用法：
  php webman plugin:export foo/my-admin --source app --source config
  php webman plugin:export --name foo/my-admin --source app --source config

说明：
  - `--source/-s` 可重复多次指定要导出的目录/文件（相对项目根目录）。
  - 若存在 `config/plugin/<vendor>/<name>` 且未显式包含，会自动追加到导出列表。
EOF;
        $en = <<<'EOF'
Export directories into vendor/<vendor>/<name>/src and generate Install.php (for plugin:install / plugin:uninstall).

Usage:
  php webman plugin:export foo/my-admin --source app --source config
  php webman plugin:export --name foo/my-admin --source app --source config

Notes:
  - `--source/-s` can be provided multiple times (relative to project root).
  - If `config/plugin/<vendor>/<name>` exists and not provided, it will be appended automatically.
EOF;
        return Util::selectByLocale(['zh_CN' => $zh, 'en' => $en]);
    }

}
