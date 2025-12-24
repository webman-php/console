<?php

namespace Webman\Console\Commands;

use Phar;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('build:phar', 'Can be easily packaged a project into phar files. Easy to distribute and use.')]
class BuildPharCommand extends Command
{
    protected string $pharFileName;

    protected string $buildDir;

    protected int $pharFormat;

    protected int $pharCompression;

    public function __construct()
    {
        parent::__construct();
        $this->pharFileName = config('plugin.webman.console.app.phar_filename', 'webman.phar');
        $this->buildDir = rtrim(config('plugin.webman.console.app.build_dir', base_path() . '/build'), DIRECTORY_SEPARATOR);
        $this->pharFormat = config('plugin.webman.console.app.phar_format', Phar::PHAR);
        $this->pharCompression = config('plugin.webman.console.app.phar_compression', Phar::NONE);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->checkEnv();
        if (!file_exists($this->buildDir) && !is_dir($this->buildDir)) {
            if (!mkdir($this->buildDir,0777,true)) {
                throw new RuntimeException("Failed to create phar file output directory. Please check the permission.");
            }
        }

        $phar_file = $this->buildDir . DIRECTORY_SEPARATOR . $this->getPharFileName();
        if (file_exists($phar_file)) {
            unlink($phar_file);
        }

        $exclude_pattern = config('plugin.webman.console.app.exclude_pattern','');

        $phar = new Phar($this->buildDir . DIRECTORY_SEPARATOR . $this->pharFileName,0 , 'webman');
        if(!str_ends_with($this->getPharFileName(), '.phar')) {
            $phar = $phar->convertToExecutable($this->pharFormat, $this->pharCompression);
        }

        $phar->startBuffering();

        $signature_algorithm = config('plugin.webman.console.app.signature_algorithm');
        if (!in_array($signature_algorithm,[Phar::MD5, Phar::SHA1, Phar::SHA256, Phar::SHA512,Phar::OPENSSL])) {
            throw new RuntimeException('The signature algorithm must be one of Phar::MD5, Phar::SHA1, Phar::SHA256, Phar::SHA512, or Phar::OPENSSL.');
        }
        if ($signature_algorithm === Phar::OPENSSL) {
            $private_key_file = config('plugin.webman.console.app.private_key_file');
            if (!file_exists($private_key_file)) {
                throw new RuntimeException("If the value of the signature algorithm is 'Phar::OPENSSL', you must set the private key file.");
            }
            $private = openssl_get_privatekey(file_get_contents($private_key_file));
            $pkey = '';
            openssl_pkey_export($private, $pkey);
            !$phar->getSignature() && $phar->setSignatureAlgorithm($signature_algorithm, $pkey);
        } else {
            !$phar->getSignature() && $phar->setSignatureAlgorithm($signature_algorithm);
        }

        $phar->buildFromDirectory(BASE_PATH,$exclude_pattern);


        $exclude_files = config('plugin.webman.console.app.exclude_files',[]);
        // 打包生成的phar和bin文件是面向生产环境的，所以以下这些命令没有任何意义，执行的话甚至会出错，需要排除在外。
        $exclude_command_files = [
            'AppPluginCreateCommand.php',
            'BuildBinCommand.php',
            'BuildPharCommand.php',
            'MakeBootstrapCommand.php',
            'MakeCommandCommand.php',
            'MakeControllerCommand.php',
            'MakeMiddlewareCommand.php',
            'MakeModelCommand.php',
            'PluginCreateCommand.php',
            'PluginDisableCommand.php',
            'PluginEnableCommand.php',
            'PluginExportCommand.php',
            'PluginInstallCommand.php',
            'PluginUninstallCommand.php'
        ];
        $exclude_command_files = array_map(function ($cmd_file) {
            return 'vendor/webman/console/src/Commands/'.$cmd_file;
        },$exclude_command_files);
        $exclude_files = array_unique(array_merge($exclude_command_files,$exclude_files));
        foreach ($exclude_files as $file) {
            if($phar->offsetExists($file)){
                $phar->delete($file);
            }
        }

        if ($this->pharCompression != Phar::NONE) {
            $phar->addFromString('vendor/composer/ClassLoader.php', $this->getClassLoaderContents());
            $phar->addFromString('/vendor/workerman/workerman/src/Worker.php', $this->getWorkerContents());
        }
        $output->writeln('Files collect complete, begin add file to Phar.');

        $phar->setStub("#!/usr/bin/env php
<?php
define('IN_PHAR', true);
Phar::mapPhar('webman');
require 'phar://webman/webman';
__HALT_COMPILER();
");

        $output->writeln('Write requests to the Phar archive, save changes to disk.');

        $phar->stopBuffering();

        unset($phar);
        return self::SUCCESS;
    }

    /**
     * @throws RuntimeException
     */
    public function checkEnv(): void
    {
        if (!class_exists(Phar::class, false)) {
            throw new RuntimeException("The 'phar' extension is required for build phar package");
        }

        if (ini_get('phar.readonly')) {
            $command = $this->getName();
            throw new RuntimeException(
                "The 'phar.readonly' is 'On', build phar must setting it 'Off' or exec with 'php -d phar.readonly=0 ./webman $command'"
            );
        }
    }

    public function getPharFileName(): string
    {
        $phar_filename = $this->pharFileName;
        if (empty($phar_filename)) {
            throw new RuntimeException('Please set the phar filename.');
        }
        $phar_filename .= match ($this->pharFormat) {
            Phar::TAR => '.tar',
            Phar::ZIP => 'zip',
            default => ''
        };
        $phar_filename .= match ($this->pharCompression) {
            Phar::GZ => '.gz',
            Phar::BZ2 => '.bz2',
            default => ''
        };
        return $phar_filename;
    }

    public function getClassLoaderContents(): string
    {
        $fileContents = file_get_contents(BASE_PATH . '/vendor/composer/ClassLoader.php');
        $replaceContents = <<<'PHP'
            if (str_starts_with($file, 'phar://')) {
                $lockFile = sys_get_temp_dir() . '/phar_' . md5($file) . '.lock';
                $fp = fopen($lockFile, 'c');
                flock($fp, LOCK_EX) && include $file;
                fclose($fp);
                file_exists($lockFile) && @unlink($lockFile);
            } else {
                include $file;
            }
PHP;
        return str_replace('            include $file;', $replaceContents, $fileContents);
    }
    public function getWorkerContents(): string
    {
        $fileContents = file_get_contents(BASE_PATH . '/vendor/workerman/workerman/src/Worker.php');
        $replaceContents = <<<'PHP'
        static::forkOneWorkerForLinux($worker); php_sapi_name() == 'micro' && usleep(50000);
        PHP;
        return str_replace('static::forkOneWorkerForLinux($worker);', $replaceContents, $fileContents);
    }
}
