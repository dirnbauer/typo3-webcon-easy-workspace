<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use Webconsulting\WebconEasyWorkspace\Hooks\WorkspaceRevisionHook;

defined('TYPO3') or die();

// Moves the badge stamp after every write to a workspace-aware table.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['webcon_easy_workspace']
    = WorkspaceRevisionHook::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['webcon_easy_workspace']
    = WorkspaceRevisionHook::class;

// The per-page change count of the badge, keyed by the workspace stamp (see
// ContextChangeSummary). One small entry per page or news article that is
// overwritten in place, so a file backend needs no garbage collection.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['webcon_easy_workspace'] ??= [
    'frontend' => VariableFrontend::class,
    'backend' => SimpleFileBackend::class,
    'groups' => ['system'],
];
