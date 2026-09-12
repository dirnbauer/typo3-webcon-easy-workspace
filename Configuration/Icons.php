<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

$icon = static fn (string $name): array => [
    'provider' => SvgIconProvider::class,
    'source' => 'EXT:webcon_easy_workspace/Resources/Public/Icons/' . $name . '.svg',
];

return [
    'wew-toolbar' => $icon('wew-toolbar'),
    'wew-module' => $icon('wew-module'),
    'wew-change-new' => $icon('wew-change-new'),
    'wew-change-changed' => $icon('wew-change-changed'),
    'wew-change-deleted' => $icon('wew-change-deleted'),
    'wew-change-moved' => $icon('wew-change-moved'),
    'wew-publish' => $icon('wew-publish'),
    'wew-discard' => $icon('wew-discard'),
    'wew-diff' => $icon('wew-diff'),
    'wew-preview' => $icon('wew-preview'),
];
