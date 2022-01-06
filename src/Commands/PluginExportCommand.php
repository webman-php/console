<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class PluginExportCommand extends Command
{
    protected static $defaultName = 'plugin:export';
    protected static $defaultDescription = 'Plugin export';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addOption('name', 'name', InputOption::VALUE_REQUIRED, 'Plugin name, for example foo/my-admin');
        $this->addOption('dest', 'dest', InputOption::VALUE_REQUIRED, 'Location of plugin storage for export');
        $this->addOption('source', 'source', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Directories to export');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Export Plugin');
        $name = strtolower($input->getOption('name'));
        if (!strpos($name, '/')) {
            $output->writeln('<error>Bad name, name must contain character \'/\' , for example foo/MyAdmin</error>');
            return self::INVALID;
        }
        $namespace = $this->getNamespace($name);
        $path_relations = $input->getOption('source');
        $original_dest = $dest = $input->getOption('dest');
        if (!is_dir($dest)) {
            mkdir($dest, 0777, true);
        }
        $output->writeln("<info>Create $dest/composer.json</info>");
        $this->createComposerJson($name, $namespace, $dest);
        $dest .= '/src';
        $this->writeInstallFile($namespace, $path_relations, $dest);
        $output->writeln("<info>Create $dest/Install.php</info>");

        foreach ($input->getOption('source') as $source) {
            $base_path = pathinfo("$dest/$source", PATHINFO_DIRNAME);
            if (!is_dir($base_path)) {
                mkdir($base_path, 0777, true);
            }
            $output->writeln("<info>Copy $source to $dest/$source </info>");
            copy_dir($source, "$dest/$source");
        }
        $output->writeln("<info>Saved $name to $original_dest</info>");
        return self::SUCCESS;
    }

    /**
     * @param $name
     * @return string
     */
    protected function getNamespace($name)
    {
        $namespace = ucfirst($name);

        $namespace = preg_replace_callback(['/-([a-zA-Z])/', '/(\/[a-zA-Z])/'], function ($matches) {
            return strtoupper($matches[1]);
        }, $namespace);
        $namespace = str_replace('/', '\\' ,ucfirst($namespace));
        return $namespace;
    }

    /**
     * @param $name
     * @param $namespace
     * @param $dest
     * @return void
     */
    protected function createComposerJson($name, $namespace, $dest)
    {
        $namespace = str_replace('\\', '\\\\', $namespace);
        $composer_json_content = <<<EOT
{
  "name": "$name",
  "type": "library",
  "license": "MIT",
  "require": {
    "php": ">=7.2"
  },
  "autoload": {
    "psr-4": {
      "$namespace\\\\": "src"
    }
  }
}
EOT;
        file_put_contents("$dest/composer.json", $composer_json_content);
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
        }
    }

    /**
     * uninstallByRelation
     * @return void
     */
    public static function uninstallByRelation()
    {
        foreach (static::\$pathRelation as \$source => \$dest) {
            /*if (is_link(base_path()."/\$dest")) {
                unlink(base_path()."/\$dest");
            }*/
            remove_dir(base_path()."/\$dest");
        }
    }
}
EOT;
        file_put_contents("$dest_dir/Install.php", $install_php_content);
    }
}
