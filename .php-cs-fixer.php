<?php

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__.'/administrator/src',
        __DIR__.'/site/src',
        __DIR__.'/api/src',
        __DIR__.'/modules/mod_alfa_cart/src',
        __DIR__.'/modules/mod_alfa_search/src',
        __DIR__.'/plugins/alfa-payments/revolut/src',
        __DIR__.'/plugins/alfa-payments/viva/src',
        __DIR__.'/plugins/alfa-payments/standard/src',
        __DIR__.'/plugins/alfa-shipments/boxnow/src',
        __DIR__.'/plugins/alfa-shipments/standard/src',
        __DIR__.'/plugins/alfa-fields/text/src',
        __DIR__.'/plugins/alfa-fields/textarea/src',
    ]);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setFinder($finder);
