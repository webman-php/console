<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webman\Console\Commands\Concerns\MakeCommandHelpers;
use Webman\Console\Util;

#[AsCommand('install', 'Execute webman installation script')]
class InstallCommand extends Command
{
    use MakeCommandHelpers;

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->msg('install_title'));
        $install_function = "\\Webman\\Install::install";
        if (is_callable($install_function)) {
            $install_function();
            $output->writeln($this->msg('done'));
            return self::SUCCESS;
        }
        $output->writeln($this->msg('require_version'));
        return self::FAILURE;
    }

    protected function msg(string $key, array $replace = []): string
    {
        $messages = [
            'zh_CN' => [
                'install_title' => '<info>执行 Webman 安装脚本</info>',
                'done' => '<info>完成</info>',
                'require_version' => '<error>该命令需要 webman-framework 版本 >= 1.3.0</error>',
            ],
            'zh_TW' => [
                'install_title' => '<info>執行 Webman 安裝腳本</info>',
                'done' => '<info>完成</info>',
                'require_version' => '<error>此命令需要 webman-framework 版本 >= 1.3.0</error>',
            ],
            'en' => [
                'install_title' => '<info>Execute installation for Webman</info>',
                'done' => '<info>Done</info>',
                'require_version' => '<error>This command requires webman-framework version >= 1.3.0</error>',
            ],
            'ja' => [
                'install_title' => '<info>Webman インストールスクリプトを実行</info>',
                'done' => '<info>完了</info>',
                'require_version' => '<error>このコマンドには webman-framework 1.3.0 以上が必要です</error>',
            ],
            'ko' => [
                'install_title' => '<info>Webman 설치 스크립트 실행</info>',
                'done' => '<info>완료</info>',
                'require_version' => '<error>이 명령은 webman-framework 1.3.0 이상이 필요합니다</error>',
            ],
            'fr' => [
                'install_title' => '<info>Exécuter le script d\'installation Webman</info>',
                'done' => '<info>Terminé</info>',
                'require_version' => '<error>Cette commande requiert webman-framework >= 1.3.0</error>',
            ],
            'de' => [
                'install_title' => '<info>Webman-Installation ausführen</info>',
                'done' => '<info>Fertig</info>',
                'require_version' => '<error>Dieser Befehl erfordert webman-framework >= 1.3.0</error>',
            ],
            'es' => [
                'install_title' => '<info>Ejecutar script de instalación de Webman</info>',
                'done' => '<info>Hecho</info>',
                'require_version' => '<error>Este comando requiere webman-framework >= 1.3.0</error>',
            ],
            'pt_BR' => [
                'install_title' => '<info>Executar script de instalação do Webman</info>',
                'done' => '<info>Concluído</info>',
                'require_version' => '<error>Este comando requer webman-framework >= 1.3.0</error>',
            ],
            'ru' => [
                'install_title' => '<info>Выполнить скрипт установки Webman</info>',
                'done' => '<info>Готово</info>',
                'require_version' => '<error>Для этой команды требуется webman-framework >= 1.3.0</error>',
            ],
            'vi' => [
                'install_title' => '<info>Chạy script cài đặt Webman</info>',
                'done' => '<info>Xong</info>',
                'require_version' => '<error>Lệnh này yêu cầu webman-framework >= 1.3.0</error>',
            ],
            'tr' => [
                'install_title' => '<info>Webman kurulum betiğini çalıştır</info>',
                'done' => '<info>Tamamlandı</info>',
                'require_version' => '<error>Bu komut webman-framework 1.3.0 veya üstü gerektirir</error>',
            ],
            'id' => [
                'install_title' => '<info>Jalankan skrip instalasi Webman</info>',
                'done' => '<info>Selesai</info>',
                'require_version' => '<error>Perintah ini memerlukan webman-framework >= 1.3.0</error>',
            ],
            'th' => [
                'install_title' => '<info>รันสคริปต์ติดตั้ง Webman</info>',
                'done' => '<info>เสร็จสิ้น</info>',
                'require_version' => '<error>คำสั่งนี้ต้องใช้ webman-framework >= 1.3.0</error>',
            ],
        ];
        $map = Util::selectLocaleMessages($messages);
        $text = $map[$key] ?? $key;
        return $replace ? strtr($text, $replace) : $text;
    }
}
