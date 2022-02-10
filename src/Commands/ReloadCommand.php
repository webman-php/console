<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\Table;
use Webman\Console\Application;
use Webman\Route;

class ReloadCommand extends Command
{
    protected static $defaultName = 'reload';
    protected static $defaultDescription = 'Reload codes. Use mode -g to reload gracefully.';

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
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        Application::run();
        return self::SUCCESS;
    }
}
