<?php

return [
    // 使用APCu存储数据
    'adapter' => env('PROMETHEUS_ADAPTER', 'apc'),

    // 存储key前缀，防止不同项目数据重复
    'adapter_key_prefix' => env('APP_ROUTE_PREFIX', ''),

    // 响应时间按照 100ms, 500ms, 1s, 5s, 10s 来区分
    'buckets_per_route' => [0.1, 0.5, 1, 5, 10],
];
