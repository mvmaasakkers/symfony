<?php

$container->loadFromExtension('framework', [
    'http_method_override' => false,
    'html_sanitizer' => [
        'sanitizers' => [
            'custom_default' => null,
        ],
    ],
]);
