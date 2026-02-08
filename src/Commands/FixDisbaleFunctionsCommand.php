<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webman\Console\Commands\Concerns\MakeCommandHelpers;
use Webman\Console\Util;

#[AsCommand('fix-disable-functions', 'Fix disbale_functions in php.ini')]
class FixDisbaleFunctionsCommand extends Command
{
    use MakeCommandHelpers;

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $php_ini_file = php_ini_loaded_file();
        if (!$php_ini_file) {
            $output->writeln($this->msg('no_ini'));
            return self::FAILURE;
        }
        $output->writeln($this->msg('location', ['{path}' => $php_ini_file]));
        $disable_functions_str = ini_get("disable_functions");
        if (!$disable_functions_str) {
            $output->writeln($this->msg('ok'));
            return self::SUCCESS;
        }

        $functions_required = [
            "stream_socket_server",
            "stream_socket_accept",
            "stream_socket_client",
            "pcntl_signal_dispatch",
            "pcntl_signal",
            "pcntl_alarm",
            "pcntl_fork",
            "posix_getuid",
            "posix_getpwuid",
            "posix_kill",
            "posix_setsid",
            "posix_getpid",
            "posix_getpwnam",
            "posix_getgrnam",
            "posix_getgid",
            "posix_setgid",
            "posix_initgroups",
            "posix_setuid",
            "posix_isatty",
            "proc_open",
            "proc_get_status",
            "proc_close",
            "shell_exec",
            "exec",
        ];

        $disable_functions = explode(",", $disable_functions_str);
        $disable_functions_removed = [];
        foreach ($disable_functions as $index => $func) {
            $func = trim($func);
            foreach ($functions_required as $func_prefix) {
                if (strpos($func, $func_prefix) === 0) {
                    $disable_functions_removed[$func] = $func;
                    unset($disable_functions[$index]);
                }
            }
        }

        $php_ini_content = file_get_contents($php_ini_file);
        if (!is_string($php_ini_content) || $php_ini_content === '') {
            $output->writeln($this->msg('ini_empty', ['{path}' => $php_ini_file]));
            return self::FAILURE;
        }

        $disable_functions = array_values(array_filter(array_map('trim', $disable_functions), static fn($v) => $v !== ''));
        $new_disable_functions_str = implode(",", $disable_functions);

        // Replace existing line if present, otherwise append.
        $pattern = '/(^|\\R)\\s*disable_functions\\s*=.*$/m';
        if (preg_match($pattern, $php_ini_content)) {
            $php_ini_content = preg_replace(
                $pattern,
                '$1disable_functions = ' . $new_disable_functions_str,
                $php_ini_content,
                1
            );
        } else {
            $php_ini_content = rtrim($php_ini_content) . PHP_EOL . 'disable_functions = ' . $new_disable_functions_str . PHP_EOL;
        }

        file_put_contents($php_ini_file, $php_ini_content);

        foreach ($disable_functions_removed as $func) {
            $output->writeln($this->msg('enabled', ['{func}' => $func]));
        }

