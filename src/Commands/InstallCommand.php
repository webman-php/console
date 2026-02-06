<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webman\Console\Commands\Concerns\MakeCommandHelpers;

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
        $zh = [
            'install_title' => '<info>执行 Webman 安装脚本</info>',
            'done' => '<info>完成</info>',
            'require_version' => '<error>该命令需要 webman-framework 版本 >= 1.3.0</error>',
        ];
        $en = [
            'install_title' => '<info>Execute installation for Webman</info>',
            'done' => '<info>Done</info>',
            'require_version' => '<error>This command requires webman-framework version >= 1.3.0</error>',
        ];

        $map = $this->isZhLocale() ? $zh : $en;
        $text = $map[$key] ?? $key;
        return $replace ? strtr($text, $replace) : $text;
    }
}
