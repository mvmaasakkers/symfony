<?php

$container->loadFromExtension('framework', [
    'http_method_override' => false,
    'validation' => [
        'translation_domain' => 'messages',
    ],
]);
