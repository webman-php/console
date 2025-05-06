<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webman\Console\Application;

#[AsCommand('connections', 'Get worker connections.')]
class ConnectionsCommand extends Command
{
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
