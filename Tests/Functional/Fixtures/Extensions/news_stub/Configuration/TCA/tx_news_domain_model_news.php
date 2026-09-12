<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'News (stub)',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'versioningWS' => true,
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'title, hidden'],
    ],
    'columns' => [
        'hidden' => [
            'label' => 'Hidden',
            'config' => ['type' => 'check'],
        ],
        'title' => [
            'label' => 'Title',
            'config' => ['type' => 'input', 'size' => 50],
        ],
    ],
];