        $output->writeln($this->msg('success'));
        return self::SUCCESS;
    }

    protected function msg(string $key, array $replace = []): string
    {
        $messages = [
            'zh_CN' => [
                'no_ini' => '<error>找不到 php.ini</error>',
                'location' => '<comment>php.ini 路径</comment> {path}',
                'ok' => '<info>OK</info> <info>disable_functions 为空，无需处理</info>',
                'ini_empty' => '<error>php.ini 内容为空：</error> {path}',
                'enabled' => '<info>已启用</info> <comment>{func}</comment>',
                'success' => '<info>完成</info>',
            ],
            'zh_TW' => [
                'no_ini' => '<error>找不到 php.ini</error>',
                'location' => '<comment>php.ini 路徑</comment> {path}',
                'ok' => '<info>OK</info> <info>disable_functions 為空，無需處理</info>',
                'ini_empty' => '<error>php.ini 內容為空：</error> {path}',
                'enabled' => '<info>已啟用</info> <comment>{func}</comment>',
                'success' => '<info>完成</info>',
            ],
            'en' => [
                'no_ini' => '<error>Cannot find php.ini</error>',
                'location' => '<comment>php.ini</comment> {path}',
                'ok' => '<info>OK</info> <info>disable_functions is empty, nothing to fix</info>',
                'ini_empty' => '<error>php.ini content is empty:</error> {path}',
                'enabled' => '<info>Enabled</info> <comment>{func}</comment>',
                'success' => '<info>Done</info>',
            ],
            'ja' => [
                'no_ini' => '<error>php.ini が見つかりません</error>',
                'location' => '<comment>php.ini のパス</comment> {path}',
                'ok' => '<info>OK</info> <info>disable_functions は空です。修正不要です</info>',
                'ini_empty' => '<error>php.ini の内容が空です：</error> {path}',
                'enabled' => '<info>有効化しました</info> <comment>{func}</comment>',
                'success' => '<info>完了</info>',
            ],
            'ko' => [
                'no_ini' => '<error>php.ini를 찾을 수 없습니다</error>',
                'location' => '<comment>php.ini 경로</comment> {path}',
                'ok' => '<info>OK</info> <info>disable_functions가 비어 있어 수정할 항목이 없습니다</info>',
                'ini_empty' => '<error>php.ini 내용이 비어 있습니다：</error> {path}',
                'enabled' => '<info>활성화됨</info> <comment>{func}</comment>',
                'success' => '<info>완료</info>',
            ],
            'fr' => [
                'no_ini' => '<error>Fichier php.ini introuvable</error>',
                'location' => '<comment>php.ini</comment> {path}',
                'ok' => '<info>OK</info> <info>disable_functions est vide, rien à modifier</info>',
                'ini_empty' => '<error>Le contenu de php.ini est vide :</error> {path}',
                'enabled' => '<info>Activé</info> <comment>{func}</comment>',
                'success' => '<info>Terminé</info>',
            ],
            'de' => [
                'no_ini' => '<error>php.ini wurde nicht gefunden</error>',
                'location' => '<comment>php.ini</comment> {path}',
                'ok' => '<info>OK</info> <info>disable_functions ist leer, nichts zu ändern</info>',
                'ini_empty' => '<error>php.ini-Inhalt ist leer:</error> {path}',
                'enabled' => '<info>Aktiviert</info> <comment>{func}</comment>',
                'success' => '<info>Fertig</info>',
            ],
            'es' => [
                'no_ini' => '<error>No se encuentra php.ini</error>',
                'location' => '<comment>php.ini</comment> {path}',
                'ok' => '<info>OK</info> <info>disable_functions está vacío, nada que corregir</info>',
                'ini_empty' => '<error>El contenido de php.ini está vacío:</error> {path}',
                'enabled' => '<info>Habilitado</info> <comment>{func}</comment>',
                'success' => '<info>Hecho</info>',
            ],
            'pt_BR' => [
                'no_ini' => '<error>Não foi possível encontrar php.ini</error>',
                'location' => '<comment>php.ini</comment> {path}',
                'ok' => '<info>OK</info> <info>disable_functions está vazio, nada a corrigir</info>',
                'ini_empty' => '<error>Conteúdo de php.ini está vazio:</error> {path}',
                'enabled' => '<info>Habilitado</info> <comment>{func}</comment>',
                'success' => '<info>Concluído</info>',
            ],
            'ru' => [
                'no_ini' => '<error>Файл php.ini не найден</error>',
                'location' => '<comment>php.ini</comment> {path}',
                'ok' => '<info>OK</info> <info>disable_functions пуст, изменений не требуется</info>',
                'ini_empty' => '<error>Содержимое php.ini пусто:</error> {path}',
                'enabled' => '<info>Включено</info> <comment>{func}</comment>',
                'success' => '<info>Готово</info>',
            ],
            'vi' => [
                'no_ini' => '<error>Không tìm thấy php.ini</error>',
                'location' => '<comment>Đường dẫn php.ini</comment> {path}',
                'ok' => '<info>OK</info> <info>disable_functions trống, không cần xử lý</info>',
                'ini_empty' => '<error>Nội dung php.ini trống:</error> {path}',
                'enabled' => '<info>Đã bật</info> <comment>{func}</comment>',
                'success' => '<info>Xong</info>',
            ],
            'tr' => [
                'no_ini' => '<error>php.ini bulunamadı</error>',
                'location' => '<comment>php.ini</comment> {path}',
                'ok' => '<info>OK</info> <info>disable_functions boş, düzeltilecek bir şey yok</info>',
                'ini_empty' => '<error>php.ini içeriği boş:</error> {path}',
                'enabled' => '<info>Etkinleştirildi</info> <comment>{func}</comment>',
                'success' => '<info>Tamamlandı</info>',
            ],
            'id' => [
                'no_ini' => '<error>Tidak dapat menemukan php.ini</error>',
                'location' => '<comment>php.ini</comment> {path}',
                'ok' => '<info>OK</info> <info>disable_functions kosong, tidak ada yang perlu diperbaiki</info>',
                'ini_empty' => '<error>Konten php.ini kosong:</error> {path}',
                'enabled' => '<info>Diaktifkan</info> <comment>{func}</comment>',
                'success' => '<info>Selesai</info>',
            ],
            'th' => [
                'no_ini' => '<error>ไม่พบ php.ini</error>',
                'location' => '<comment>เส้นทาง php.ini</comment> {path}',
                'ok' => '<info>OK</info> <info>disable_functions ว่าง ไม่ต้องแก้ไข</info>',
                'ini_empty' => '<error>เนื้อหา php.ini ว่าง：</error> {path}',
                'enabled' => '<info>เปิดใช้งานแล้ว</info> <comment>{func}</comment>',
                'success' => '<info>เสร็จสิ้น</info>',
            ],
        ];
        $map = Util::selectLocaleMessages($messages);
        $text = $map[$key] ?? $key;
        return $replace ? strtr($text, $replace) : $text;
    }
}
