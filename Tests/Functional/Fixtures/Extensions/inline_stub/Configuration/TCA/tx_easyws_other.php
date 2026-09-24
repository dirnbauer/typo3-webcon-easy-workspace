<?php

declare(strict_types=1);

// A Content Blocks collection table: workspace-aware, hidden from the
// record list, children of a tt_content row via foreign_table_parent_uid.
return [
    'ctrl' => [
        'title' => 'Collection other (stub)',
        'label' => 'header',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'sortby' => 'sorting',
        'versioningWS' => true,
        'hideTable' => true,
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'header'],
    ],
    'columns' => [
        'header' => [
            'label' => 'Header',
            'config' => ['type' => 'input', 'size' => 50],
        ],
        'foreign_table_parent_uid' => [
            'config' => ['type' => 'passthrough'],
        ],
    ],
];
