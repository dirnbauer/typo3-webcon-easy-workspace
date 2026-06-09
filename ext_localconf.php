<?php

declare(strict_types=1);

use Webconsulting\WebconEasyWorkspace\Hook\WorkspaceChangeInvalidationHook;

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['webcon_easy_workspace'] = WorkspaceChangeInvalidationHook::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['webcon_easy_workspace'] = WorkspaceChangeInvalidationHook::class;
