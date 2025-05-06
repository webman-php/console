<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Webman\Console\Application;

#[AsCommand('reload', 'Reload codes. Use mode -g to reload gracefully.')]
class ReloadCommand extends Command
{
    protected function configure() : void
    {
        $this
            ->addOption('graceful', 'd', InputOption::VALUE_NONE, 'graceful reload');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (\class_exists(\Support\App::class)) {
            \Support\App::run();
            return self::SUCCESS;
        }
        Application::run();
        return self::SUCCESS;
    }
}
