<?php

namespace Webman\Console\Commands;

use Phar;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ZipArchive;


class BuildBinCommand extends Command
{
    protected static $defaultName = 'build:bin';
    protected static $defaultDescription = 'build bin';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('version', InputArgument::OPTIONAL, 'PHP version');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->checkEnv();
        $output->writeln('Phar packing...');

        $version = $input->getArgument('version');
        if (!$version) {
            $version = (float)PHP_VERSION;
        }
        $version = $version >= 8.0 ? $version : 8.1;
        $supportZip = class_exists(ZipArchive::class);
        $microZipFileName = $supportZip ? "php$version.micro.sfx.zip" : "php$version.micro.sfx";
        $pharFileName = config('plugin.webman.console.app.phar_filename', 'webman.phar');
        $binFileName = config('plugin.webman.console.app.bin_filename', 'webman.bin');
        $buildDir = config('plugin.webman.console.app.build_dir', base_path() . '/build');

        $binFile = "$buildDir/$binFileName";
        $pharFile = "$buildDir/$pharFileName";
        $zipFile = "$buildDir/$microZipFileName";
        $sfxFile = "$buildDir/php$version.micro.sfx";

        foreach ([$binFile, $pharFile, $zipFile, $sfxFile] as $file) {
            if (is_file($file)) {
                unlink($binFile);
            }
        }

        // 打包
        $command = new PharPackCommand();
        $command->execute($input, $output);

        // 下载 micro.sfx.zip
        $domain = 'download.workerman.net';
        $output->writeln("\r\nDownloading PHP$version ...");
        if (extension_loaded('openssl')) {
            $client = stream_socket_client("ssl://$domain:443");
        } else {
            $client = stream_socket_client("tcp://$domain:80");
        }

        fwrite($client, "GET /php/$microZipFileName HTTP/1.0\r\nAccept: text/html\r\nHost: $domain\r\nUser-Agent: webman/console\r\n\r\n");
        $bodyLength = 0;
        $bodyBuffer = '';
        $lastPercent = 0;
        while (true) {
            $buffer = fread($client, 65535);
            if ($buffer !== false) {
                $bodyBuffer .= $buffer;
                if (!$bodyLength && $pos = strpos($bodyBuffer, "\r\n\r\n")) {
                    if (!preg_match('/Content-Length: (\d+)\r\n/', $bodyBuffer, $match)) {
                        $output->writeln("Download php$version.micro.sfx.zip failed");
                        return self::FAILURE;
                    }
                    if (preg_match('/404 Not Found/', $bodyBuffer)) {
                        $output->writeln("Download php$version.micro.sfx.zip failed, 404 Not Found");
                        return self::FAILURE;
                    }
                    $bodyLength = (int)$match[1];
                    $bodyBuffer = substr($bodyBuffer, $pos + 4);
                }
            }
            $receiveLength = strlen($bodyBuffer);
            $percent = ceil($receiveLength * 100 / $bodyLength);
            if ($percent != $lastPercent) {
                echo "[" . str_pad('', $percent, '=') . '>' . str_pad('', 100 - $percent) . "$percent%]";
                echo $percent < 100 ? "\r" : "\n";
            }
            $lastPercent = $percent;
            if ($bodyLength && $receiveLength >= $bodyLength) {
                file_put_contents($zipFile, $bodyBuffer);
                break;
            }
            if ($buffer === false || !is_resource($client) || feof($client)) {
                $output->writeln("Fail donwload PHP$version ...");
                return self::FAILURE;
            }
        }

        // 解压
        if ($supportZip) {
            $zip = new ZipArchive;
            $zip->open($zipFile, ZipArchive::CHECKCONS);
            $zip->extractTo($buildDir);
        }

        // 生成二进制文件
        file_put_contents($binFile, file_get_contents($sfxFile) . file_get_contents($pharFile));
        if ($supportZip) {
            unlink($zipFile);
        }
        unlink($sfxFile);
        unlink($pharFile);

        // 添加执行权限
        chmod($binFile, 0755);

        $output->writeln("\r\nSaved $binFileName to $binFile\r\nBuild Success!\r\n");

        return self::SUCCESS;
    }

    /**
     * @throws RuntimeException
     */
    private function checkEnv(): void
    {
        if (!class_exists(Phar::class, false)) {
            throw new RuntimeException("The 'phar' extension is required for build phar package");
        }

        if (ini_get('phar.readonly')) {
            throw new RuntimeException(
                "The 'phar.readonly' is 'On', build phar must setting it 'Off' or exec with 'php -d phar.readonly=0 ./webman build:bin'"
            );
        }
    }

}
