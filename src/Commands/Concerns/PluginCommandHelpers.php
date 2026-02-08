<?php

namespace Webman\Console\Commands\Concerns;

use Webman\Console\Commands\Concerns\MakeCommandHelpers;
use Webman\Console\Util;

trait PluginCommandHelpers
{
    use MakeCommandHelpers;

    /**
     * Normalize plugin name input and canonicalize it to lowercase.
     *
     * @param mixed $value
     * @return string|null
     */
    protected function normalizePluginName(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        return $value === '' ? null : strtolower($value);
    }

    /**
     * Validate composer package name "vendor/name".
     * We accept input and canonicalize to lowercase in normalizePluginName().
     *
     * @param string $name lowercase package name
     * @return bool
     */
    protected function isValidComposerPackageName(string $name): bool
    {
        if (substr_count($name, '/') !== 1) {
            return false;
        }
        return (bool)preg_match('/^[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?$/', $name);
    }

    /**
     * Check whether a plugin package exists in current project.
     *
     * Rules:
     * - Prefer directory existence under "<project>/vendor/<vendor>/<name>" (works for both composer-installed and local plugin skeletons).
     * - Fallback to Composer runtime API when available.
     *
     * @param string $name composer package name in vendor/name format (lowercase recommended)
     * @return bool
     */
    protected function pluginPackageExists(string $name): bool
    {
        $relativeVendorPath = 'vendor' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
        $vendorDir = base_path() . DIRECTORY_SEPARATOR . $relativeVendorPath;
        if (is_dir($vendorDir)) {
            return true;
        }

        if (class_exists(\Composer\InstalledVersions::class)) {
            return \Composer\InstalledVersions::isInstalled($name);
        }

        return false;
    }

    /**
     * Update "enable" flag in config/plugin/<name>/app.php with minimal diffs.
     *
     * @param string $configFile
     * @param bool $enable
     * @return array{ok:bool,changed:bool,already:bool,missingFile:bool,missingKey:bool,error:string|null}
     */
    protected function setPluginEnableFlag(string $configFile, bool $enable): array
    {
        $result = [
            'ok' => false,
            'changed' => false,
            'already' => false,
            'missingFile' => false,
            'missingKey' => false,
            'error' => null,
        ];

        if (!is_file($configFile)) {
            $result['missingFile'] = true;
            $result['ok'] = true;
            return $result;
        }

        $config = $this->loadPhpConfigArray($configFile);
        if ($config === null) {
            $result['error'] = "Bad config file: {$configFile}";
            return $result;
        }
        if (!array_key_exists('enable', $config)) {
            $result['missingKey'] = true;
            $result['ok'] = true;
            return $result;
        }

        $current = (bool)$config['enable'];
        if ($current === $enable) {
            $result['already'] = true;
            $result['ok'] = true;
            return $result;
        }

        $content = file_get_contents($configFile);
        if (!is_string($content) || $content === '') {
            $result['error'] = "Unable to read file: {$configFile}";
            return $result;
        }

        $target = $enable ? 'true' : 'false';
        $pattern = '/([\'"]enable[\'"]\s*=>\s*)(true|false)/i';
        $count = 0;
        $patched = preg_replace($pattern, '$1' . $target, $content, -1, $count);
        if (!is_string($patched) || $count === 0) {
            // Do not rewrite full config to preserve user formatting/comments.
            $result['error'] = "Config key 'enable' not found in file: {$configFile}";
            return $result;
        }

        if (file_put_contents($configFile, $patched) === false) {
            $result['error'] = "Unable to write file: {$configFile}";
            return $result;
        }

        $result['ok'] = true;
        $result['changed'] = true;
        return $result;
    }

