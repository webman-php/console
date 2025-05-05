<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('install', 'Execute webman installation script')]
class InstallCommand extends Command
{
    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("Execute installation for webman");
        $install_function = "\\Webman\\Install::install";
        if (is_callable($install_function)) {
            $install_function();
            return self::SUCCESS;
        }
        $output->writeln('<error>This command requires webman-framework version >= 1.3.0</error>');
        return self::FAILURE;
    }

}
