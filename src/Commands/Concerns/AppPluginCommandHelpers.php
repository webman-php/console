<?php

namespace Webman\Console\Commands\Concerns;

use Symfony\Component\Console\Output\OutputInterface;
use Webman\Console\Util;

/**
 * Helpers for AppPlugin* commands (plugin/<name>).
 */
trait AppPluginCommandHelpers
{
    use MakeCommandHelpers;

    /**
     * Prefer admin plugin locale if available, fallback to global translation/app locale.
     *
     * @return string
     */
    protected function getLocale(): string
    {
        $locale = null;
        if (function_exists('config')) {
            $locale = config('plugin.admin.translation.locale')
                ?: config('translation.locale')
                ?: config('app.locale');
        }

        $locale = is_string($locale) ? trim($locale) : '';
        if ($locale === '') {
            $locale = $this->resolveLocaleFromAdminTranslationConfigFile() ?? '';
        }

        return $locale !== '' ? $locale : Util::getLocale();
    }

    /**
     * Resolve locale from admin plugin translation config file.
     * This is a fallback when config() is not ready or the admin plugin is not loaded.
     *
     * @return string|null
     */
    protected function resolveLocaleFromAdminTranslationConfigFile(): ?string
    {
        $ds = DIRECTORY_SEPARATOR;
        $candidates = [
            base_path('plugin' . $ds . 'admin' . $ds . 'config' . $ds . 'translation.php'),
            base_path('vendor' . $ds . 'webman' . $ds . 'admin' . $ds . 'src' . $ds . 'plugin' . $ds . 'admin' . $ds . 'config' . $ds . 'translation.php'),
        ];

        foreach ($candidates as $file) {
            $config = $this->loadPhpConfigArray($file);
            if ($config === null || $config === []) {
                continue;
            }
            $locale = $config['locale'] ?? null;
            $locale = is_string($locale) ? trim($locale) : '';
            if ($locale !== '') {
                return $locale;
            }
        }
        return null;
    }

    /**
     * @param mixed $value
     * @return string
     */
    protected function normalizeAppPluginName(mixed $value): string
    {
        return trim((string)$value);
    }

