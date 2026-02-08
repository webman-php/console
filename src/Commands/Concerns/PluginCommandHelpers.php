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
     * Bilingual CLI messages for plugin commands.
     *
     * @param string $key
     * @param array $replace
     * @return string
     */
    protected function pluginMsg(string $key, array $replace = []): string
    {
        $zh = [
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

        $map = Util::selectLocaleMessages(['zh_CN' => $zh, 'en' => $en]);
        $text = $map[$key] ?? $key;
        return $replace ? strtr($text, $replace) : $text;
    }
}

