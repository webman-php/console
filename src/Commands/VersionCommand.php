<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webman\Console\Commands\Concerns\MakeCommandHelpers;
use Webman\Console\Util;

#[AsCommand('version', 'Show webman version')]
class VersionCommand extends Command
{
    use MakeCommandHelpers;

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $version_info = [];
        $installed_file = base_path() . '/vendor/composer/installed.php';
        if (is_file($installed_file)) {
            $version_info = include $installed_file;
        }
        $webman_framework_version = $version_info['versions']['workerman/webman-framework']['pretty_version'] ?? null;
        $webman_framework_version = is_string($webman_framework_version) ? trim($webman_framework_version) : '';
        if ($webman_framework_version === '') {
            $output->writeln($this->msg('not_found'));
            return self::FAILURE;
        }
        $output->writeln($this->msg('version', ['{version}' => $webman_framework_version]));
        return self::SUCCESS;
    }

    protected function msg(string $key, array $replace = []): string
    {
        $messages = [
            'zh_CN' => [
                'version' => '<info>Webman-framework 版本</info> <comment>{version}</comment>',
                'not_found' => '<error>无法读取 workerman/webman-framework 版本信息</error>',
            ],
            'zh_TW' => [
                'version' => '<info>Webman-framework 版本</info> <comment>{version}</comment>',
                'not_found' => '<error>無法讀取 workerman/webman-framework 版本資訊</error>',
            ],
            'en' => [
                'version' => '<info>Webman-framework</info> <comment>{version}</comment>',
                'not_found' => '<error>Unable to read version info for workerman/webman-framework</error>',
            ],
            'ja' => [
                'version' => '<info>Webman-framework バージョン</info> <comment>{version}</comment>',
                'not_found' => '<error>workerman/webman-framework のバージョン情報を読み取れません</error>',
            ],
            'ko' => [
                'version' => '<info>Webman-framework 버전</info> <comment>{version}</comment>',
                'not_found' => '<error>workerman/webman-framework 버전 정보를 읽을 수 없습니다</error>',
            ],
            'fr' => [
                'version' => '<info>Webman-framework</info> <comment>{version}</comment>',
                'not_found' => '<error>Impossible de lire la version workerman/webman-framework</error>',
            ],
            'de' => [
                'version' => '<info>Webman-framework</info> <comment>{version}</comment>',
                'not_found' => '<error>Versionsinfo für workerman/webman-framework konnte nicht gelesen werden</error>',
            ],
            'es' => [
                'version' => '<info>Webman-framework</info> <comment>{version}</comment>',
                'not_found' => '<error>No se pudo leer la versión de workerman/webman-framework</error>',
            ],
            'pt_BR' => [
                'version' => '<info>Webman-framework</info> <comment>{version}</comment>',
                'not_found' => '<error>Não foi possível ler a versão do workerman/webman-framework</error>',
            ],
            'ru' => [
                'version' => '<info>Webman-framework</info> <comment>{version}</comment>',
                'not_found' => '<error>Не удалось прочитать версию workerman/webman-framework</error>',
            ],
            'vi' => [
                'version' => '<info>Webman-framework</info> <comment>{version}</comment>',
                'not_found' => '<error>Không đọc được thông tin phiên bản workerman/webman-framework</error>',
            ],
            'tr' => [
                'version' => '<info>Webman-framework</info> <comment>{version}</comment>',
                'not_found' => '<error>workerman/webman-framework sürüm bilgisi okunamadı</error>',
            ],
            'id' => [
                'version' => '<info>Webman-framework</info> <comment>{version}</comment>',
                'not_found' => '<error>Tidak dapat membaca info versi workerman/webman-framework</error>',
            ],
            'th' => [
                'version' => '<info>Webman-framework</info> <comment>{version}</comment>',
                'not_found' => '<error>อ่านข้อมูลเวอร์ชัน workerman/webman-framework ไม่ได้</error>',
            ],
        ];
        $map = Util::selectLocaleMessages($messages);
        $text = $map[$key] ?? $key;
        return $replace ? strtr($text, $replace) : $text;
    }
}
