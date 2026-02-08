<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webman\Console\Commands\Concerns\AppPluginCommandHelpers;
use Webman\Console\Util;
use ZipArchive;
use Exception;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

#[AsCommand('app-plugin:zip', 'App Plugin Zip')]
class AppPluginZipCommand extends Command
{
    use AppPluginCommandHelpers;

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
        $name = $this->normalizeAppPluginName($input->getArgument('name'));
        $this->writeln($output, $this->msg('zip_title', ['{name}' => $name]));

        if (!$this->isValidAppPluginName($name)) {
            $this->writeln($output, $this->msg('bad_name', ['{name}' => $name]));
            return Command::FAILURE;
        }

        $sourceDir = $this->appPluginBasePath($name);
        $zipFilePath = base_path('plugin' . DIRECTORY_SEPARATOR . $name . '.zip');
        if (!is_dir($sourceDir)) {
            $this->writeln($output, $this->msg('plugin_not_exists', ['{path}' => $this->toRelativePath($sourceDir)]));
            return Command::FAILURE;
        }

        if (is_file($zipFilePath)) {
            if (!@unlink($zipFilePath) && is_file($zipFilePath)) {
                $this->writeln($output, $this->msg('zip_delete_failed', ['{path}' => $this->toRelativePath($zipFilePath)]));
                return Command::FAILURE;
            }
        }

        $excludePaths = ['node_modules', '.git', '.idea', '.vscode', '__pycache__'];

        try {
            $this->zipDirectory($name, $sourceDir, $zipFilePath, $excludePaths);
        } catch (\Throwable $e) {
            $this->writeln($output, $this->msg('failed', ['{error}' => $e->getMessage()]));
            return Command::FAILURE;
        }

        $this->writeln($output, $this->msg('zip_saved', ['{path}' => $this->toRelativePath($zipFilePath)]));
        $this->writeln($output, $this->msg('done'));
        return Command::SUCCESS;
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
            $msg = Util::selectByLocale([
                'zh_CN' => "无法创建 zip 文件：{$zipFilePath}", 'zh_TW' => "無法建立 zip 檔案：{$zipFilePath}",
                'en' => "Unable to create zip file: {$zipFilePath}", 'ja' => "zip ファイルを作成できません：{$zipFilePath}",
                'ko' => "zip 파일을 생성할 수 없습니다：{$zipFilePath}", 'fr' => "Impossible de créer le fichier zip : {$zipFilePath}",
                'de' => "Zip-Datei kann nicht erstellt werden: {$zipFilePath}", 'es' => "No se puede crear el archivo zip: {$zipFilePath}",
                'pt_BR' => "Não foi possível criar o arquivo zip: {$zipFilePath}", 'ru' => "Не удалось создать zip-файл: {$zipFilePath}",
                'vi' => "Không thể tạo tệp zip: {$zipFilePath}", 'tr' => "Zip dosyası oluşturulamıyor: {$zipFilePath}",
                'id' => "Tidak dapat membuat file zip: {$zipFilePath}", 'th' => "สร้างไฟล์ zip ไม่ได้：{$zipFilePath}",
            ]);
            throw new Exception($msg);
        }

        $rawSourceDir = $sourceDir;
        $sourceDir = realpath($sourceDir);
        if ($sourceDir === false) {
            $msg = Util::selectByLocale([
                'zh_CN' => "源目录不存在：{$rawSourceDir}", 'zh_TW' => "來源目錄不存在：{$rawSourceDir}",
                'en' => "Source directory does not exist: {$rawSourceDir}", 'ja' => "ソースディレクトリが存在しません：{$rawSourceDir}",
                'ko' => "소스 디렉터리가 없습니다：{$rawSourceDir}", 'fr' => "Le répertoire source n'existe pas : {$rawSourceDir}",
                'de' => "Quellverzeichnis existiert nicht: {$rawSourceDir}", 'es' => "El directorio origen no existe: {$rawSourceDir}",
                'pt_BR' => "O diretório de origem não existe: {$rawSourceDir}", 'ru' => "Исходный каталог не существует: {$rawSourceDir}",
                'vi' => "Thư mục nguồn không tồn tại: {$rawSourceDir}", 'tr' => "Kaynak dizin mevcut değil: {$rawSourceDir}",
                'id' => "Direktori sumber tidak ada: {$rawSourceDir}", 'th' => "ไม่มีไดเรกทอรีต้นทาง：{$rawSourceDir}",
            ]);
            throw new Exception($msg);
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                // 关键修复：统一使用正斜杠 '/'，避免 Windows 反斜杠污染 ZIP
                $relativePath = $name . '/' . str_replace('\\', '/', substr($filePath, strlen($sourceDir) + 1));

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
