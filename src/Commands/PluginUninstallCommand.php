<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Webman\Console\Util;
use Webman\Console\Commands\Concerns\PluginCommandHelpers;

#[AsCommand('plugin:uninstall', 'Execute plugin uninstall script')]
class PluginUninstallCommand extends Command
{
    use PluginCommandHelpers;

    /**
     * @return void
     */
    protected function configure()
    {
        // Do NOT use "-n": Symfony Console already reserves "-n" for "--no-interaction".
        $this->addArgument('name', InputArgument::OPTIONAL, 'Plugin name, for example foo/my-admin');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Plugin name, for example foo/my-admin');
        $this->setHelp($this->buildHelpText());
        $this->addUsage('foo/my-admin');
        $this->addUsage('--name foo/my-admin');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $nameArg = $this->normalizePluginName($input->getArgument('name'));
        $nameOpt = $this->normalizePluginName($input->getOption('name'));
        if ($nameArg && $nameOpt && $nameArg !== $nameOpt) {
            $output->writeln($this->pluginMsg('name_conflict', ['{arg}' => $nameArg, '{opt}' => $nameOpt]));
            return Command::FAILURE;
        }
        $nameRaw = $nameOpt ?: $nameArg;
        if (!$nameRaw) {
            $output->writeln($this->pluginMsg('missing_name'));
            return Command::FAILURE;
        }
        if (!$this->isValidComposerPackageName($nameRaw)) {
            $output->writeln($this->pluginMsg('bad_name', ['{name}' => (string)$nameRaw]));
            return Command::FAILURE;
        }

        if (!$this->pluginPackageExists($nameRaw)) {
            $output->writeln($this->pluginMsg('plugin_not_found', [
                '{name}' => $nameRaw,
                '{path}' => "vendor/{$nameRaw}",
            ]));
            return Command::FAILURE;
        }

        $output->writeln($this->pluginMsg('uninstall_title', ['{name}' => $nameRaw]));

        $namespace = Util::nameToNamespace($nameRaw);
        $uninstallFunction = "\\{$namespace}\\Install::uninstall";
        $pluginConst = "\\{$namespace}\\Install::WEBMAN_PLUGIN";
        if (!defined($pluginConst) || !is_callable($uninstallFunction)) {
            $output->writeln($this->pluginMsg('script_missing'));
            return Command::SUCCESS;
        }

        try {
            $uninstallFunction();
        } catch (\Throwable $e) {
            $output->writeln($this->pluginMsg('script_failed', ['{error}' => $e->getMessage()]));
            return Command::FAILURE;
        }

        $output->writeln($this->pluginMsg('script_ok'));
        return Command::SUCCESS;
    }

    protected function buildHelpText(): string
    {
        $zh = <<<'EOF'
执行插件卸载脚本（Install::uninstall）。

用法：
  php webman plugin:uninstall foo/my-admin
  php webman plugin:uninstall --name foo/my-admin

说明：
  - 需要插件包中存在 `Install::WEBMAN_PLUGIN` 常量且 `Install::uninstall` 可调用。
EOF;
        $en = <<<'EOF'
Execute plugin uninstall script (Install::uninstall).

Usage:
  php webman plugin:uninstall foo/my-admin
  php webman plugin:uninstall --name foo/my-admin

Notes:
  - The plugin package must define `Install::WEBMAN_PLUGIN` and provide callable `Install::uninstall`.
EOF;
        return Util::selectByLocale([
            'zh_CN' => $zh, 'zh_TW' => $zh, 'en' => $en,
            'ja' => "プラグインのアンインストールスクリプトを実行（Install::uninstall）。\n\n用法：\n  php webman plugin:uninstall foo/my-admin\n  php webman plugin:uninstall --name foo/my-admin\n\n説明：\n  - プラグインに `Install::WEBMAN_PLUGIN` 定数と callable `Install::uninstall` が必要。",
            'ko' => "플러그인 제거 스크립트 실행 (Install::uninstall).\n\n사용법:\n  php webman plugin:uninstall foo/my-admin\n  php webman plugin:uninstall --name foo/my-admin\n\n참고:\n  - 플러그인 패키지에 `Install::WEBMAN_PLUGIN` 상수와 callable `Install::uninstall` 필요.",
            'fr' => "Exécuter le script de désinstallation du plugin (Install::uninstall).\n\nUsage :\n  php webman plugin:uninstall foo/my-admin\n  php webman plugin:uninstall --name foo/my-admin\n\nNotes :\n  - Le package doit définir `Install::WEBMAN_PLUGIN` et fournir `Install::uninstall` callable.",
            'de' => "Deinstallationsskript des Plugins ausführen (Install::uninstall).\n\nVerwendung:\n  php webman plugin:uninstall foo/my-admin\n  php webman plugin:uninstall --name foo/my-admin\n\nHinweise:\n  - Das Plugin-Paket muss `Install::WEBMAN_PLUGIN` definieren und aufrufbares `Install::uninstall` bereitstellen.",
            'es' => "Ejecutar script de desinstalación del plugin (Install::uninstall).\n\nUso:\n  php webman plugin:uninstall foo/my-admin\n  php webman plugin:uninstall --name foo/my-admin\n\nNotas:\n  - El paquete debe definir `Install::WEBMAN_PLUGIN` y proporcionar callable `Install::uninstall`.",
            'pt_BR' => "Executar script de desinstalação do plugin (Install::uninstall).\n\nUso:\n  php webman plugin:uninstall foo/my-admin\n  php webman plugin:uninstall --name foo/my-admin\n\nNotas:\n  - O pacote deve definir `Install::WEBMAN_PLUGIN` e fornecer callable `Install::uninstall`.",
            'ru' => "Выполнить скрипт удаления плагина (Install::uninstall).\n\nИспользование:\n  php webman plugin:uninstall foo/my-admin\n  php webman plugin:uninstall --name foo/my-admin\n\nПримечания:\n  - В пакете должны быть константа `Install::WEBMAN_PLUGIN` и вызываемый `Install::uninstall`.",
            'vi' => "Chạy script gỡ cài đặt plugin (Install::uninstall).\n\nCách dùng:\n  php webman plugin:uninstall foo/my-admin\n  php webman plugin:uninstall --name foo/my-admin\n\nLưu ý:\n  - Gói plugin phải định nghĩa `Install::WEBMAN_PLUGIN` và cung cấp callable `Install::uninstall`.",
            'tr' => "Eklenti kaldırma betiğini çalıştır (Install::uninstall).\n\nKullanım:\n  php webman plugin:uninstall foo/my-admin\n  php webman plugin:uninstall --name foo/my-admin\n\nNotlar:\n  - Pakette `Install::WEBMAN_PLUGIN` tanımlı ve çağrılabilir `Install::uninstall` olmalı.",
            'id' => "Jalankan skrip uninstall plugin (Install::uninstall).\n\nPenggunaan:\n  php webman plugin:uninstall foo/my-admin\n  php webman plugin:uninstall --name foo/my-admin\n\nCatatan:\n  - Paket plugin harus mendefinisikan `Install::WEBMAN_PLUGIN` dan menyediakan callable `Install::uninstall`.",
            'th' => "รันสคริปต์ถอนการติดตั้งปลั๊กอิน (Install::uninstall)\n\nวิธีใช้:\n  php webman plugin:uninstall foo/my-admin\n  php webman plugin:uninstall --name foo/my-admin\n\nหมายเหตุ:\n  - แพ็กเกจปลั๊กอินต้องกำหนด `Install::WEBMAN_PLUGIN` และมี callable `Install::uninstall`",
        ]);
    }
}
