<?php

namespace Webman\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Webman\Console\Commands\Concerns\MakeCommandHelpers;
use Webman\Console\Util;
use Webman\Route;

#[AsCommand('route:list', 'Route list')]
class RouteListCommand extends Command
{
    use MakeCommandHelpers;

    protected function configure(): void
    {
        $desc = Util::selectByLocale([
            'zh_CN' => '路由列表', 'zh_TW' => '路由列表', 'en' => 'Route list', 'ja' => 'ルート一覧',
            'ko' => '라우트 목록', 'fr' => 'Liste des routes', 'de' => 'Routenliste', 'es' => 'Lista de rutas',
            'pt_BR' => 'Lista de rotas', 'ru' => 'Список маршрутов', 'vi' => 'Danh sách route',
            'tr' => 'Rota listesi', 'id' => 'Daftar rute', 'th' => 'รายการเส้นทาง',
        ]);
        $this->setDescription($desc);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->msg('title'));
        $headers = Util::selectLocaleArray([
            'zh_CN' => ['URI', '方法', '回调', '中间件', '名称'],
            'zh_TW' => ['URI', '方法', '回調', '中間件', '名稱'],
            'en' => ['uri', 'method', 'callback', 'middleware', 'name'],
            'ja' => ['URI', 'メソッド', 'コールバック', 'ミドルウェア', '名前'],
            'ko' => ['URI', '메서드', '콜백', '미들웨어', '이름'],
            'fr' => ['uri', 'méthode', 'callback', 'middleware', 'nom'],
            'de' => ['URI', 'Methode', 'Callback', 'Middleware', 'Name'],
            'es' => ['uri', 'método', 'callback', 'middleware', 'nombre'],
            'pt_BR' => ['uri', 'método', 'callback', 'middleware', 'nome'],
            'ru' => ['URI', 'Метод', 'Обработчик', 'ПО', 'Имя'],
            'vi' => ['uri', 'phương thức', 'callback', 'middleware', 'tên'],
            'tr' => ['uri', 'metot', 'callback', 'middleware', 'ad'],
            'id' => ['uri', 'metode', 'callback', 'middleware', 'nama'],
            'th' => ['URI', 'เมธอด', 'callback', 'middleware', 'ชื่อ'],
        ]);
        $closureLabel = Util::selectByLocale([
            'zh_CN' => '闭包', 'zh_TW' => '閉包', 'en' => 'Closure', 'ja' => 'クロージャ',
            'ko' => '클로저', 'fr' => 'Closure', 'de' => 'Closure', 'es' => 'Closure',
            'pt_BR' => 'Closure', 'ru' => 'Замыкание', 'vi' => 'Closure', 'tr' => 'Closure',
            'id' => 'Closure', 'th' => 'Closure',
        ]);
        $rows = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $cb = $route->getCallback();
                $cb = $cb instanceof \Closure
                    ? $closureLabel
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
        $messages = [
            'zh_CN' => ['title' => '<info>路由列表</info>'],
            'zh_TW' => ['title' => '<info>路由列表</info>'],
            'en' => ['title' => '<info>Route list</info>'],
            'ja' => ['title' => '<info>ルート一覧</info>'],
            'ko' => ['title' => '<info>라우트 목록</info>'],
            'fr' => ['title' => '<info>Liste des routes</info>'],
            'de' => ['title' => '<info>Routenliste</info>'],
            'es' => ['title' => '<info>Lista de rutas</info>'],
            'pt_BR' => ['title' => '<info>Lista de rotas</info>'],
            'ru' => ['title' => '<info>Список маршрутов</info>'],
            'vi' => ['title' => '<info>Danh sách route</info>'],
            'tr' => ['title' => '<info>Rota listesi</info>'],
            'id' => ['title' => '<info>Daftar rute</info>'],
            'th' => ['title' => '<info>รายการเส้นทาง</info>'],
        ];
        $map = Util::selectLocaleMessages($messages);
        $text = $map[$key] ?? $key;
        return $replace ? strtr($text, $replace) : $text;
    }
}
