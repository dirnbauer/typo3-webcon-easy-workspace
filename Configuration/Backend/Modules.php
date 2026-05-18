<?php

declare(strict_types=1);

use Webconsulting\WebconEasyWorkspace\Controller\Backend\EasyWorkspaceModuleController;

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
        'routes' => [
            '_default' => [
                'target' => EasyWorkspaceModuleController::class . '::handleRequest',
            ],
        ],
    ],
];
