<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Webman\Console\Application;

#[AsCommand('stop', 'Stop worker. Use mode -g to stop gracefully.')]
class StopCommand extends Command
{
    protected function configure() : void
    {
        $this
            ->addOption('graceful', 'g',InputOption::VALUE_NONE, 'graceful stop');
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
