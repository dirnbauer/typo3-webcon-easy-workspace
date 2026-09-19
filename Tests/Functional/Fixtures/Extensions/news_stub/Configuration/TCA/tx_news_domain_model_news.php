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
        '0' => ['showitem' => 'title, hidden, content_elements'],
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
        // Mirrors EXT:news: the article's own content elements, an inline
        // relation whose foreign field lives on tt_content.
        'content_elements' => [
            'label' => 'Content elements',
            'config' => [
                'type' => 'inline',
                'allowed' => 'tt_content',
                'foreign_table' => 'tt_content',
                'foreign_sortby' => 'sorting',
                'foreign_field' => 'tx_news_related_news',
                'minitems' => 0,
                'maxitems' => 99,
            ],
        ],
    ],
];
