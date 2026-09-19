<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// Mirrors EXT:news: the reverse side of tx_news_domain_model_news.content_elements.
ExtensionManagementUtility::addTCAcolumns('tt_content', [
    'tx_news_related_news' => [
        'label' => 'tx_news_related_news',
        'config' => [
            'type' => 'passthrough',
        ],
    ],
]);
