<?php

class LumenTest extends TestCase
{

    //创建基境
    /**
     * Setup the test environment.
     */
    public function setUp()
    {
        //清空APC缓存
        apcu_clear_cache();
        parent::setUp();

        $this->app->register(\U9\U9PhpPrometheus\Provider\PrometheusExporterServiceProvider::class);

        $this->app->get("/testRoute", function () {
            return "testRoute";
        });
    }

    /**
     * 测试Counter
     * @test
     */
    public function testCounter()
    {
        $this->get("/testRoute");

        $metricBefore = $this->getMetricValue("http_response_status_count", "testRoute");


        $this->get("/testRoute");

        $metricAfter = $this->getMetricValue('http_response_status_count', "testRoute");

        //第二次访问计数大于第一次
        $this->assertGreaterThan($metricBefore, $metricAfter);
    }

    private function getMetricValue(string $name, $uri)
    {
        $pattern = sprintf('/%s.*\{.*' . $uri . '.*\}\s*(\d+)/', $name);

        if (preg_match($pattern, $this->getMetricResponse()->getContent(), $matches)) {
            return intval($matches[1]);
        }

        return null;
    }

    private function getMetricResponse()
    {
        return $this->call("GET", '/metrics');
    }

    /**
     * 测试Histogram
     * @test
     */
    public function testHistogram()
    {
        $this->get("/testRoute");

        $metricBefore = $this->getMetricValue("http_response_duration_s", "testRoute");


        $this->get("/testRoute");

        $metricAfter = $this->getMetricValue('http_response_duration_s', "testRoute");

        //第二次访问计数大于第一次
        $this->assertGreaterThan($metricBefore, $metricAfter);
    }

    /**
     * 测试 正则表达式 路由
     */
    public function testRegularExpressionRoute()
    {
        $do = function () {
        };

        $this->app->get('/testRoute/{id:[0-9]+}/{name:[0-9]+}', $do);
        $this->app->get("/testRoute/{id}/{name}", $do);

        $this->get("/testRoute/123/321");

        $metricBefore = $this->getMetricValue("http_response_duration_s", 'testRoute\/\{id\:\[0-9\]\+\}');
        $this->assertEquals($metricBefore, 1);
    }

    /**
     * 测试 缓存 路由
     */
    public function testRegularExpressionRouteCache()
    {


        $this->app->get('/testRoute/{id:[0-9]+}/{name:[0-9]+}', \U9\U9PhpPrometheus\Controller\PrometheusExporterController::class . '@metrics');
        $this->app->get("/testRoute/{id}/{name}", \U9\U9PhpPrometheus\Controller\PrometheusExporterController::class . '@metrics');

        $this->get("/testRoute/123/321");

        $metricBefore = $this->getMetricValue("http_response_duration_s", 'testRoute\/\{id\:\[0-9\]\+\}');
        $this->assertEquals($metricBefore, 1);

        $currentRoute = app('request')->route();
        $cacheKey = env('APP_ROUTE_PREFIX', '') . ':routes:' . $currentRoute[1]['uses'];
        $cacheRoute = unserialize(apcu_fetch($cacheKey));

        $this->assertGreaterThanOrEqual(2, count($cacheRoute));
    }


    /**
     * test http method OPTIONS request
     * @test
     */
    public function testHttpMethodOptionsRequest()
    {
        //绑定了跨域组件的路由
        $this->app->get("/testOptions", function () {
            return "testOptions";
        });


        //满足跨域中间件的写法
        $currentUri = $this->prepareUrlForRequest('/testOptions');
        $request = Illuminate\Http\Request::create($currentUri, 'OPTIONS');
        $this->app->bind('request',function () use($request){
            return $request;
        });
        $this->app->register(\uuu9\Cors\Middleware\CorsServiceProvider::class);

        //用Options method 请求接口
        $server = $this->transformHeadersToServerVars([]);
        $response = $this->call('OPTIONS', '/testOptions', [], [], [], $server);

        //prometheus不记录
        $count = $this->getMetricValue('http_response_status_count', "testRoute");
        $this->assertEquals(0, $count);

        //lumen5.3有效
        //TODO::lumen5.4
        if (stripos($this->app->version(),'5.3.*')){
            $this->assertEquals(204, $response->getStatusCode());
        }
    }

    /**
     * test http method HEAD request
     * @test
     */
    public function testHttpMethodHeadRequest()
    {
        $server = $this->transformHeadersToServerVars([]);
        $this->call('HEAD', '/testRoute', [], [], [], $server);

        $count = $this->getMetricValue('http_response_status_count', "testRoute");
        $this->assertEquals(0, $count);
    }
}
