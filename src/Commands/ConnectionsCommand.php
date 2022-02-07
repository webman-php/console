<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\Table;
use Webman\Console\Application;
use Webman\Route;

class ConnectionsCommand extends Command
{
    protected static $defaultName = 'connections';
    protected static $defaultDescription = 'Get worker connections.';

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        Application::run();
        return self::SUCCESS;
    }
}
