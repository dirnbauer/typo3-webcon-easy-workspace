<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// Shaped like a Content Blocks installation: every collection field is a
// base tt_content column, although only its own CType shows it. The extra
// fields share one child table and are told apart by foreign_match_fields,
// so a page of N elements used to cost N x (number of inline fields)
// queries before a single change was found.
$inline = static fn(string $table, array $extra = []): array => [
    'label' => $table,
    'config' => [
        'type' => 'inline',
        'foreign_table' => $table,
        'foreign_field' => 'foreign_table_parent_uid',
        'foreign_sortby' => 'sorting',
        'minitems' => 0,
        'maxitems' => 99,
    ] + $extra,
];

$columns = [
    'easyws_items' => $inline('tx_easyws_item', ['foreign_match_fields' => ['fieldname' => 'easyws_items']]),
    'easyws_others' => $inline('tx_easyws_other'),
];
for ($i = 1; $i <= 24; $i++) {
    $columns['easyws_extra_' . $i] = $inline('tx_easyws_item', ['foreign_match_fields' => ['fieldname' => 'easyws_extra_' . $i]]);
}
ExtensionManagementUtility::addTCAcolumns('tt_content', $columns);

ExtensionManagementUtility::addRecordType(
    ['label' => 'Collection (stub)', 'value' => 'easyws_collection', 'group' => 'default'],
    '--palette--;;general, header, easyws_items, easyws_extra_3',
);
// A type override, as Content Blocks writes them for every collection field.
$GLOBALS['TCA']['tt_content']['types']['easyws_collection']['columnsOverrides']['easyws_items']['label'] = 'Items (collection)';
