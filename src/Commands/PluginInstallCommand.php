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

#[AsCommand('plugin:install', 'Execute plugin installation script')]
class PluginInstallCommand extends Command
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

        $output->writeln($this->pluginMsg('install_title', ['{name}' => $nameRaw]));

        $namespace = Util::nameToNamespace($nameRaw);
        $installFunction = "\\{$namespace}\\Install::install";
        $pluginConst = "\\{$namespace}\\Install::WEBMAN_PLUGIN";
        if (!defined($pluginConst) || !is_callable($installFunction)) {
            $output->writeln($this->pluginMsg('script_missing'));
            return Command::SUCCESS;
        }

        try {
            $installFunction();
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
执行插件安装脚本（Install::install）。

用法：
  php webman plugin:install foo/my-admin
  php webman plugin:install --name foo/my-admin

说明：
  - 需要插件包中存在 `Install::WEBMAN_PLUGIN` 常量且 `Install::install` 可调用。
EOF;
        $en = <<<'EOF'
Execute plugin install script (Install::install).

Usage:
  php webman plugin:install foo/my-admin
  php webman plugin:install --name foo/my-admin

Notes:
  - The plugin package must define `Install::WEBMAN_PLUGIN` and provide callable `Install::install`.
EOF;
        return Util::selectByLocale([
            'zh_CN' => $zh, 'zh_TW' => $zh, 'en' => $en,
            'ja' => "プラグインのインストールスクリプトを実行（Install::install）。\n\n用法：\n  php webman plugin:install foo/my-admin\n  php webman plugin:install --name foo/my-admin\n\n説明：\n  - プラグインに `Install::WEBMAN_PLUGIN` 定数と callable `Install::install` が必要。",
            'ko' => "플러그인 설치 스크립트 실행 (Install::install).\n\n사용법:\n  php webman plugin:install foo/my-admin\n  php webman plugin:install --name foo/my-admin\n\n참고:\n  - 플러그인 패키지에 `Install::WEBMAN_PLUGIN` 상수와 callable `Install::install` 필요.",
            'fr' => "Exécuter le script d'installation du plugin (Install::install).\n\nUsage :\n  php webman plugin:install foo/my-admin\n  php webman plugin:install --name foo/my-admin\n\nNotes :\n  - Le package doit définir `Install::WEBMAN_PLUGIN` et fournir `Install::install` callable.",
            'de' => "Installationsskript des Plugins ausführen (Install::install).\n\nVerwendung:\n  php webman plugin:install foo/my-admin\n  php webman plugin:install --name foo/my-admin\n\nHinweise:\n  - Das Plugin-Paket muss `Install::WEBMAN_PLUGIN` definieren und aufrufbares `Install::install` bereitstellen.",
            'es' => "Ejecutar script de instalación del plugin (Install::install).\n\nUso:\n  php webman plugin:install foo/my-admin\n  php webman plugin:install --name foo/my-admin\n\nNotas:\n  - El paquete debe definir `Install::WEBMAN_PLUGIN` y proporcionar callable `Install::install`.",
            'pt_BR' => "Executar script de instalação do plugin (Install::install).\n\nUso:\n  php webman plugin:install foo/my-admin\n  php webman plugin:install --name foo/my-admin\n\nNotas:\n  - O pacote deve definir `Install::WEBMAN_PLUGIN` e fornecer callable `Install::install`.",
            'ru' => "Выполнить скрипт установки плагина (Install::install).\n\nИспользование:\n  php webman plugin:install foo/my-admin\n  php webman plugin:install --name foo/my-admin\n\nПримечания:\n  - В пакете должны быть константа `Install::WEBMAN_PLUGIN` и вызываемый `Install::install`.",
            'vi' => "Chạy script cài đặt plugin (Install::install).\n\nCách dùng:\n  php webman plugin:install foo/my-admin\n  php webman plugin:install --name foo/my-admin\n\nLưu ý:\n  - Gói plugin phải định nghĩa `Install::WEBMAN_PLUGIN` và cung cấp callable `Install::install`.",
            'tr' => "Eklenti kurulum betiğini çalıştır (Install::install).\n\nKullanım:\n  php webman plugin:install foo/my-admin\n  php webman plugin:install --name foo/my-admin\n\nNotlar:\n  - Pakette `Install::WEBMAN_PLUGIN` tanımlı ve çağrılabilir `Install::install` olmalı.",
            'id' => "Jalankan skrip instalasi plugin (Install::install).\n\nPenggunaan:\n  php webman plugin:install foo/my-admin\n  php webman plugin:install --name foo/my-admin\n\nCatatan:\n  - Paket plugin harus mendefinisikan `Install::WEBMAN_PLUGIN` dan menyediakan callable `Install::install`.",
            'th' => "รันสคริปต์ติดตั้งปลั๊กอิน (Install::install)\n\nวิธีใช้:\n  php webman plugin:install foo/my-admin\n  php webman plugin:install --name foo/my-admin\n\nหมายเหตุ:\n  - แพ็กเกจปลั๊กอินต้องกำหนด `Install::WEBMAN_PLUGIN` และมี callable `Install::install`",
        ]);
    }
}
