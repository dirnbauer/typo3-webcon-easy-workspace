<?php

declare(strict_types=1);

use TYPO3\CodingStandards\CsFixerConfig;

$config = CsFixerConfig::create();
$config->getFinder()
    ->in(__DIR__ . '/Classes')
    ->in(__DIR__ . '/Configuration')
    ->in(__DIR__ . '/Tests')
    ->in(__DIR__ . '/Extensions/webcon_workspace_chatops/Classes')
    ->in(__DIR__ . '/Extensions/webcon_workspace_chatops/Configuration')
    ->append([
        __DIR__ . '/Extensions/webcon_workspace_chatops/ext_localconf.php',
        __FILE__,
    ]);
$config->setCacheFile(__DIR__ . '/.Build/.php-cs-fixer.cache');

return $config;
