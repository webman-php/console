<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\Table;
use Webman\Console\Application;
use Webman\Route;

class StopCommand extends Command
{
    protected static $defaultName = 'stop';
    protected static $defaultDescription = 'Stop worker. Use mode -g to stop gracefully.';
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
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        Application::run();
        return self::SUCCESS;
    }
}
