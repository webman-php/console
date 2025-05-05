<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('app-plugin:update', 'Update App Plugin')]
class AppPluginUpdateCommand extends Command
{
    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'App plugin name');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $output->writeln("Update App Plugin $name");
        $class = "\\plugin\\$name\\api\\Install";
        if (!method_exists($class, 'update')) {
            throw new \RuntimeException("Method $class::update not exists");
        }
        call_user_func([$class, 'update'], config("plugin.$name.app.version"), config("plugin.$name.app.version"));
        return self::SUCCESS;
    }

}
