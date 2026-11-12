<?php

return [

    /*
    | Networks allowed to call /health/ready. Defaults to loopback and the
    | private ranges used by container networks. Requests from anywhere else
    | receive 404. Behind a reverse proxy, trusted proxies must be configured
    | so the client address is the real one.
    */
    'ready_allowed_cidrs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('HEALTH_READY_ALLOWED_CIDRS', '127.0.0.0/8,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'))
    ))),

];
