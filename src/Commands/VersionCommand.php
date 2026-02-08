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
        $zh = [
            'version' => '<info>Webman-framework 版本</info> <comment>{version}</comment>',
            'not_found' => '<error>无法读取 workerman/webman-framework 版本信息</error>',
        ];
        $en = [
            'version' => '<info>Webman-framework</info> <comment>{version}</comment>',
            'not_found' => '<error>Unable to read version info for workerman/webman-framework</error>',
        ];

        $map = Util::selectLocaleMessages(['zh_CN' => $zh, 'en' => $en]);
        $text = $map[$key] ?? $key;
        return $replace ? strtr($text, $replace) : $text;
    }
}
