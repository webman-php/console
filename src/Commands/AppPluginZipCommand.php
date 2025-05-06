<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ZipArchive;
use Exception;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

#[AsCommand('app-plugin:zip', 'App Plugin Zip')]
class AppPluginZipCommand extends Command
{
    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'App plugin name');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $output->writeln("Zip App Plugin $name");
        $sourceDir = base_path('plugin' . DIRECTORY_SEPARATOR . $name);
        $zipFilePath = base_path('plugin' . DIRECTORY_SEPARATOR . $name . '.zip');
        if (!is_dir($sourceDir)) {
            $output->writeln("Plugin $name not exists");
            return self::FAILURE;
        }
        if (is_file($zipFilePath)) {
            unlink($zipFilePath);
        }

        $excludePaths = ['node_modules', '.git', '.idea', '.vscode', '__pycache__'];

        $this->zipDirectory($name, $sourceDir, $zipFilePath, $excludePaths);
        return self::SUCCESS;
    }

    /**
     * @param $name
     * @param $sourceDir
     * @param $zipFilePath
     * @param array $excludePaths
     * @return bool
     * @throws Exception
     */
    protected function zipDirectory($name, $sourceDir, $zipFilePath, array $excludePaths = []): bool
    {
        $zip = new ZipArchive();

        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            throw new Exception("cannot open <$zipFilePath>\n");
        }

        $sourceDir = realpath($sourceDir);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = $name . DIRECTORY_SEPARATOR . substr($filePath, strlen($sourceDir) + 1);

                // 修正排除目录的判断逻辑，确保所有层级都能排除
                $shouldExclude = false;
                foreach ($excludePaths as $excludePath) {
                    // 统一路径分隔符为正斜杠，兼容 Windows
                    $normalizedRelativePath = str_replace('\\', '/', $relativePath);
                    $normalizedExcludePath = str_replace('\\', '/', $excludePath);
                    if (preg_match('#/(?:' . preg_quote($normalizedExcludePath, '#') . ')(/|$)#i', $normalizedRelativePath)) {
                        $shouldExclude = true;
                        break;
                    }
                }
                if ($shouldExclude) {
                    continue;
                }

                $zip->addFile($filePath, $relativePath);
            }
        }

        return $zip->close();
    }
}
