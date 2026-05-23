<?php

declare(strict_types=1);

use Webconsulting\WebconEasyWorkspace\Controller\Backend\EasyWorkspaceModuleController;

$moduleDefaults = [
    'access' => 'user',
    'workspaces' => 'offline',
    'iconIdentifier' => 'module-workspaces',
    'routes' => [
        '_default' => [
            'target' => EasyWorkspaceModuleController::class . '::handleRequest',
        ],
    ],
];

return [
    'webcon_easy_workspace' => [
        'parent' => 'content',
        'position' => ['after' => 'workspaces_publish'],
        'access' => 'user',
        'workspaces' => 'offline',
        'path' => '/module/content/easy-workspace',
        'iconIdentifier' => 'module-workspaces',
        'labels' => 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/Modules/easy_workspace.xlf',
        'navigationComponent' => '@typo3/backend/tree/page-tree-element',
        'appearance' => [
            'dependsOnSubmodules' => true,
        ],
        'showSubmoduleOverview' => false,
    ],
    'webcon_easy_workspace_pending' => $moduleDefaults + [
        'parent' => 'webcon_easy_workspace',
        'position' => ['before' => '*'],
        'path' => '/module/content/easy-workspace/pending',
        'labels' => [
            'title' => 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:module.section.pending',
            'description' => 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:module.pending.subtitle',
            'shortDescription' => 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:module.pending.subtitle',
        ],
    ],
    'webcon_easy_workspace_records' => $moduleDefaults + [
        'parent' => 'webcon_easy_workspace',
        'position' => ['after' => 'webcon_easy_workspace_pending'],
        'path' => '/module/content/easy-workspace/records',
        'labels' => [
            'title' => 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:module.section.all',
            'description' => 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:module.all.subtitle',
            'shortDescription' => 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:module.all.subtitle',
        ],
    ],
    'webcon_easy_workspace_diagnostics' => $moduleDefaults + [
        'parent' => 'webcon_easy_workspace',
        'position' => ['after' => 'webcon_easy_workspace_records'],
        'path' => '/module/content/easy-workspace/diagnostics',
        'labels' => [
            'title' => 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:module.section.testsDiagnostics',
            'description' => 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:module.testsDiagnostics.subtitle',
            'shortDescription' => 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:module.testsDiagnostics.subtitle',
        ],
    ],
];
