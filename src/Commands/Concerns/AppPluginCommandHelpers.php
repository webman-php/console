<?php

namespace Webman\Console\Commands\Concerns;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Helpers for AppPlugin* commands (plugin/<name>).
 */
trait AppPluginCommandHelpers
{
    use MakeCommandHelpers;

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
     * Bilingual CLI messages for app plugin commands.
     *
     * @param string $key
     * @param array<string,string> $replace
     * @return string
     */
    protected function msg(string $key, array $replace = []): string
    {
        $zh = [
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

        $map = $this->isZhLocale() ? $zh : $en;
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

