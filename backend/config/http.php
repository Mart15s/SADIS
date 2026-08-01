<?php

$trustedProxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1')))));

return ['trusted_proxies' => $trustedProxies];