    /**
     * Multilingual CLI message map for plugin commands. Fallback: exact -> lang prefix -> en -> zh_CN -> first.
     *
     * @return array<string, array<string, string>>
     */
    protected function getPluginMessageMap(): array
    {
        $zhCn = [
            'bad_name' => "<error>插件名无效：{name}\n要求：必须是 composer 包名，格式为 vendor/name（建议全小写），例如 foo/my-admin。</error>",
            'name_conflict' => "<error>参数冲突：位置参数与 --name 不一致：{arg} vs {opt}\n请只保留一个，或确保两者一致。</error>",
            'plugin_not_found' => "<error>插件不存在：{name}\n请先安装该插件（例如：composer require {name}），或确认目录存在：{path}</error>",
            'create_title' => "<info>创建插件</info> <comment>{name}</comment>",
            'enable_title' => "<info>启用插件</info> <comment>{name}</comment>",
            'disable_title' => "<info>禁用插件</info> <comment>{name}</comment>",
            'export_title' => "<info>导出插件</info> <comment>{name}</comment>",
            'install_title' => "<info>执行安装脚本</info> <comment>{name}</comment>",
            'uninstall_title' => "<info>执行卸载脚本</info> <comment>{name}</comment>",
            'missing_name' => "<error>缺少参数：请通过位置参数或 `--name` 指定插件包名（例如 foo/my-admin）。</error>",
            'dir_exists' => "<error>目录已存在：</error> {path}",
            'create_failed' => "<error>创建失败：</error> {error}",
            'step_psr4' => "<comment>步骤</comment> 添加 PSR-4 映射：<info>{key}</info> -> <info>{path}</info>",
            'psr4_ok' => "<info>PSR-4 映射已写入 composer.json</info>",
            'psr4_failed' => "<error>写入 composer.json 失败：</error> {error}",
            'step_config' => "<comment>步骤</comment> 生成配置目录：{path}",
            'step_vendor' => "<comment>步骤</comment> 生成插件代码目录：{path}",
            'created' => "<info>已创建：</info> {path}",
            'dumpautoload_ok' => "<info>已执行：</info> composer dumpautoload",
            'dumpautoload_failed' => "<comment>提示</comment> 自动执行失败，请手动运行：<info>{cmd}</info>",
            'dumpautoload_manual' => "<comment>提示</comment> 当前环境无法自动执行命令，请手动运行：<info>composer dumpautoload</info>",
            'done' => "<info>完成</info> 插件 {name} 创建成功",
            'config_file' => "<comment>配置文件</comment> {path}",
            'config_missing' => "<comment>提示</comment> 配置文件不存在，跳过：{path}",
            'enable_key_missing' => "<comment>提示</comment> 配置项 `enable` 不存在，跳过：{path}",
            'already_enabled' => "<comment>提示</comment> 已是启用状态，无需修改",
            'already_disabled' => "<comment>提示</comment> 已是禁用状态，无需修改",
            'enabled_ok' => "<info>已启用</info> {name}",
            'disabled_ok' => "<info>已禁用</info> {name}",
            'updated_ok' => "<info>已更新</info> {path}",
            'update_failed' => "<error>更新失败：</error> {error}",
            'export_install_created' => "<info>已生成：</info> {path}",
            'export_copy' => "<info>复制</info> {src} <comment>→</comment> {dest}",
            'export_skip_missing' => "<comment>跳过</comment> 路径不存在：{path}",
            'export_saved' => "<info>已导出</info> {name} <comment>→</comment> {dest}",
            'script_missing' => "<comment>提示</comment> 未找到安装/卸载脚本（Install::WEBMAN_PLUGIN 或方法不存在）。如刚修改过 composer.json，请先执行：<info>composer dumpautoload</info>",
            'script_ok' => "<info>执行完成</info>",
            'script_failed' => "<error>执行失败：</error> {error}",
        ];

        $en = [
            'bad_name' => "<error>Invalid plugin name: {name}\nIt must be a composer package name in vendor/name format (prefer lowercase), e.g. foo/my-admin.</error>",
            'name_conflict' => "<error>Argument conflict: positional name differs from --name: {arg} vs {opt}\nPlease keep only one or make them identical.</error>",
            'plugin_not_found' => "<error>Plugin not found: {name}\nPlease install it first (e.g. composer require {name}) or ensure directory exists: {path}</error>",
            'create_title' => "<info>Create plugin</info> <comment>{name}</comment>",
            'enable_title' => "<info>Enable plugin</info> <comment>{name}</comment>",
            'disable_title' => "<info>Disable plugin</info> <comment>{name}</comment>",
            'export_title' => "<info>Export plugin</info> <comment>{name}</comment>",
            'install_title' => "<info>Execute install script</info> <comment>{name}</comment>",
            'uninstall_title' => "<info>Execute uninstall script</info> <comment>{name}</comment>",
            'missing_name' => "<error>Missing argument: please provide package name by positional argument or `--name` (e.g. foo/my-admin).</error>",
            'dir_exists' => "<error>Directory already exists:</error> {path}",
            'create_failed' => "<error>Create failed:</error> {error}",
            'step_psr4' => "<comment>Step</comment> Add PSR-4 mapping: <info>{key}</info> -> <info>{path}</info>",
            'psr4_ok' => "<info>PSR-4 mapping updated in composer.json</info>",
            'psr4_failed' => "<error>Failed to update composer.json:</error> {error}",
            'step_config' => "<comment>Step</comment> Create config directory: {path}",
            'step_vendor' => "<comment>Step</comment> Create plugin source directory: {path}",
            'created' => "<info>Created:</info> {path}",
            'dumpautoload_ok' => "<info>Executed:</info> composer dumpautoload",
            'dumpautoload_failed' => "<comment>Note</comment> Auto execution failed, please run manually: <info>{cmd}</info>",
            'dumpautoload_manual' => "<comment>Note</comment> Cannot execute commands in this environment, please run: <info>composer dumpautoload</info>",
            'done' => "<info>Done</info> Plugin {name} created successfully",
            'config_file' => "<comment>Config file</comment> {path}",
            'config_missing' => "<comment>Note</comment> Config file not found, skipped: {path}",
            'enable_key_missing' => "<comment>Note</comment> Config key `enable` not found, skipped: {path}",
            'already_enabled' => "<comment>Note</comment> Already enabled, no changes needed",
            'already_disabled' => "<comment>Note</comment> Already disabled, no changes needed",
            'enabled_ok' => "<info>Enabled</info> {name}",
            'disabled_ok' => "<info>Disabled</info> {name}",
            'updated_ok' => "<info>Updated</info> {path}",
            'update_failed' => "<error>Update failed:</error> {error}",
            'export_install_created' => "<info>Generated:</info> {path}",
            'export_copy' => "<info>Copy</info> {src} <comment>→</comment> {dest}",
            'export_skip_missing' => "<comment>Skip</comment> Path not found: {path}",
            'export_saved' => "<info>Exported</info> {name} <comment>→</comment> {dest}",
            'script_missing' => "<comment>Note</comment> Install/Uninstall script not found (Install::WEBMAN_PLUGIN or method missing). If you just changed composer.json, please run: <info>composer dumpautoload</info>",
            'script_ok' => "<info>Done</info>",
            'script_failed' => "<error>Execution failed:</error> {error}",
        ];

        $zhTw = [
            'bad_name' => "<error>插件名稱無效：{name}\n要求：必須是 composer 套件名，格式為 vendor/name（建議全小寫），例如 foo/my-admin。</error>",
            'name_conflict' => "<error>參數衝突：位置參數與 --name 不一致：{arg} vs {opt}\n請只保留一個，或確保兩者一致。</error>",
            'plugin_not_found' => "<error>插件不存在：{name}\n請先安裝該插件（例如：composer require {name}），或確認目錄存在：{path}</error>",
            'create_title' => "<info>建立插件</info> <comment>{name}</comment>",
            'enable_title' => "<info>啟用插件</info> <comment>{name}</comment>",
            'disable_title' => "<info>停用插件</info> <comment>{name}</comment>",
            'export_title' => "<info>匯出插件</info> <comment>{name}</comment>",
            'install_title' => "<info>執行安裝腳本</info> <comment>{name}</comment>",
            'uninstall_title' => "<info>執行卸載腳本</info> <comment>{name}</comment>",
            'missing_name' => "<error>缺少參數：請透過位置參數或 `--name` 指定插件套件名（例如 foo/my-admin）。</error>",
            'dir_exists' => "<error>目錄已存在：</error> {path}",
            'create_failed' => "<error>建立失敗：</error> {error}",
            'step_psr4' => "<comment>步驟</comment> 新增 PSR-4 對應：<info>{key}</info> -> <info>{path}</info>",
            'psr4_ok' => "<info>PSR-4 對應已寫入 composer.json</info>",
            'psr4_failed' => "<error>寫入 composer.json 失敗：</error> {error}",
            'step_config' => "<comment>步驟</comment> 產生設定目錄：{path}",
            'step_vendor' => "<comment>步驟</comment> 產生插件程式目錄：{path}",
            'created' => "<info>已建立：</info> {path}",
            'dumpautoload_ok' => "<info>已執行：</info> composer dumpautoload",
            'dumpautoload_failed' => "<comment>提示</comment> 自動執行失敗，請手動執行：<info>{cmd}</info>",
            'dumpautoload_manual' => "<comment>提示</comment> 目前環境無法自動執行指令，請手動執行：<info>composer dumpautoload</info>",
            'done' => "<info>完成</info> 插件 {name} 建立成功",
            'config_file' => "<comment>設定檔</comment> {path}",
            'config_missing' => "<comment>提示</comment> 設定檔不存在，略過：{path}",
            'enable_key_missing' => "<comment>提示</comment> 設定項 `enable` 不存在，略過：{path}",
            'already_enabled' => "<comment>提示</comment> 已是啟用狀態，無需修改",
            'already_disabled' => "<comment>提示</comment> 已是停用狀態，無需修改",
            'enabled_ok' => "<info>已啟用</info> {name}",
            'disabled_ok' => "<info>已停用</info> {name}",
            'updated_ok' => "<info>已更新</info> {path}",
            'update_failed' => "<error>更新失敗：</error> {error}",
            'export_install_created' => "<info>已產生：</info> {path}",
            'export_copy' => "<info>複製</info> {src} <comment>→</comment> {dest}",
            'export_skip_missing' => "<comment>略過</comment> 路徑不存在：{path}",
            'export_saved' => "<info>已匯出</info> {name} <comment>→</comment> {dest}",
            'script_missing' => "<comment>提示</comment> 未找到安裝/卸載腳本（Install::WEBMAN_PLUGIN 或方法不存在）。如剛修改過 composer.json，請先執行：<info>composer dumpautoload</info>",
            'script_ok' => "<info>執行完成</info>",
            'script_failed' => "<error>執行失敗：</error> {error}",
        ];

        $ja = [
            'bad_name' => "<error>無効なプラグイン名：{name}\ncomposer パッケージ名（vendor/name、小推奨）が必要です。例：foo/my-admin。</error>",
            'name_conflict' => "<error>引数の衝突：位置引数と --name が一致しません：{arg} vs {opt}\nどちらか一方に統一してください。</error>",
            'plugin_not_found' => "<error>プラグインが見つかりません：{name}\n先にインストール（例：composer require {name}）するか、ディレクトリ {path} の存在を確認してください。</error>",
            'create_title' => "<info>プラグインを作成</info> <comment>{name}</comment>",
            'enable_title' => "<info>プラグインを有効化</info> <comment>{name}</comment>",
            'disable_title' => "<info>プラグインを無効化</info> <comment>{name}</comment>",
            'export_title' => "<info>プラグインをエクスポート</info> <comment>{name}</comment>",
            'install_title' => "<info>インストールスクリプトを実行</info> <comment>{name}</comment>",
            'uninstall_title' => "<info>アンインストールスクリプトを実行</info> <comment>{name}</comment>",
            'missing_name' => "<error>引数がありません。位置引数または `--name` でパッケージ名（例：foo/my-admin）を指定してください。</error>",
            'dir_exists' => "<error>ディレクトリが既に存在します：</error> {path}",
            'create_failed' => "<error>作成に失敗しました：</error> {error}",
            'step_psr4' => "<comment>手順</comment> PSR-4 マッピングを追加：<info>{key}</info> -> <info>{path}</info>",
            'psr4_ok' => "<info>composer.json に PSR-4 マッピングを反映しました</info>",
            'psr4_failed' => "<error>composer.json の更新に失敗しました：</error> {error}",
            'step_config' => "<comment>手順</comment> 設定ディレクトリを作成：{path}",
            'step_vendor' => "<comment>手順</comment> プラグインソースディレクトリを作成：{path}",
            'created' => "<info>作成しました：</info> {path}",
            'dumpautoload_ok' => "<info>実行しました：</info> composer dumpautoload",
            'dumpautoload_failed' => "<comment>注意</comment> 自動実行に失敗しました。手動で実行してください：<info>{cmd}</info>",
            'dumpautoload_manual' => "<comment>注意</comment> この環境ではコマンドを自動実行できません。手動で <info>composer dumpautoload</info> を実行してください。",
            'done' => "<info>完了</info> プラグイン {name} の作成に成功しました",
            'config_file' => "<comment>設定ファイル</comment> {path}",
            'config_missing' => "<comment>注意</comment> 設定ファイルが見つかりません、スキップ：{path}",
            'enable_key_missing' => "<comment>注意</comment> 設定キー `enable` が見つかりません、スキップ：{path}",
            'already_enabled' => "<comment>注意</comment> 既に有効です。変更不要です。",
            'already_disabled' => "<comment>注意</comment> 既に無効です。変更不要です。",
            'enabled_ok' => "<info>有効にしました</info> {name}",
            'disabled_ok' => "<info>無効にしました</info> {name}",
            'updated_ok' => "<info>更新しました</info> {path}",
            'update_failed' => "<error>更新に失敗しました：</error> {error}",
            'export_install_created' => "<info>生成しました：</info> {path}",
            'export_copy' => "<info>コピー</info> {src} <comment>→</comment> {dest}",
            'export_skip_missing' => "<comment>スキップ</comment> パスが見つかりません：{path}",
            'export_saved' => "<info>エクスポートしました</info> {name} <comment>→</comment> {dest}",
            'script_missing' => "<comment>注意</comment> インストール/アンインストールスクリプトが見つかりません（Install::WEBMAN_PLUGIN またはメソッドがありません）。composer.json を変更した場合は <info>composer dumpautoload</info> を実行してください。",
            'script_ok' => "<info>完了</info>",
            'script_failed' => "<error>実行に失敗しました：</error> {error}",
        ];

        $ko = [
            'bad_name' => "<error>잘못된 플러그인 이름: {name}\ncomposer 패키지 이름(vendor/name, 소문자 권장)이어야 합니다. 예: foo/my-admin.</error>",
            'name_conflict' => "<error>인자 충돌: 위치 인자와 --name이 일치하지 않습니다: {arg} vs {opt}\n하나만 사용하거나 동일하게 맞추세요.</error>",
            'plugin_not_found' => "<error>플러그인을 찾을 수 없습니다: {name}\n먼저 설치하세요(예: composer require {name}) 또는 디렉터리 존재 여부 확인: {path}</error>",
            'create_title' => "<info>플러그인 생성</info> <comment>{name}</comment>",
            'enable_title' => "<info>플러그인 활성화</info> <comment>{name}</comment>",
            'disable_title' => "<info>플러그인 비활성화</info> <comment>{name}</comment>",
            'export_title' => "<info>플러그인 내보내기</info> <comment>{name}</comment>",
            'install_title' => "<info>설치 스크립트 실행</info> <comment>{name}</comment>",
            'uninstall_title' => "<info>제거 스크립트 실행</info> <comment>{name}</comment>",
            'missing_name' => "<error>인자가 없습니다. 위치 인자 또는 `--name`으로 패키지 이름(예: foo/my-admin)을 지정하세요.</error>",
            'dir_exists' => "<error>디렉터리가 이미 있습니다:</error> {path}",
            'create_failed' => "<error>생성 실패:</error> {error}",
            'step_psr4' => "<comment>단계</comment> PSR-4 매핑 추가: <info>{key}</info> -> <info>{path}</info>",
            'psr4_ok' => "<info>composer.json에 PSR-4 매핑이 반영되었습니다</info>",
            'psr4_failed' => "<error>composer.json 업데이트 실패:</error> {error}",
            'step_config' => "<comment>단계</comment> 설정 디렉터리 생성: {path}",
            'step_vendor' => "<comment>단계</comment> 플러그인 소스 디렉터리 생성: {path}",
            'created' => "<info>생성됨:</info> {path}",
            'dumpautoload_ok' => "<info>실행됨:</info> composer dumpautoload",
            'dumpautoload_failed' => "<comment>참고</comment> 자동 실행에 실패했습니다. 수동으로 실행하세요: <info>{cmd}</info>",
            'dumpautoload_manual' => "<comment>참고</comment> 이 환경에서는 명령을 자동 실행할 수 없습니다. 수동으로 <info>composer dumpautoload</info>를 실행하세요.",
            'done' => "<info>완료</info> 플러그인 {name}이(가) 성공적으로 생성되었습니다",
            'config_file' => "<comment>설정 파일</comment> {path}",
            'config_missing' => "<comment>참고</comment> 설정 파일을 찾을 수 없어 건너뜁니다: {path}",
            'enable_key_missing' => "<comment>참고</comment> 설정 키 `enable`을 찾을 수 없어 건너뜁니다: {path}",
            'already_enabled' => "<comment>참고</comment> 이미 활성화되어 있습니다. 변경할 필요 없습니다.",
            'already_disabled' => "<comment>참고</comment> 이미 비활성화되어 있습니다. 변경할 필요 없습니다.",
            'enabled_ok' => "<info>활성화됨</info> {name}",
            'disabled_ok' => "<info>비활성화됨</info> {name}",
            'updated_ok' => "<info>업데이트됨</info> {path}",
            'update_failed' => "<error>업데이트 실패:</error> {error}",
            'export_install_created' => "<info>생성됨:</info> {path}",
            'export_copy' => "<info>복사</info> {src} <comment>→</comment> {dest}",
            'export_skip_missing' => "<comment>건너뜀</comment> 경로를 찾을 수 없습니다: {path}",
            'export_saved' => "<info>내보냄</info> {name} <comment>→</comment> {dest}",
            'script_missing' => "<comment>참고</comment> 설치/제거 스크립트를 찾을 수 없습니다(Install::WEBMAN_PLUGIN 또는 메서드 없음). composer.json을 수정했다면 <info>composer dumpautoload</info>를 실행하세요.",
            'script_ok' => "<info>완료</info>",
            'script_failed' => "<error>실행 실패:</error> {error}",
        ];

        $restLocales = ['fr' => $en, 'de' => $en, 'es' => $en, 'pt_BR' => $en, 'ru' => $en, 'vi' => $en, 'tr' => $en, 'id' => $en, 'th' => $en];
        return array_merge(
            ['zh_CN' => $zhCn, 'zh_TW' => $zhTw, 'en' => $en, 'ja' => $ja, 'ko' => $ko],
            $restLocales
        );
    }

    /**
     * CLI messages for plugin commands. Locale selected by getLocale() / Util fallback.
     *
     * @param string $key
     * @param array $replace
     * @return string
     */
    protected function pluginMsg(string $key, array $replace = []): string
    {
        $map = Util::selectLocaleMessages($this->getPluginMessageMap());
        $text = $map[$key] ?? $key;
        return $replace ? strtr($text, $replace) : $text;
    }
}

