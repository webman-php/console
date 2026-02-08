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
        $messages = [
            'zh_CN' => [
                'collect_complete' => '<info>文件收集完成</info> <comment>开始写入 Phar...</comment>',
                'write_to_disk' => '<info>写入 Phar 归档</info> <comment>并保存到磁盘...</comment>',
                'phar_packing' => '<comment>Phar 打包中...</comment>',
                'downloading_php' => "\r\n<comment>正在下载 PHP{version} ...</comment>",
                'download_failed' => '<error>下载失败：</error> {message}',
                'use_php' => "\r\n<comment>使用本地 PHP{version} 资源...</comment>",
                'saved_bin' => "\r\n<info>已保存</info> {name} <comment>→</comment> {path}\r\n<info>构建成功</info>\r\n",
                'download_stream_failed' => '<error>下载失败：</error> 无法连接下载源',
            ],
            'zh_TW' => [
                'collect_complete' => '<info>檔案收集完成</info> <comment>開始寫入 Phar...</comment>',
                'write_to_disk' => '<info>寫入 Phar 歸檔</info> <comment>並儲存到磁碟...</comment>',
                'phar_packing' => '<comment>Phar 打包中...</comment>',
                'downloading_php' => "\r\n<comment>正在下載 PHP{version} ...</comment>",
                'download_failed' => '<error>下載失敗：</error> {message}',
                'use_php' => "\r\n<comment>使用本機 PHP{version} 資源...</comment>",
                'saved_bin' => "\r\n<info>已儲存</info> {name} <comment>→</comment> {path}\r\n<info>建置成功</info>\r\n",
                'download_stream_failed' => '<error>下載失敗：</error> 無法連線至下載來源',
            ],
            'en' => [
                'collect_complete' => '<info>Files collected</info> <comment>begin writing Phar...</comment>',
                'write_to_disk' => '<info>Writing Phar archive</info> <comment>and saving to disk...</comment>',
                'phar_packing' => '<comment>Phar packing...</comment>',
                'downloading_php' => "\r\n<comment>Downloading PHP{version} ...</comment>",
                'download_failed' => '<error>Download failed:</error> {message}',
                'use_php' => "\r\n<comment>Using local PHP{version} assets...</comment>",
                'saved_bin' => "\r\n<info>Saved</info> {name} <comment>to</comment> {path}\r\n<info>Build Success!</info>\r\n",
                'download_stream_failed' => '<error>Download failed:</error> cannot connect to download source',
            ],
            'ja' => [
                'collect_complete' => '<info>ファイル収集完了</info> <comment>Phar 書き込み開始...</comment>',
                'write_to_disk' => '<info>Phar アーカイブを書き込み</info> <comment>ディスクに保存中...</comment>',
                'phar_packing' => '<comment>Phar パッキング中...</comment>',
                'downloading_php' => "\r\n<comment>PHP{version} をダウンロード中...</comment>",
                'download_failed' => '<error>ダウンロード失敗：</error> {message}',
                'use_php' => "\r\n<comment>ローカル PHP{version} を使用...</comment>",
                'saved_bin' => "\r\n<info>保存しました</info> {name} <comment>→</comment> {path}\r\n<info>ビルド成功</info>\r\n",
                'download_stream_failed' => '<error>ダウンロード失敗：</error> ダウンロード元に接続できません',
            ],
            'ko' => [
                'collect_complete' => '<info>파일 수집 완료</info> <comment>Phar 쓰기 시작...</comment>',
                'write_to_disk' => '<info>Phar 아카이브 쓰기</info> <comment>디스크에 저장 중...</comment>',
                'phar_packing' => '<comment>Phar 패킹 중...</comment>',
                'downloading_php' => "\r\n<comment>PHP{version} 다운로드 중...</comment>",
                'download_failed' => '<error>다운로드 실패:</error> {message}',
                'use_php' => "\r\n<comment>로컬 PHP{version} 사용 중...</comment>",
                'saved_bin' => "\r\n<info>저장됨</info> {name} <comment>→</comment> {path}\r\n<info>빌드 성공</info>\r\n",
                'download_stream_failed' => '<error>다운로드 실패:</error> 다운로드 소스에 연결할 수 없습니다',
            ],
            'fr' => [
                'collect_complete' => '<info>Fichiers collectés</info> <comment>écriture du Phar...</comment>',
                'write_to_disk' => '<info>Écriture de l\'archive Phar</info> <comment>et enregistrement...</comment>',
                'phar_packing' => '<comment>Création du Phar...</comment>',
                'downloading_php' => "\r\n<comment>Téléchargement de PHP{version}...</comment>",
                'download_failed' => '<error>Échec du téléchargement :</error> {message}',
                'use_php' => "\r\n<comment>Utilisation du PHP{version} local...</comment>",
                'saved_bin' => "\r\n<info>Enregistré</info> {name} <comment>→</comment> {path}\r\n<info>Build réussi</info>\r\n",
                'download_stream_failed' => '<error>Échec du téléchargement :</error> impossible de contacter la source',
            ],
            'de' => [
                'collect_complete' => '<info>Dateien gesammelt</info> <comment>Schreibe Phar...</comment>',
                'write_to_disk' => '<info>Phar-Archiv wird geschrieben</info> <comment>und auf Disk gespeichert...</comment>',
                'phar_packing' => '<comment>Phar wird erstellt...</comment>',
                'downloading_php' => "\r\n<comment>Lade PHP{version} herunter...</comment>",
                'download_failed' => '<error>Download fehlgeschlagen:</error> {message}',
                'use_php' => "\r\n<comment>Lokale PHP{version}-Ressourcen werden verwendet...</comment>",
                'saved_bin' => "\r\n<info>Gespeichert</info> {name} <comment>→</comment> {path}\r\n<info>Build erfolgreich</info>\r\n",
                'download_stream_failed' => '<error>Download fehlgeschlagen:</error> Verbindung zur Quelle nicht möglich',
            ],
            'es' => [
                'collect_complete' => '<info>Archivos recopilados</info> <comment>escribiendo Phar...</comment>',
                'write_to_disk' => '<info>Escribiendo archivo Phar</info> <comment>y guardando en disco...</comment>',
                'phar_packing' => '<comment>Empaquetando Phar...</comment>',
                'downloading_php' => "\r\n<comment>Descargando PHP{version}...</comment>",
                'download_failed' => '<error>Error de descarga:</error> {message}',
                'use_php' => "\r\n<comment>Usando PHP{version} local...</comment>",
                'saved_bin' => "\r\n<info>Guardado</info> {name} <comment>→</comment> {path}\r\n<info>Build correcto</info>\r\n",
                'download_stream_failed' => '<error>Error de descarga:</error> no se pudo conectar con la fuente',
            ],
            'pt_BR' => [
                'collect_complete' => '<info>Arquivos coletados</info> <comment>escrevendo Phar...</comment>',
                'write_to_disk' => '<info>Gravando arquivo Phar</info> <comment>e salvando em disco...</comment>',
                'phar_packing' => '<comment>Empacotando Phar...</comment>',
                'downloading_php' => "\r\n<comment>Baixando PHP{version}...</comment>",
                'download_failed' => '<error>Falha no download:</error> {message}',
                'use_php' => "\r\n<comment>Usando PHP{version} local...</comment>",
                'saved_bin' => "\r\n<info>Salvo</info> {name} <comment>→</comment> {path}\r\n<info>Build concluído</info>\r\n",
                'download_stream_failed' => '<error>Falha no download:</error> não foi possível conectar à fonte',
            ],
            'ru' => [
                'collect_complete' => '<info>Файлы собраны</info> <comment>запись Phar...</comment>',
                'write_to_disk' => '<info>Запись архива Phar</info> <comment>и сохранение на диск...</comment>',
                'phar_packing' => '<comment>Создание Phar...</comment>',
                'downloading_php' => "\r\n<comment>Загрузка PHP{version}...</comment>",
                'download_failed' => '<error>Ошибка загрузки:</error> {message}',
                'use_php' => "\r\n<comment>Используются локальные PHP{version}...</comment>",
                'saved_bin' => "\r\n<info>Сохранено</info> {name} <comment>→</comment> {path}\r\n<info>Сборка успешна</info>\r\n",
                'download_stream_failed' => '<error>Ошибка загрузки:</error> не удалось подключиться к источнику',
            ],
            'vi' => [
                'collect_complete' => '<info>Đã thu thập tệp</info> <comment>đang ghi Phar...</comment>',
                'write_to_disk' => '<info>Ghi archive Phar</info> <comment>và lưu vào đĩa...</comment>',
                'phar_packing' => '<comment>Đang đóng gói Phar...</comment>',
                'downloading_php' => "\r\n<comment>Đang tải PHP{version}...</comment>",
                'download_failed' => '<error>Tải thất bại:</error> {message}',
                'use_php' => "\r\n<comment>Đang dùng PHP{version} local...</comment>",
                'saved_bin' => "\r\n<info>Đã lưu</info> {name} <comment>→</comment> {path}\r\n<info>Build thành công</info>\r\n",
                'download_stream_failed' => '<error>Tải thất bại:</error> không kết nối được nguồn tải',
            ],
            'tr' => [
                'collect_complete' => '<info>Dosyalar toplandı</info> <comment>Phar yazılıyor...</comment>',
                'write_to_disk' => '<info>Phar arşivi yazılıyor</info> <comment>diske kaydediliyor...</comment>',
                'phar_packing' => '<comment>Phar paketleniyor...</comment>',
                'downloading_php' => "\r\n<comment>PHP{version} indiriliyor...</comment>",
                'download_failed' => '<error>İndirme başarısız:</error> {message}',
                'use_php' => "\r\n<comment>Yerel PHP{version} kullanılıyor...</comment>",
                'saved_bin' => "\r\n<info>Kaydedildi</info> {name} <comment>→</comment> {path}\r\n<info>Derleme başarılı</info>\r\n",
                'download_stream_failed' => '<error>İndirme başarısız:</error> kaynağa bağlanılamıyor',
            ],
            'id' => [
                'collect_complete' => '<info>File terkumpul</info> <comment>menulis Phar...</comment>',
                'write_to_disk' => '<info>Menulis arsip Phar</info> <comment>dan menyimpan ke disk...</comment>',
                'phar_packing' => '<comment>Membuat paket Phar...</comment>',
                'downloading_php' => "\r\n<comment>Mengunduh PHP{version}...</comment>",
                'download_failed' => '<error>Unduhan gagal:</error> {message}',
                'use_php' => "\r\n<comment>Menggunakan aset PHP{version} lokal...</comment>",
                'saved_bin' => "\r\n<info>Disimpan</info> {name} <comment>→</comment> {path}\r\n<info>Build berhasil</info>\r\n",
                'download_stream_failed' => '<error>Unduhan gagal:</error> tidak dapat terhubung ke sumber',
            ],
            'th' => [
                'collect_complete' => '<info>รวบรวมไฟล์เสร็จแล้ว</info> <comment>กำลังเขียน Phar...</comment>',
                'write_to_disk' => '<info>กำลังเขียนไฟล์ Phar</info> <comment>และบันทึกลงดิสก์...</comment>',
                'phar_packing' => '<comment>กำลังแพ็ก Phar...</comment>',
                'downloading_php' => "\r\n<comment>กำลังดาวน์โหลด PHP{version}...</comment>",
                'download_failed' => '<error>ดาวน์โหลดล้มเหลว：</error> {message}',
                'use_php' => "\r\n<comment>ใช้ PHP{version} ท้องถิ่น...</comment>",
                'saved_bin' => "\r\n<info>บันทึกแล้ว</info> {name} <comment>→</comment> {path}\r\n<info>Build สำเร็จ</info>\r\n",
                'download_stream_failed' => '<error>ดาวน์โหลดล้มเหลว：</error> เชื่อมต่อแหล่งดาวน์โหลดไม่ได้',
            ],
        ];
        $map = Util::selectLocaleMessages($messages);
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
        $messages = [
            'zh_CN' => [
                'mkdir_build_dir_failed' => '创建 Phar 输出目录失败，请检查权限。',
                'bad_signature_algorithm' => '签名算法必须是 Phar::MD5、Phar::SHA1、Phar::SHA256、Phar::SHA512 或 Phar::OPENSSL 之一。',
                'openssl_private_key_missing' => "当签名算法为 'Phar::OPENSSL' 时，必须配置 private key 文件。",
                'phar_extension_required' => "打包 Phar 需要启用 'phar' 扩展。",
                'phar_readonly_on' => "{ini} 中 'phar.readonly' 为 On，打包 Phar 需要将其关闭（设置为 Off）才能打包，也可使用如下命令打包：php -d phar.readonly=0 ./webman {command}",
                'phar_filename_required' => '请配置 phar 文件名（phar_filename）。',
            ],
            'zh_TW' => [
                'mkdir_build_dir_failed' => '建立 Phar 輸出目錄失敗，請檢查權限。',
                'bad_signature_algorithm' => '簽章演算法必須為 Phar::MD5、Phar::SHA1、Phar::SHA256、Phar::SHA512 或 Phar::OPENSSL 之一。',
                'openssl_private_key_missing' => "當簽章演算法為 'Phar::OPENSSL' 時，必須設定 private key 檔案。",
                'phar_extension_required' => "打包 Phar 需啟用 'phar' 擴充功能。",
                'phar_readonly_on' => "{ini} 中 'phar.readonly' 為 On，打包 Phar 需將其關閉（設為 Off），或執行：php -d phar.readonly=0 ./webman {command}",
                'phar_filename_required' => '請設定 phar 檔名（phar_filename）。',
            ],
            'en' => [
                'mkdir_build_dir_failed' => 'Failed to create Phar output directory. Please check permissions.',
                'bad_signature_algorithm' => 'Signature algorithm must be one of Phar::MD5, Phar::SHA1, Phar::SHA256, Phar::SHA512, or Phar::OPENSSL.',
                'openssl_private_key_missing' => "When signature algorithm is 'Phar::OPENSSL', you must configure the private key file.",
                'phar_extension_required' => "The 'phar' extension is required to build a Phar package.",
                'phar_readonly_on' => "In {ini}, 'phar.readonly' is On. Set it to Off to build Phar, or run: php -d phar.readonly=0 ./webman {command}",
                'phar_filename_required' => 'Please set the Phar filename (phar_filename).',
            ],
            'ja' => [
                'mkdir_build_dir_failed' => 'Phar 出力ディレクトリの作成に失敗しました。権限を確認してください。',
                'bad_signature_algorithm' => '署名アルゴリズムは Phar::MD5、Phar::SHA1、Phar::SHA256、Phar::SHA512、Phar::OPENSSL のいずれかである必要があります。',
                'openssl_private_key_missing' => "署名アルゴリズムが 'Phar::OPENSSL' の場合は、秘密鍵ファイルの設定が必要です。",
                'phar_extension_required' => "Phar のビルドには 'phar' 拡張が必要です。",
                'phar_readonly_on' => "{ini} で 'phar.readonly' が On です。Phar をビルドするには Off に設定するか、実行: php -d phar.readonly=0 ./webman {command}",
                'phar_filename_required' => 'Phar ファイル名（phar_filename）を設定してください。',
            ],
            'ko' => [
                'mkdir_build_dir_failed' => 'Phar 출력 디렉터리 생성에 실패했습니다. 권한을 확인하세요.',
                'bad_signature_algorithm' => '서명 알고리즘은 Phar::MD5, Phar::SHA1, Phar::SHA256, Phar::SHA512, Phar::OPENSSL 중 하나여야 합니다.',
                'openssl_private_key_missing' => "서명 알고리즘이 'Phar::OPENSSL'일 경우 private key 파일을 설정해야 합니다.",
                'phar_extension_required' => "Phar 패키지 빌드에는 'phar' 확장이 필요합니다.",
                'phar_readonly_on' => "{ini}에서 'phar.readonly'가 On입니다. Phar 빌드를 위해 Off로 설정하거나 실행: php -d phar.readonly=0 ./webman {command}",
                'phar_filename_required' => 'Phar 파일명(phar_filename)을 설정하세요.',
            ],
            'fr' => [
                'mkdir_build_dir_failed' => 'Échec de la création du répertoire de sortie Phar. Vérifiez les permissions.',
                'bad_signature_algorithm' => 'L\'algorithme de signature doit être Phar::MD5, Phar::SHA1, Phar::SHA256, Phar::SHA512 ou Phar::OPENSSL.',
                'openssl_private_key_missing' => "Quand l'algorithme est 'Phar::OPENSSL', vous devez configurer le fichier de clé privée.",
                'phar_extension_required' => "L'extension 'phar' est requise pour créer un Phar.",
                'phar_readonly_on' => "Dans {ini}, 'phar.readonly' est On. Passez à Off pour construire le Phar, ou exécutez : php -d phar.readonly=0 ./webman {command}",
                'phar_filename_required' => 'Veuillez définir le nom du fichier Phar (phar_filename).',
            ],
            'de' => [
                'mkdir_build_dir_failed' => 'Phar-Ausgabeverzeichnis konnte nicht erstellt werden. Bitte Berechtigungen prüfen.',
                'bad_signature_algorithm' => 'Signaturalgorithmus muss Phar::MD5, Phar::SHA1, Phar::SHA256, Phar::SHA512 oder Phar::OPENSSL sein.',
                'openssl_private_key_missing' => "Bei 'Phar::OPENSSL' muss die Private-Key-Datei konfiguriert werden.",
                'phar_extension_required' => "Die Extension 'phar' wird zum Erstellen eines Phar-Pakets benötigt.",
                'phar_readonly_on' => "In {ini} ist 'phar.readonly' auf On. Zum Erstellen auf Off setzen oder ausführen: php -d phar.readonly=0 ./webman {command}",
                'phar_filename_required' => 'Bitte den Phar-Dateinamen (phar_filename) setzen.',
            ],
            'es' => [
                'mkdir_build_dir_failed' => 'No se pudo crear el directorio de salida Phar. Compruebe los permisos.',
                'bad_signature_algorithm' => 'El algoritmo de firma debe ser Phar::MD5, Phar::SHA1, Phar::SHA256, Phar::SHA512 o Phar::OPENSSL.',
                'openssl_private_key_missing' => "Cuando el algoritmo es 'Phar::OPENSSL', debe configurar el archivo de clave privada.",
                'phar_extension_required' => "Se requiere la extensión 'phar' para construir un Phar.",
                'phar_readonly_on' => "En {ini}, 'phar.readonly' está On. Ponlo en Off para construir Phar, o ejecuta: php -d phar.readonly=0 ./webman {command}",
                'phar_filename_required' => 'Configure el nombre del archivo Phar (phar_filename).',
            ],
            'pt_BR' => [
                'mkdir_build_dir_failed' => 'Falha ao criar o diretório de saída do Phar. Verifique as permissões.',
                'bad_signature_algorithm' => 'O algoritmo de assinatura deve ser Phar::MD5, Phar::SHA1, Phar::SHA256, Phar::SHA512 ou Phar::OPENSSL.',
                'openssl_private_key_missing' => "Quando o algoritmo for 'Phar::OPENSSL', é necessário configurar o arquivo da chave privada.",
                'phar_extension_required' => "A extensão 'phar' é necessária para construir um Phar.",
                'phar_readonly_on' => "Em {ini}, 'phar.readonly' está On. Defina como Off para construir o Phar, ou execute: php -d phar.readonly=0 ./webman {command}",
                'phar_filename_required' => 'Defina o nome do arquivo Phar (phar_filename).',
            ],
            'ru' => [
                'mkdir_build_dir_failed' => 'Не удалось создать каталог для Phar. Проверьте права доступа.',
                'bad_signature_algorithm' => 'Алгоритм подписи должен быть Phar::MD5, Phar::SHA1, Phar::SHA256, Phar::SHA512 или Phar::OPENSSL.',
                'openssl_private_key_missing' => "При алгоритме 'Phar::OPENSSL' необходимо настроить файл закрытого ключа.",
                'phar_extension_required' => "Для сборки Phar требуется расширение 'phar'.",
                'phar_readonly_on' => "В {ini} параметр 'phar.readonly' включён. Установите Off для сборки Phar или выполните: php -d phar.readonly=0 ./webman {command}",
                'phar_filename_required' => 'Укажите имя файла Phar (phar_filename).',
            ],
            'vi' => [
                'mkdir_build_dir_failed' => 'Tạo thư mục xuất Phar thất bại. Vui lòng kiểm tra quyền.',
                'bad_signature_algorithm' => 'Thuật toán chữ ký phải là Phar::MD5, Phar::SHA1, Phar::SHA256, Phar::SHA512 hoặc Phar::OPENSSL.',
                'openssl_private_key_missing' => "Khi dùng 'Phar::OPENSSL' bạn phải cấu hình file private key.",
                'phar_extension_required' => "Cần bật extension 'phar' để build Phar.",
                'phar_readonly_on' => "Trong {ini}, 'phar.readonly' đang On. Đặt Off để build Phar, hoặc chạy: php -d phar.readonly=0 ./webman {command}",
                'phar_filename_required' => 'Vui lòng cấu hình tên file Phar (phar_filename).',
            ],
            'tr' => [
                'mkdir_build_dir_failed' => 'Phar çıktı dizini oluşturulamadı. İzinleri kontrol edin.',
                'bad_signature_algorithm' => 'İmza algoritması Phar::MD5, Phar::SHA1, Phar::SHA256, Phar::SHA512 veya Phar::OPENSSL olmalıdır.',
                'openssl_private_key_missing' => "'Phar::OPENSSL' kullanıldığında private key dosyası yapılandırılmalıdır.",
                'phar_extension_required' => "Phar oluşturmak için 'phar' eklentisi gerekir.",
                'phar_readonly_on' => "{ini} içinde 'phar.readonly' Açık. Phar için Kapatın veya çalıştırın: php -d phar.readonly=0 ./webman {command}",
                'phar_filename_required' => 'Phar dosya adını (phar_filename) ayarlayın.',
            ],
            'id' => [
                'mkdir_build_dir_failed' => 'Gagal membuat direktori keluaran Phar. Periksa izin.',
                'bad_signature_algorithm' => 'Algoritma tanda tangan harus Phar::MD5, Phar::SHA1, Phar::SHA256, Phar::SHA512, atau Phar::OPENSSL.',
                'openssl_private_key_missing' => "Jika algoritma 'Phar::OPENSSL', Anda harus mengonfigurasi file private key.",
                'phar_extension_required' => "Ekstensi 'phar' diperlukan untuk membuat Phar.",
                'phar_readonly_on' => "Di {ini}, 'phar.readonly' On. Setel Off untuk build Phar, atau jalankan: php -d phar.readonly=0 ./webman {command}",
                'phar_filename_required' => 'Atur nama file Phar (phar_filename).',
            ],
            'th' => [
                'mkdir_build_dir_failed' => 'สร้างไดเรกทอรีเอาต์พุต Phar ไม่สำเร็จ กรุณาตรวจสอบสิทธิ์',
                'bad_signature_algorithm' => 'อัลกอริทึมลายเซ็นต้องเป็น Phar::MD5, Phar::SHA1, Phar::SHA256, Phar::SHA512 หรือ Phar::OPENSSL',
                'openssl_private_key_missing' => "เมื่อใช้อัลกอริทึม 'Phar::OPENSSL' ต้องกำหนดค่าไฟล์ private key",
                'phar_extension_required' => "ต้องเปิดใช้ extension 'phar' เพื่อ build Phar",
                'phar_readonly_on' => "ใน {ini} 'phar.readonly' เป็น On ตั้งเป็น Off เพื่อ build Phar หรือรัน: php -d phar.readonly=0 ./webman {command}",
                'phar_filename_required' => 'กรุณาตั้งค่าชื่อไฟล์ Phar (phar_filename)',
            ],
        ];
        $map = Util::selectLocaleMessages($messages);
        $text = $map[$key] ?? $key;
        return $replace ? strtr($text, $replace) : $text;
    }

    protected function getPhpIniDisplayPath(): string
    {
        $loaded = php_ini_loaded_file();
        if (is_string($loaded) && $loaded !== '') {
            return $loaded;
        }
        return Util::selectByLocale([
            'zh_CN' => 'php.ini（未加载）', 'zh_TW' => 'php.ini（未載入）', 'en' => 'php.ini (not loaded)',
            'ja' => 'php.ini（未読み込み）', 'ko' => 'php.ini(로드되지 않음)', 'fr' => 'php.ini (non chargé)',
            'de' => 'php.ini (nicht geladen)', 'es' => 'php.ini (no cargado)', 'pt_BR' => 'php.ini (não carregado)',
            'ru' => 'php.ini (не загружен)', 'vi' => 'php.ini (chưa tải)', 'tr' => 'php.ini (yüklenmedi)',
            'id' => 'php.ini (tidak dimuat)', 'th' => 'php.ini (ไม่ได้โหลด)',
        ]);
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
