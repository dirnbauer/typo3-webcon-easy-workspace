<?php

declare(strict_types=1);

use Webconsulting\WebconEasyWorkspace\Hook\WorkspaceChangeInvalidationHook;

defined('TYPO3') or die();

$typo3Configuration = &$GLOBALS['TYPO3_CONF_VARS'];
if (!is_array($typo3Configuration)) {
    $typo3Configuration = [];
}
$scOptions = &$typo3Configuration['SC_OPTIONS'];
if (!is_array($scOptions)) {
    $scOptions = [];
}
$dataHandlerOptions = &$scOptions['t3lib/class.t3lib_tcemain.php'];
if (!is_array($dataHandlerOptions)) {
    $dataHandlerOptions = [];
}

foreach (['processDatamapClass', 'processCmdmapClass'] as $hookType) {
    if (!is_array($dataHandlerOptions[$hookType] ?? null)) {
        $dataHandlerOptions[$hookType] = [];
    }
    $dataHandlerOptions[$hookType]['webcon_easy_workspace'] = WorkspaceChangeInvalidationHook::class;
}
