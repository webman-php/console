<?php
namespace Webman\Console;

use support\Container;
use support\Log;
use support\Request;
use Webman\App;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Worker;

ini_set('display_errors', 'on');
error_reporting(E_ALL);


class Application
{
    public static function run()
    {
        $runtime_logs_path = runtime_path() . DIRECTORY_SEPARATOR . 'logs';
        if ( !file_exists($runtime_logs_path) || !is_dir($runtime_logs_path) ) {
            if (!mkdir($runtime_logs_path,0777,true)) {
                throw new RuntimeException("Failed to create runtime logs directory. Please check the permission.");
            }
        }

        $runtime_views_path = runtime_path() . DIRECTORY_SEPARATOR . 'views';
        if ( !file_exists($runtime_views_path) || !is_dir($runtime_views_path) ) {
            if (!mkdir($runtime_views_path,0777,true)) {
                throw new RuntimeException("Failed to create runtime views directory. Please check the permission.");
            }
        }

        Worker::$onMasterReload = function (){
            if (function_exists('opcache_get_status')) {
                if ($status = opcache_get_status()) {
                    if (isset($status['scripts']) && $scripts = $status['scripts']) {
                        foreach (array_keys($scripts) as $file) {
                            opcache_invalidate($file, true);
                        }
                    }
                }
            }
        };

        $config                               = config('server');
        Worker::$pidFile                      = $config['pid_file'];
        Worker::$stdoutFile                   = $config['stdout_file'];
        Worker::$logFile                      = $config['log_file'];
        // Phar support. Defined the file paths to store the master process status file.
        Worker::$statisticsFileNamePrefix     = $config['statistics_file_prefix'];
        TcpConnection::$defaultMaxPackageSize = $config['max_package_size'] ?? 10*1024*1024;

        $worker = new Worker($config['listen'], $config['context']);
        $property_map = [
            'name',
            'count',
            'user',
            'group',
            'reusePort',
            'transport',
        ];
        foreach ($property_map as $property) {
            if (isset($config[$property])) {
                $worker->$property = $config[$property];
            }
        }

        $worker->onWorkerStart = function ($worker) {
            $app = new App($worker, Container::instance(), Log::channel('default'), app_path(), public_path());
            Http::requestClass(Request::class);
            $worker->onMessage = [$app, 'onMessage'];
        };

        // Windows does not support custom processes.
        if (\DIRECTORY_SEPARATOR === '/') {
            foreach (config('process', []) as $process_name => $config) {
                // Remove monitor process.
                if (class_exists(\Phar::class, false) && \Phar::running() && 'monitor' === $process_name) {
                    continue;
                }
                worker_start($process_name, $config);
            }
            foreach (config('plugin', []) as $firm => $projects) {
                foreach ($projects as $name => $project) {
                    foreach ($project['process'] ?? [] as $process_name => $config) {
                        worker_start("plugin.$firm.$name.$process_name", $config);
                    }
                }
            }
        }
        Worker::runAll();
    }
}
