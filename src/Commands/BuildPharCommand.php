<?php

namespace Webman\Console\Commands;

use Phar;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webman\Console\Commands\Concerns\MakeCommandHelpers;
use Webman\Console\Util;

#[AsCommand('build:phar', 'Can be easily packaged a project into phar files. Easy to distribute and use.')]
class BuildPharCommand extends Command
{
    use MakeCommandHelpers;

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
                throw new RuntimeException($this->err('mkdir_build_dir_failed'));
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
            throw new RuntimeException($this->err('bad_signature_algorithm'));
        }
        if ($signature_algorithm === Phar::OPENSSL) {
            $private_key_file = config('plugin.webman.console.app.private_key_file');
            if (!file_exists($private_key_file)) {
                throw new RuntimeException($this->err('openssl_private_key_missing'));
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
        $output->writeln($this->msg('collect_complete'));

        $phar->setStub("#!/usr/bin/env php
<?php
define('IN_PHAR', true);
Phar::mapPhar('webman');
require 'phar://webman/webman';
__HALT_COMPILER();
");

        $output->writeln($this->msg('write_to_disk'));

        $phar->stopBuffering();

        unset($phar);
        return self::SUCCESS;
    }

    protected function msg(string $key, array $replace = []): string
    {
        $zh = [
            'collect_complete' => '<info>文件收集完成</info> <comment>开始写入 Phar...</comment>',
            'write_to_disk' => '<info>写入 Phar 归档</info> <comment>并保存到磁盘...</comment>',
            'phar_packing' => '<comment>Phar 打包中...</comment>',
            'downloading_php' => "\r\n<comment>正在下载 PHP{version} ...</comment>",
            'download_failed' => '<error>下载失败：</error> {message}',
            'use_php' => "\r\n<comment>使用本地 PHP{version} 资源...</comment>",
            'saved_bin' => "\r\n<info>已保存</info> {name} <comment>→</comment> {path}\r\n<info>构建成功</info>\r\n",
            'download_stream_failed' => '<error>下载失败：</error> 无法连接下载源',
        ];
        $en = [
            'collect_complete' => '<info>Files collected</info> <comment>begin writing Phar...</comment>',
            'write_to_disk' => '<info>Writing Phar archive</info> <comment>and saving to disk...</comment>',
            'phar_packing' => '<comment>Phar packing...</comment>',
            'downloading_php' => "\r\n<comment>Downloading PHP{version} ...</comment>",
            'download_failed' => '<error>Download failed:</error> {message}',
            'use_php' => "\r\n<comment>Using local PHP{version} assets...</comment>",
            'saved_bin' => "\r\n<info>Saved</info> {name} <comment>to</comment> {path}\r\n<info>Build Success!</info>\r\n",
            'download_stream_failed' => '<error>Download failed:</error> cannot connect to download source',
        ];

        $map = Util::selectLocaleMessages(['zh_CN' => $zh, 'en' => $en]);
        $text = $map[$key] ?? $key;
        return $replace ? strtr($text, $replace) : $text;
    }

    /**
     * Plain-text error messages for exceptions (bilingual).
     *
     * @param string $key
     * @param array $replace
     * @return string
     */
    protected function err(string $key, array $replace = []): string
    {
        $zh = [
            'mkdir_build_dir_failed' => '创建 Phar 输出目录失败，请检查权限。',
            'bad_signature_algorithm' => '签名算法必须是 Phar::MD5、Phar::SHA1、Phar::SHA256、Phar::SHA512 或 Phar::OPENSSL 之一。',
            'openssl_private_key_missing' => "当签名算法为 'Phar::OPENSSL' 时，必须配置 private key 文件。",
            'phar_extension_required' => "打包 Phar 需要启用 'phar' 扩展。",
            'phar_readonly_on' => "{ini} 中 'phar.readonly' 为 On，打包 Phar 需要将其关闭（设置为 Off）才能打包，也可使用如下命令打包：php -d phar.readonly=0 ./webman {command}",
            'phar_filename_required' => '请配置 phar 文件名（phar_filename）。',
        ];
        $en = [
            'mkdir_build_dir_failed' => 'Failed to create Phar output directory. Please check permissions.',
            'bad_signature_algorithm' => 'Signature algorithm must be one of Phar::MD5, Phar::SHA1, Phar::SHA256, Phar::SHA512, or Phar::OPENSSL.',
            'openssl_private_key_missing' => "When signature algorithm is 'Phar::OPENSSL', you must configure the private key file.",
            'phar_extension_required' => "The 'phar' extension is required to build a Phar package.",
            'phar_readonly_on' => "In {ini}, 'phar.readonly' is On. Set it to Off to build Phar, or run: php -d phar.readonly=0 ./webman {command}",
            'phar_filename_required' => 'Please set the Phar filename (phar_filename).',
        ];
        $map = Util::selectLocaleMessages(['zh_CN' => $zh, 'en' => $en]);
        $text = $map[$key] ?? $key;
        return $replace ? strtr($text, $replace) : $text;
    }

    protected function getPhpIniDisplayPath(): string
    {
        $loaded = php_ini_loaded_file();
        if (is_string($loaded) && $loaded !== '') {
            return $loaded;
        }
        return Util::selectByLocale(['zh_CN' => 'php.ini（未加载）', 'en' => 'php.ini (not loaded)']);
    }

    /**
     * @throws RuntimeException
     */
    public function checkEnv(): void
    {
        if (!class_exists(Phar::class, false)) {
            throw new RuntimeException($this->err('phar_extension_required'));
        }

        if (ini_get('phar.readonly')) {
            $command = $this->getName();
            throw new RuntimeException(
                $this->err('phar_readonly_on', [
                    '{command}' => (string)$command,
                    '{ini}' => $this->getPhpIniDisplayPath(),
                ])
            );
        }
    }

    public function getPharFileName(): string
    {
        $phar_filename = $this->pharFileName;
        if (empty($phar_filename)) {
            throw new RuntimeException($this->err('phar_filename_required'));
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
