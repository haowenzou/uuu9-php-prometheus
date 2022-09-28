<?php

namespace U9\U9PhpPrometheus\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use U9\U9PhpPrometheus\Contract\PrometheusExporterContract;

class RequestPerRoute
{
    /** @var PrometheusExporterContract */
    private $prometheusExporter;

    /**
     * RequestPerRoute constructor.
     * @param PrometheusExporterContract $prometheusExporter
     */
    public function __construct(PrometheusExporterContract $prometheusExporter)
    {
        $this->prometheusExporter = $prometheusExporter;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Closure $next
     * @return mixed
     *
     * @throws \Prometheus\Exception\MetricsRegistrationException
     */
    public function handle(Request $request, Closure $next)
    {
        $start = microtime(true);

        /** @var \Illuminate\Http\Response $response */
        $response = $next($request);
        // Do not log OPTIONS OR HEAD requests
        if ($request->isMethod('OPTIONS') || $request->isMethod('HEAD')) {
            return $response;
        }

        $durationSeconds = (microtime(true) - $start);

        //api版本号
        $versionAccept = $request->header('accept');
        preg_match('/application\/vnd\.uuu9\.v(\d)\+json/', $versionAccept, $matches);
        $version = '0';
        if ($matches) {
            $version = $matches[1];
        }

        $uri = $this->getRouteUri($request);
        $method = $request->getMethod();
        $status = $response->getStatusCode();

        $this->requestCountMetric($version, $uri, $method, $status);
        $this->requestLatencyMetric($version, $uri, $method, $durationSeconds);

        return $response;
    }

    /**
     * Get custom route uri.
     * Is not just http request uri, support regular expression constraints route uri.
     *
     * @param Request $request
     * @return string
     */
    private function getRouteUri(Request $request)
    {
        //lumen
        if (class_exists('Laravel\Lumen\Application', false)) {
            return $this->getLumenRouteUri($request);
        }

        return $this->getLaravelRouteUri($request);
    }

    /**
     * @param Request $request
     * @return string
     */
    private function getLumenRouteUri(Request $request)
    {
        $currentRoute = $request->route();
        // Is regular expression constraints route.
        if ($currentRoute && !empty($currentRoute[2])) {
            try {
                // Matching route
                $matchedRoutes = $this->matchRoute($request, $currentRoute);

                // Perfect matched
                if (count($matchedRoutes) === 1) {
                    return $matchedRoutes[0]['uri'];
                } elseif (count($matchedRoutes) > 1) {
                    // Has many matched
                    foreach ($matchedRoutes as $matchedRoute) {
                        // Do route matching again
                        $dispatcher = \FastRoute\simpleDispatcher(
                            function (\FastRoute\RouteCollector $r) use ($matchedRoute, $currentRoute) {
                                $r->addRoute($matchedRoute['method'], $matchedRoute['uri'], $currentRoute[1]);
                            }
                        );
                        $routeInfo = $dispatcher->dispatch($request->method(), $request->getPathInfo());
                        if ($routeInfo === $currentRoute) {
                            return $matchedRoute['uri'];
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error("监控匹配URL异常：" . $request->getPathInfo() . $e->getMessage() . $e->getTraceAsString());
            }
        }

        return $request->getPathInfo();
    }

    /**
     * @param Request $request
     * @param $currentRoute
     * @return array
     */
    private function matchRoute(Request $request, $currentRoute)
    {
        $cacheKey = '';
        $matchedRoutes = [];

        // route handler isn't \Closure
        if (isset($currentRoute[1]['uses'])) {
            try {
                //$currentRoute[1] is a route handler
                $cacheKey = env('APP_ROUTE_PREFIX', '') . ':routes:' . $currentRoute[1]['uses'];
                $matchedRoutes = apcu_fetch($cacheKey);
                $matchedRoutes = unserialize($matchedRoutes);
                if ($matchedRoutes) {
                    return $matchedRoutes;
                }
            } catch (\Exception $e) {
                //serialize error
                Log::error("监控匹配Route异常：" . $request->getPathInfo() . $e->getMessage() . $e->getTraceAsString());
            }
        }

        $routes = app()->getRoutes();
        foreach ($routes as $route) {
            if ($route['method'] = $request->method()) {
                if (isset($route['action']) && $route['action'] === $currentRoute[1]) {
                    $matchedRoutes[] = $route;
                }
            }
        }

        //if has $cacheKey, $matchedRoutes can be serialized
        $cacheKey && apcu_store($cacheKey, serialize($matchedRoutes));

        return $matchedRoutes;
    }

    /**
     * @param Request $request
     * @return string
     */
    private function getLaravelRouteUri(Request $request)
    {
        //TODO::Laravel
        return $request->getPathInfo();
    }

    /**
     * @param string $version api版本
     * @param string $uri
     * @param string $method
     * @param int $status
     * @throws \Prometheus\Exception\MetricsRegistrationException
     */
    private function requestCountMetric(string $version, string $uri, string $method, int $status)
    {
        $this->prometheusExporter->incCounter(
            'http_response_status_count',
            'the number of http requests',
            config('prometheus-exporter.namespace_http', ''),
            [
                'uri',
                'method',
                'status',
                'version'
            ],
            [
                $uri,
                $method,
                $status,
                $version
            ]
        );
    }

    /**
     * @param string $version api版本
     * @param string $uri
     * @param string $method
     * @param float $duration 耗时 秒
     * @throws \Prometheus\Exception\MetricsRegistrationException
     */
    private function requestLatencyMetric(string $version, string $uri, string $method, float $duration)
    {
        $this->prometheusExporter->setHistogram(
            'http_response_duration_s',
            'duration of requests',
            $duration,
            config('prometheus-exporter.namespace_http', ''),
            [
                'uri',
                'method',
                'version'
            ],
            [
                $uri,
                $method,
                $version
            ],
            config('prometheus-exporter.buckets_per_route')
        );
    }
}
