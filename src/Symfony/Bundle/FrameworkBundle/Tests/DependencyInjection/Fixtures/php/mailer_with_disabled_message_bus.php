<?php

$container->loadFromExtension('framework', [
    'http_method_override' => false,
    'mailer' => [
        'dsn' => 'smtp://example.com',
        'message_bus' => false,
    ],
]);