    /**
     * App plugin name is a folder name under plugin/<name>.
     *
     * @param string $name
     * @return bool
     */
    protected function isValidAppPluginName(string $name): bool
    {
        if ($name === '') {
            return false;
        }
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            return false;
        }
        // Keep it safe for directory/namespace usage.
        return (bool)preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $name);
    }

    /**
     * @param string $name
     * @return string
     */
    protected function appPluginBasePath(string $name): string
    {
        return base_path('plugin' . DIRECTORY_SEPARATOR . $name);
    }

    /**
     * @param string $name
     * @return string
     */
    protected function appPluginInstallClass(string $name): string
    {
        return "\\plugin\\{$name}\\api\\Install";
    }

    /**
     * @param string $name
     * @return string
     */
    protected function appPluginVersion(string $name): string
    {
        $v = config("plugin.$name.app.version");
        $v = is_string($v) ? trim($v) : '';
        return $v !== '' ? $v : '1.0.0';
    }

    /**
     * Safely call plugin Install static method with signature tolerance.
     *
     * @param class-string $class
     * @param string $method
     * @param array<int,mixed> $args
     * @return mixed
     */
    protected function callInstallMethod(string $class, string $method, array $args): mixed
    {
        if (!class_exists($class)) {
            throw new \RuntimeException("Class $class not exists");
        }
        if (!method_exists($class, $method)) {
            throw new \RuntimeException("Method $class::$method not exists");
        }

        $ref = new \ReflectionMethod($class, $method);
        $required = $ref->getNumberOfRequiredParameters();
        $total = $ref->getNumberOfParameters();

        if (count($args) < $required) {
            throw new \RuntimeException("Method $class::$method requires $required parameter(s)");
        }

        $useArgs = array_slice($args, 0, $total);
        return $ref->invokeArgs(null, $useArgs);
    }

    /**
     * @param \Throwable $e
     * @return bool
     */
    protected function isScriptMissingThrowable(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, ' not exists') && (str_starts_with($msg, 'Class ') || str_starts_with($msg, 'Method '));
    }

    /**
     * Supported locales and CLI message keys for app plugin commands.
     * Fallback order: exact locale -> language prefix -> en -> zh_CN -> first.
     *
     * @return array<string, array<string, string>>
     */
    protected function getAppPluginMessageMap(): array
    {
        $zhCn = [
            'bad_name' => "<error>插件名无效：{name}</error>\n<comment>要求</comment> 只能是 plugin/ 目录下的文件夹名，且仅允许字母数字、下划线、连字符（不能包含 / 或 \\）。",
            'plugin_not_exists' => "<error>插件不存在：</error> {path}",
            'create_title' => "<info>创建 App 插件</info> <comment>{name}</comment>",
            'install_title' => "<info>安装 App 插件</info> <comment>{name}</comment>",
            'uninstall_title' => "<info>卸载 App 插件</info> <comment>{name}</comment>",
            'update_title' => "<info>更新 App 插件</info> <comment>{name}</comment>",
            'zip_title' => "<info>打包 App 插件</info> <comment>{name}</comment>",
            'dir_exists' => "<error>目录已存在：</error> {path}",
            'created_dir' => "<info>创建目录：</info> {path}",
            'created_file' => "<info>创建文件：</info> {path}",
            'script_missing' => "<error>未找到安装脚本：</error> {class}\n<comment>提示</comment> 如刚修改过 composer.json，请先执行：<info>composer dumpautoload</info>",
            'uninstall_confirm' => "<fg=yellow>即将执行卸载脚本，可能会删除数据，是否继续？[y/N]（回车=N）</>\n",
            'running' => "<comment>执行</comment> {class}::{method}({args})",
            'done' => "<info>完成</info>",
            'failed' => "<error>失败：</error> {error}",
            'version_same' => "<comment>提示</comment> from/to 版本相同（{version}），若无迁移逻辑可忽略。",
            'zip_saved' => "<info>已生成：</info> {path}",
            'zip_delete_failed' => "<error>无法删除旧的 zip 文件：</error> {path}",
            'zip_open_failed' => "<error>无法创建 zip 文件：</error> {path}",
        ];

        $zhTw = [
            'bad_name' => "<error>插件名稱無效：{name}</error>\n<comment>要求</comment> 只能是 plugin/ 目錄下的資料夾名，且僅允許字母數字、底線、連字元（不能包含 / 或 \\）。",
            'plugin_not_exists' => "<error>插件不存在：</error> {path}",
            'create_title' => "<info>建立 App 插件</info> <comment>{name}</comment>",
            'install_title' => "<info>安裝 App 插件</info> <comment>{name}</comment>",
            'uninstall_title' => "<info>卸載 App 插件</info> <comment>{name}</comment>",
            'update_title' => "<info>更新 App 插件</info> <comment>{name}</comment>",
            'zip_title' => "<info>打包 App 插件</info> <comment>{name}</comment>",
            'dir_exists' => "<error>目錄已存在：</error> {path}",
            'created_dir' => "<info>建立目錄：</info> {path}",
            'created_file' => "<info>建立檔案：</info> {path}",
            'script_missing' => "<error>未找到安裝腳本：</error> {class}\n<comment>提示</comment> 如剛修改過 composer.json，請先執行：<info>composer dumpautoload</info>",
            'uninstall_confirm' => "<fg=yellow>即將執行卸載腳本，可能會刪除資料，是否繼續？[y/N]（Enter=N）</>\n",
            'running' => "<comment>執行</comment> {class}::{method}({args})",
            'done' => "<info>完成</info>",
            'failed' => "<error>失敗：</error> {error}",
            'version_same' => "<comment>提示</comment> from/to 版本相同（{version}），若無遷移邏輯可忽略。",
            'zip_saved' => "<info>已產生：</info> {path}",
            'zip_delete_failed' => "<error>無法刪除舊的 zip 檔案：</error> {path}",
            'zip_open_failed' => "<error>無法建立 zip 檔案：</error> {path}",
        ];

        $en = [
            'bad_name' => "<error>Invalid plugin name: {name}</error>\n<comment>Rules</comment> Must be a folder name under plugin/, and only allows letters/digits/underscore/hyphen (must not contain / or \\).",
            'plugin_not_exists' => "<error>Plugin not found:</error> {path}",
            'create_title' => "<info>Create App plugin</info> <comment>{name}</comment>",
            'install_title' => "<info>Install App plugin</info> <comment>{name}</comment>",
            'uninstall_title' => "<info>Uninstall App plugin</info> <comment>{name}</comment>",
            'update_title' => "<info>Update App plugin</info> <comment>{name}</comment>",
            'zip_title' => "<info>Zip App plugin</info> <comment>{name}</comment>",
            'dir_exists' => "<error>Directory already exists:</error> {path}",
            'created_dir' => "<info>Create dir:</info> {path}",
            'created_file' => "<info>Create file:</info> {path}",
            'script_missing' => "<error>Install script not found:</error> {class}\n<comment>Note</comment> If you just changed composer.json, please run: <info>composer dumpautoload</info>",
            'uninstall_confirm' => "<fg=yellow>It will execute uninstall script and may delete data. Continue? [y/N] (Enter = N)</>\n",
            'running' => "<comment>Run</comment> {class}::{method}({args})",
            'done' => "<info>Done</info>",
            'failed' => "<error>Failed:</error> {error}",
            'version_same' => "<comment>Note</comment> from/to versions are the same ({version}). You can ignore this if no migration is needed.",
            'zip_saved' => "<info>Generated:</info> {path}",
            'zip_delete_failed' => "<error>Unable to delete existing zip file:</error> {path}",
            'zip_open_failed' => "<error>Cannot create zip file:</error> {path}",
        ];

        $ja = [
            'bad_name' => "<error>無効なプラグイン名：{name}</error>\n<comment>ルール</comment> plugin/ 以下のフォルダ名のみ。英数字・アンダースコア・ハイフンのみ使用可（/ または \\ は不可）。",
            'plugin_not_exists' => "<error>プラグインが見つかりません：</error> {path}",
            'create_title' => "<info>App プラグインを作成</info> <comment>{name}</comment>",
            'install_title' => "<info>App プラグインをインストール</info> <comment>{name}</comment>",
            'uninstall_title' => "<info>App プラグインをアンインストール</info> <comment>{name}</comment>",
            'update_title' => "<info>App プラグインを更新</info> <comment>{name}</comment>",
            'zip_title' => "<info>App プラグインをZIP化</info> <comment>{name}</comment>",
            'dir_exists' => "<error>ディレクトリが既に存在します：</error> {path}",
            'created_dir' => "<info>ディレクトリを作成：</info> {path}",
            'created_file' => "<info>ファイルを作成：</info> {path}",
            'script_missing' => "<error>インストールスクリプトが見つかりません：</error> {class}\n<comment>注意</comment> composer.json を変更した場合は <info>composer dumpautoload</info> を実行してください。",
            'uninstall_confirm' => "<fg=yellow>アンインストールスクリプトを実行します。データが削除される可能性があります。続行しますか？[y/N]（Enter=N）</>\n",
            'running' => "<comment>実行</comment> {class}::{method}({args})",
            'done' => "<info>完了</info>",
            'failed' => "<error>失敗：</error> {error}",
            'version_same' => "<comment>注意</comment> from/to のバージョンが同じです（{version}）。マイグレーションが不要な場合は無視して構いません。",
            'zip_saved' => "<info>生成しました：</info> {path}",
            'zip_delete_failed' => "<error>既存の zip ファイルを削除できません：</error> {path}",
            'zip_open_failed' => "<error>zip ファイルを作成できません：</error> {path}",
        ];

        $ko = [
            'bad_name' => "<error>잘못된 플러그인 이름: {name}</error>\n<comment>규칙</comment> plugin/ 아래 폴더 이름만 허용되며, 영문/숫자/밑줄/하이픈만 사용 가능합니다（/ 또는 \\ 포함 불가）.",
            'plugin_not_exists' => "<error>플러그인을 찾을 수 없습니다:</error> {path}",
            'create_title' => "<info>App 플러그인 생성</info> <comment>{name}</comment>",
            'install_title' => "<info>App 플러그인 설치</info> <comment>{name}</comment>",
            'uninstall_title' => "<info>App 플러그인 제거</info> <comment>{name}</comment>",
            'update_title' => "<info>App 플러그인 업데이트</info> <comment>{name}</comment>",
            'zip_title' => "<info>App 플러그인 ZIP 패키징</info> <comment>{name}</comment>",
            'dir_exists' => "<error>디렉터리가 이미 존재합니다:</error> {path}",
            'created_dir' => "<info>디렉터리 생성:</info> {path}",
            'created_file' => "<info>파일 생성:</info> {path}",
            'script_missing' => "<error>설치 스크립트를 찾을 수 없습니다:</error> {class}\n<comment>참고</comment> composer.json을 수정한 경우 <info>composer dumpautoload</info> 를 실행하세요.",
            'uninstall_confirm' => "<fg=yellow>제거 스크립트를 실행하면 데이터가 삭제될 수 있습니다. 계속하시겠습니까? [y/N] (Enter=N)</>\n",
            'running' => "<comment>실행</comment> {class}::{method}({args})",
            'done' => "<info>완료</info>",
            'failed' => "<error>실패:</error> {error}",
            'version_same' => "<comment>참고</comment> from/to 버전이 동일합니다（{version}）. 마이그레이션이 필요 없으면 무시해도 됩니다.",
            'zip_saved' => "<info>생성됨:</info> {path}",
            'zip_delete_failed' => "<error>기존 zip 파일을 삭제할 수 없습니다:</error> {path}",
            'zip_open_failed' => "<error>zip 파일을 생성할 수 없습니다:</error> {path}",
        ];

        $fr = [
            'bad_name' => "<error>Nom de plugin invalide : {name}</error>\n<comment>Règles</comment> Doit être un nom de dossier sous plugin/, uniquement lettres/chiffres/tiret souligné/tiret (ne doit pas contenir / ou \\).",
            'plugin_not_exists' => "<error>Plugin introuvable :</error> {path}",
            'create_title' => "<info>Créer le plugin App</info> <comment>{name}</comment>",
            'install_title' => "<info>Installer le plugin App</info> <comment>{name}</comment>",
            'uninstall_title' => "<info>Désinstaller le plugin App</info> <comment>{name}</comment>",
            'update_title' => "<info>Mettre à jour le plugin App</info> <comment>{name}</comment>",
            'zip_title' => "<info>Zipper le plugin App</info> <comment>{name}</comment>",
            'dir_exists' => "<error>Le répertoire existe déjà :</error> {path}",
            'created_dir' => "<info>Créer le répertoire :</info> {path}",
            'created_file' => "<info>Créer le fichier :</info> {path}",
            'script_missing' => "<error>Script d'installation introuvable :</error> {class}\n<comment>Note</comment> Si vous venez de modifier composer.json, exécutez : <info>composer dumpautoload</info>",
            'uninstall_confirm' => "<fg=yellow>Le script de désinstallation va s'exécuter et peut supprimer des données. Continuer ? [y/N] (Entrée = N)</>\n",
            'running' => "<comment>Exécution</comment> {class}::{method}({args})",
            'done' => "<info>Terminé</info>",
            'failed' => "<error>Échec :</error> {error}",
            'version_same' => "<comment>Note</comment> Les versions from/to sont identiques ({version}). Ignorez si aucune migration n'est nécessaire.",
            'zip_saved' => "<info>Généré :</info> {path}",
            'zip_delete_failed' => "<error>Impossible de supprimer le fichier zip existant :</error> {path}",
            'zip_open_failed' => "<error>Impossible de créer le fichier zip :</error> {path}",
        ];

        $de = [
            'bad_name' => "<error>Ungültiger Plugin-Name: {name}</error>\n<comment>Regeln</comment> Muss ein Ordnername unter plugin/ sein, nur Buchstaben/Ziffern/Unterstrich/Bindestrich (darf / oder \\ nicht enthalten).",
            'plugin_not_exists' => "<error>Plugin nicht gefunden:</error> {path}",
            'create_title' => "<info>App-Plugin erstellen</info> <comment>{name}</comment>",
            'install_title' => "<info>App-Plugin installieren</info> <comment>{name}</comment>",
            'uninstall_title' => "<info>App-Plugin deinstallieren</info> <comment>{name}</comment>",
            'update_title' => "<info>App-Plugin aktualisieren</info> <comment>{name}</comment>",
            'zip_title' => "<info>App-Plugin als ZIP packen</info> <comment>{name}</comment>",
            'dir_exists' => "<error>Verzeichnis existiert bereits:</error> {path}",
            'created_dir' => "<info>Verzeichnis erstellen:</info> {path}",
            'created_file' => "<info>Datei erstellen:</info> {path}",
            'script_missing' => "<error>Installationsskript nicht gefunden:</error> {class}\n<comment>Hinweis</comment> Bei Änderung von composer.json bitte ausführen: <info>composer dumpautoload</info>",
            'uninstall_confirm' => "<fg=yellow>Deinstallationsskript wird ausgeführt und kann Daten löschen. Fortfahren? [y/N] (Eingabe = N)</>\n",
            'running' => "<comment>Ausführen</comment> {class}::{method}({args})",
            'done' => "<info>Fertig</info>",
            'failed' => "<error>Fehlgeschlagen:</error> {error}",
            'version_same' => "<comment>Hinweis</comment> from/to-Versionen sind gleich ({version}). Kann ignoriert werden, wenn keine Migration nötig ist.",
            'zip_saved' => "<info>Erstellt:</info> {path}",
            'zip_delete_failed' => "<error>Vorhandene ZIP-Datei kann nicht gelöscht werden:</error> {path}",
            'zip_open_failed' => "<error>ZIP-Datei kann nicht erstellt werden:</error> {path}",
        ];

        $es = [
            'bad_name' => "<error>Nombre de plugin no válido: {name}</error>\n<comment>Reglas</comment> Debe ser un nombre de carpeta bajo plugin/, solo letras/números/guion bajo/guion (no debe contener / o \\).",
            'plugin_not_exists' => "<error>Plugin no encontrado:</error> {path}",
            'create_title' => "<info>Crear plugin App</info> <comment>{name}</comment>",
            'install_title' => "<info>Instalar plugin App</info> <comment>{name}</comment>",
            'uninstall_title' => "<info>Desinstalar plugin App</info> <comment>{name}</comment>",
            'update_title' => "<info>Actualizar plugin App</info> <comment>{name}</comment>",
            'zip_title' => "<info>Comprimir plugin App en ZIP</info> <comment>{name}</comment>",
            'dir_exists' => "<error>El directorio ya existe:</error> {path}",
            'created_dir' => "<info>Crear directorio:</info> {path}",
            'created_file' => "<info>Crear archivo:</info> {path}",
            'script_missing' => "<error>Script de instalación no encontrado:</error> {class}\n<comment>Nota</comment> Si acaba de modificar composer.json, ejecute: <info>composer dumpautoload</info>",
            'uninstall_confirm' => "<fg=yellow>Se ejecutará el script de desinstalación y puede borrar datos. ¿Continuar? [y/N] (Enter = N)</>\n",
            'running' => "<comment>Ejecutar</comment> {class}::{method}({args})",
            'done' => "<info>Hecho</info>",
            'failed' => "<error>Error:</error> {error}",
            'version_same' => "<comment>Nota</comment> Las versiones from/to son iguales ({version}). Puede ignorarse si no hay migración.",
            'zip_saved' => "<info>Generado:</info> {path}",
            'zip_delete_failed' => "<error>No se puede eliminar el archivo zip existente:</error> {path}",
            'zip_open_failed' => "<error>No se puede crear el archivo zip:</error> {path}",
        ];

        $ptBr = [
            'bad_name' => "<error>Nome de plugin inválido: {name}</error>\n<comment>Regras</comment> Deve ser um nome de pasta em plugin/, apenas letras/números/underscore/hífen (não pode conter / ou \\).",
            'plugin_not_exists' => "<error>Plugin não encontrado:</error> {path}",
            'create_title' => "<info>Criar plugin App</info> <comment>{name}</comment>",
            'install_title' => "<info>Instalar plugin App</info> <comment>{name}</comment>",
            'uninstall_title' => "<info>Desinstalar plugin App</info> <comment>{name}</comment>",
            'update_title' => "<info>Atualizar plugin App</info> <comment>{name}</comment>",
            'zip_title' => "<info>Empacotar plugin App em ZIP</info> <comment>{name}</comment>",
            'dir_exists' => "<error>O diretório já existe:</error> {path}",
            'created_dir' => "<info>Criar diretório:</info> {path}",
            'created_file' => "<info>Criar arquivo:</info> {path}",
            'script_missing' => "<error>Script de instalação não encontrado:</error> {class}\n<comment>Nota</comment> Se você acabou de alterar o composer.json, execute: <info>composer dumpautoload</info>",
            'uninstall_confirm' => "<fg=yellow>O script de desinstalação será executado e pode excluir dados. Continuar? [y/N] (Enter = N)</>\n",
            'running' => "<comment>Executar</comment> {class}::{method}({args})",
            'done' => "<info>Concluído</info>",
            'failed' => "<error>Falhou:</error> {error}",
            'version_same' => "<comment>Nota</comment> As versões from/to são iguais ({version}). Pode ignorar se não houver migração.",
            'zip_saved' => "<info>Gerado:</info> {path}",
            'zip_delete_failed' => "<error>Não foi possível excluir o arquivo zip existente:</error> {path}",
            'zip_open_failed' => "<error>Não foi possível criar o arquivo zip:</error> {path}",
        ];

        $ru = [
            'bad_name' => "<error>Недопустимое имя плагина: {name}</error>\n<comment>Правила</comment> Должно быть именем папки в plugin/, только буквы/цифры/подчёркивание/дефис (не должно содержать / или \\).",
            'plugin_not_exists' => "<error>Плагин не найден:</error> {path}",
            'create_title' => "<info>Создать App-плагин</info> <comment>{name}</comment>",
            'install_title' => "<info>Установить App-плагин</info> <comment>{name}</comment>",
            'uninstall_title' => "<info>Удалить App-плагин</info> <comment>{name}</comment>",
            'update_title' => "<info>Обновить App-плагин</info> <comment>{name}</comment>",
            'zip_title' => "<info>Упаковать App-плагин в ZIP</info> <comment>{name}</comment>",
            'dir_exists' => "<error>Каталог уже существует:</error> {path}",
            'created_dir' => "<info>Создать каталог:</info> {path}",
            'created_file' => "<info>Создать файл:</info> {path}",
            'script_missing' => "<error>Скрипт установки не найден:</error> {class}\n<comment>Примечание</comment> Если вы только что изменили composer.json, выполните: <info>composer dumpautoload</info>",
            'uninstall_confirm' => "<fg=yellow>Будет выполнен скрипт удаления, данные могут быть удалены. Продолжить? [y/N] (Enter = N)</>\n",
            'running' => "<comment>Выполнить</comment> {class}::{method}({args})",
            'done' => "<info>Готово</info>",
            'failed' => "<error>Ошибка:</error> {error}",
            'version_same' => "<comment>Примечание</comment> Версии from/to совпадают ({version}). Можно игнорировать, если миграция не нужна.",
            'zip_saved' => "<info>Создано:</info> {path}",
            'zip_delete_failed' => "<error>Не удалось удалить существующий zip-файл:</error> {path}",
            'zip_open_failed' => "<error>Не удалось создать zip-файл:</error> {path}",
        ];

        $vi = [
            'bad_name' => "<error>Tên plugin không hợp lệ: {name}</error>\n<comment>Quy tắc</comment> Phải là tên thư mục trong plugin/, chỉ cho phép chữ/số/gạch dưới/gạch ngang (không được chứa / hoặc \\).",
            'plugin_not_exists' => "<error>Không tìm thấy plugin:</error> {path}",
            'create_title' => "<info>Tạo plugin App</info> <comment>{name}</comment>",
            'install_title' => "<info>Cài đặt plugin App</info> <comment>{name}</comment>",
            'uninstall_title' => "<info>Gỡ cài đặt plugin App</info> <comment>{name}</comment>",
            'update_title' => "<info>Cập nhật plugin App</info> <comment>{name}</comment>",
            'zip_title' => "<info>Đóng gói plugin App thành ZIP</info> <comment>{name}</comment>",
            'dir_exists' => "<error>Thư mục đã tồn tại:</error> {path}",
            'created_dir' => "<info>Tạo thư mục:</info> {path}",
            'created_file' => "<info>Tạo tệp:</info> {path}",
            'script_missing' => "<error>Không tìm thấy script cài đặt:</error> {class}\n<comment>Lưu ý</comment> Nếu vừa sửa composer.json, hãy chạy: <info>composer dumpautoload</info>",
            'uninstall_confirm' => "<fg=yellow>Script gỡ cài đặt sẽ chạy và có thể xóa dữ liệu. Tiếp tục? [y/N] (Enter = N)</>\n",
            'running' => "<comment>Chạy</comment> {class}::{method}({args})",
            'done' => "<info>Xong</info>",
            'failed' => "<error>Thất bại:</error> {error}",
            'version_same' => "<comment>Lưu ý</comment> Phiên bản from/to giống nhau ({version}). Có thể bỏ qua nếu không cần di chuyển.",
            'zip_saved' => "<info>Đã tạo:</info> {path}",
            'zip_delete_failed' => "<error>Không thể xóa tệp zip hiện có:</error> {path}",
            'zip_open_failed' => "<error>Không thể tạo tệp zip:</error> {path}",
        ];

        $tr = [
            'bad_name' => "<error>Geçersiz eklenti adı: {name}</error>\n<comment>Kurallar</comment> plugin/ altında bir klasör adı olmalı, yalnızca harf/rakam/alt çizgi/tire (/ veya \\ içermemeli).",
            'plugin_not_exists' => "<error>Eklenti bulunamadı:</error> {path}",
            'create_title' => "<info>App eklentisi oluştur</info> <comment>{name}</comment>",
            'install_title' => "<info>App eklentisini yükle</info> <comment>{name}</comment>",
            'uninstall_title' => "<info>App eklentisini kaldır</info> <comment>{name}</comment>",
            'update_title' => "<info>App eklentisini güncelle</info> <comment>{name}</comment>",
            'zip_title' => "<info>App eklentisini ZIP'le</info> <comment>{name}</comment>",
            'dir_exists' => "<error>Dizin zaten mevcut:</error> {path}",
            'created_dir' => "<info>Dizin oluştur:</info> {path}",
            'created_file' => "<info>Dosya oluştur:</info> {path}",
            'script_missing' => "<error>Kurulum betiği bulunamadı:</error> {class}\n<comment>Not</comment> composer.json'ı yeni değiştirdiyseniz çalıştırın: <info>composer dumpautoload</info>",
            'uninstall_confirm' => "<fg=yellow>Kaldırma betiği çalışacak ve veriler silinebilir. Devam? [y/N] (Enter = N)</>\n",
            'running' => "<comment>Çalıştır</comment> {class}::{method}({args})",
            'done' => "<info>Tamamlandı</info>",
            'failed' => "<error>Başarısız:</error> {error}",
            'version_same' => "<comment>Not</comment> from/to sürümleri aynı ({version}). Taşıma gerekmiyorsa yok sayabilirsiniz.",
            'zip_saved' => "<info>Oluşturuldu:</info> {path}",
            'zip_delete_failed' => "<error>Mevcut zip dosyası silinemiyor:</error> {path}",
            'zip_open_failed' => "<error>Zip dosyası oluşturulamıyor:</error> {path}",
        ];

        $id = [
            'bad_name' => "<error>Nama plugin tidak valid: {name}</error>\n<comment>Aturan</comment> Harus nama folder di bawah plugin/, hanya huruf/angka/underscore/tanda hubung (tidak boleh mengandung / atau \\).",
            'plugin_not_exists' => "<error>Plugin tidak ditemukan:</error> {path}",
            'create_title' => "<info>Buat plugin App</info> <comment>{name}</comment>",
            'install_title' => "<info>Pasang plugin App</info> <comment>{name}</comment>",
            'uninstall_title' => "<info>Copot plugin App</info> <comment>{name}</comment>",
            'update_title' => "<info>Perbarui plugin App</info> <comment>{name}</comment>",
            'zip_title' => "<info>Bungkus plugin App ke ZIP</info> <comment>{name}</comment>",
            'dir_exists' => "<error>Direktori sudah ada:</error> {path}",
            'created_dir' => "<info>Buat direktori:</info> {path}",
            'created_file' => "<info>Buat file:</info> {path}",
            'script_missing' => "<error>Skrip instalasi tidak ditemukan:</error> {class}\n<comment>Catatan</comment> Jika Anda baru mengubah composer.json, jalankan: <info>composer dumpautoload</info>",
            'uninstall_confirm' => "<fg=yellow>Skrip copot akan dijalankan dan dapat menghapus data. Lanjutkan? [y/N] (Enter = N)</>\n",
            'running' => "<comment>Jalankan</comment> {class}::{method}({args})",
            'done' => "<info>Selesai</info>",
            'failed' => "<error>Gagal:</error> {error}",
            'version_same' => "<comment>Catatan</comment> Versi from/to sama ({version}). Dapat diabaikan jika tidak ada migrasi.",
            'zip_saved' => "<info>Berhasil dibuat:</info> {path}",
            'zip_delete_failed' => "<error>Tidak dapat menghapus file zip yang ada:</error> {path}",
            'zip_open_failed' => "<error>Tidak dapat membuat file zip:</error> {path}",
        ];

        $th = [
            'bad_name' => "<error>ชื่อปลั๊กอินไม่ถูกต้อง: {name}</error>\n<comment>กฎ</comment> ต้องเป็นชื่อโฟลเดอร์ภายใต้ plugin/ เท่านั้น ใช้ได้แค่ตัวอักษร/ตัวเลข/ขีดล่าง/ขีด (ห้ามมี / หรือ \\)",
            'plugin_not_exists' => "<error>ไม่พบปลั๊กอิน:</error> {path}",
            'create_title' => "<info>สร้างปลั๊กอิน App</info> <comment>{name}</comment>",
            'install_title' => "<info>ติดตั้งปลั๊กอิน App</info> <comment>{name}</comment>",
            'uninstall_title' => "<info>ถอนการติดตั้งปลั๊กอิน App</info> <comment>{name}</comment>",
            'update_title' => "<info>อัปเดตปลั๊กอิน App</info> <comment>{name}</comment>",
            'zip_title' => "<info>บีบอัดปลั๊กอิน App เป็น ZIP</info> <comment>{name}</comment>",
            'dir_exists' => "<error>มีไดเรกทอรีอยู่แล้ว:</error> {path}",
            'created_dir' => "<info>สร้างไดเรกทอรี:</info> {path}",
            'created_file' => "<info>สร้างไฟล์:</info> {path}",
            'script_missing' => "<error>ไม่พบสคริปต์ติดตั้ง:</error> {class}\n<comment>หมายเหตุ</comment> หากเพิ่งแก้ composer.json กรุณารัน: <info>composer dumpautoload</info>",
            'uninstall_confirm' => "<fg=yellow>จะรันสคริปต์ถอนการติดตั้งและอาจลบข้อมูล ดำเนินการต่อ? [y/N] (Enter = N)</>\n",
            'running' => "<comment>รัน</comment> {class}::{method}({args})",
            'done' => "<info>เสร็จสิ้น</info>",
            'failed' => "<error>ล้มเหลว:</error> {error}",
            'version_same' => "<comment>หมายเหตุ</comment> เวอร์ชัน from/to เหมือนกัน ({version}) ข้ามได้หากไม่มีการย้ายข้อมูล",
            'zip_saved' => "<info>สร้างแล้ว:</info> {path}",
            'zip_delete_failed' => "<error>ลบไฟล์ zip เดิมไม่ได้:</error> {path}",
            'zip_open_failed' => "<error>สร้างไฟล์ zip ไม่ได้:</error> {path}",
        ];

        return [
            'zh_CN' => $zhCn,
            'zh_TW' => $zhTw,
            'en' => $en,
            'ja' => $ja,
            'ko' => $ko,
            'fr' => $fr,
            'de' => $de,
            'es' => $es,
            'pt_BR' => $ptBr,
            'ru' => $ru,
            'vi' => $vi,
            'tr' => $tr,
            'id' => $id,
            'th' => $th,
        ];
    }

    /**
     * CLI messages for app plugin commands. Locale selected by getLocale() / Util fallback.
     *
     * @param string $key
     * @param array<string,string> $replace
     * @return string
     */
    protected function msg(string $key, array $replace = []): string
    {
        $map = Util::selectLocaleMessages($this->getAppPluginMessageMap());
        $text = $map[$key] ?? $key;
        return $replace ? strtr($text, $replace) : $text;
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     * @return void
     */
    protected function writeln(OutputInterface $output, string $message): void
    {
        $output->writeln($message);
    }
}

