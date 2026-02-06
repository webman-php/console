<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Webman\Console\Commands\Concerns\MakeCommandHelpers;
use Webman\Route;

#[AsCommand('route:list', 'Route list')]
class RouteListCommand extends Command
{
    use MakeCommandHelpers;

    protected function configure(): void
    {
        $this->setDescription($this->isZhLocale() ? '路由列表' : 'Route list');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->msg('title'));
        $headers = $this->isZhLocale()
            ? ['URI', '方法', '回调', '中间件', '名称']
            : ['uri', 'method', 'callback', 'middleware', 'name'];
        $rows = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $cb = $route->getCallback();
                $cb = $cb instanceof \Closure
                    ? ($this->isZhLocale() ? '闭包' : 'Closure')
                    : (is_array($cb) ? json_encode($cb) : var_export($cb, 1));
                $rows[] = [$route->getPath(), $method, $cb, json_encode($route->getMiddleware() ?: null), $route->getName()];
            }
        }

        $table = new Table($output);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();
        return self::SUCCESS;
    }

    protected function msg(string $key, array $replace = []): string
    {
        $zh = [
            'title' => '<info>路由列表</info>',
        ];
        $en = [
            'title' => '<info>Route list</info>',
        ];
        $map = $this->isZhLocale() ? $zh : $en;
        $text = $map[$key] ?? $key;
        return $replace ? strtr($text, $replace) : $text;
    }
}
