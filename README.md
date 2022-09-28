# uuu9-php-prometheus
## metrics规范 TODO
http://git.vpgame.cn/infra/desgin-docs/blob/master/confirmed/monitor/prometheus_metrics.md

## 依赖
- [ext-apcu](https://pecl.php.net/package/APCU): 使用共享内存来维护PHP-FPM多进程状态
- [jimdo/prometheus_client_php](https://packagist.org/packages/jimdo/prometheus_client_php): PrometheusSDK

## 代码参考
https://github.com/triadev/LaravelPrometheusExporter

## 支持框架
- lumen5.3
- lumen5.4
- _laravel(待支持)_

## 目录
```
├── src
│   ├── Config
│   │   └── config.php 基本配置
│   ├── Contract
│   │   └── PrometheusExporterContract.php Interface
│   ├── Controller
│   │   └── PrometheusExporterController.php 控制器，暴露数据接口
│   ├── Middleware
│   │   └── RequestPerRoute.php 路由中间件(主业务)
│   ├── PrometheusExporter.php 整合PrometheusSDK调用，implements PrometheusExporterContract
│   ├── Provider
│   │   └── PrometheusExporterServiceProvider.php 服务提供者，框架启动时加载注册
│   └── Storage
│       └── APCU.php 数据存储适配器
```


## lumen项目配置
1 `composer.json` repositories节点新增
```json
{
    "type": "vcs",
    "url": "git@git.vpgame.cn:infra/vp-php-prometheus.git"
}
```

2 运行命令
```bash
composer require uuu9/uuu9-php-prometheus
```

3 `.env.tp`文件，新增 `APP_ROUTE_PREFIX=路由前缀`，如`APP_ROUTE_PREFIX=sso`

4 `bootstrap/app.php`
```php
//注册prometheus
$app->register(U9\U9PhpPrometheus\Provider\PrometheusExporterServiceProvider::class);
```

## laravel
laravel需要特殊处理

TODO:
- 新增Controller namespace Illuminate\Routing\Controller

