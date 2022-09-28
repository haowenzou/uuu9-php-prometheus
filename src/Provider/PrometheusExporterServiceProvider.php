<?php

namespace U9\U9PhpPrometheus\Provider;

use Illuminate\Support\ServiceProvider;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis;
use U9\U9PhpPrometheus\Contract\PrometheusExporterContract;
use U9\U9PhpPrometheus\Controller\PrometheusExporterController;
use U9\U9PhpPrometheus\Middleware\RequestPerRoute;
use U9\U9PhpPrometheus\PrometheusExporter;
use U9\U9PhpPrometheus\Storage\APCU;

class PrometheusExporterServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
    }

    /**
     * Register the service provider.
     *
     * @throws \ErrorException
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../Config/config.php', 'prometheus-exporter');

        switch (config('prometheus-exporter.adapter')) {
            case 'apc':
                $this->app->bind(Adapter::class, APCU::class);
                break;
            case 'redis':
                $this->app->bind(Adapter::class, function () {
                    return new Redis(config('prometheus-exporter.redis'));
                });
                break;
            case 'push':
                $this->app->bind(Adapter::class, APCU::class);
                break;
            case 'inmemory':
                $this->app->bind(Adapter::class, InMemory::class);
                break;
            default:
                throw new \ErrorException('"prometheus-exporter.adapter" must be either apc or redis');
        }

        //lumen
        if (class_exists('Laravel\Lumen\Application', false)) {
            $this->app->middleware([
                RequestPerRoute::class
            ]);
        } else {
            //>=Laravel 5.4
            $router = $this->app['router'];
            $router->aliasMiddleware('lpe.requestPerRoute', RequestPerRoute::class);
        }

        //定义暴露节点
        $this->app->get(env('APP_ROUTE_PREFIX', '') . '/metrics', PrometheusExporterController::class . '@metrics');
        $this->app->bind(PrometheusExporterContract::class, PrometheusExporter::class, true);
    }
}
