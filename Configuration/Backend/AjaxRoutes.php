<?php

declare(strict_types=1);

use Webconsulting\WebconEasyWorkspace\Controller\Backend\EasyWorkspaceAjaxController;

return [
    'webcon_easy_workspace_items' => [
        'path' => '/webcon-easy-workspace/items',
        'methods' => ['GET'],
        'target' => EasyWorkspaceAjaxController::class . '::itemsAction',
    ],
    'webcon_easy_workspace_has_changes' => [
        'path' => '/webcon-easy-workspace/has-changes',
        'methods' => ['GET'],
        'target' => EasyWorkspaceAjaxController::class . '::hasChangesAction',
    ],
    'webcon_easy_workspace_publish' => [
        'path' => '/webcon-easy-workspace/publish',
        'methods' => ['POST'],
        'target' => EasyWorkspaceAjaxController::class . '::publishAction',
    ],
    'webcon_easy_workspace_preview_link' => [
        'path' => '/webcon-easy-workspace/preview-link',
        'methods' => ['GET'],
        'target' => EasyWorkspaceAjaxController::class . '::previewLinkAction',
    ],
    'webcon_easy_workspace_discard' => [
        'path' => '/webcon-easy-workspace/discard',
        'methods' => ['POST'],
        'target' => EasyWorkspaceAjaxController::class . '::discardAction',
    ],
    'webcon_easy_workspace_latest' => [
        'path' => '/webcon-easy-workspace/latest',
        'methods' => ['GET'],
        'target' => EasyWorkspaceAjaxController::class . '::latestAction',
    ],
    'webcon_easy_workspace_diff' => [
        'path' => '/webcon-easy-workspace/diff',
        'methods' => ['GET'],
        'target' => EasyWorkspaceAjaxController::class . '::diffAction',
    ],
    'webcon_easy_workspace_history_rollback' => [
        'path' => '/webcon-easy-workspace/history-rollback',
        'methods' => ['POST'],
        'target' => EasyWorkspaceAjaxController::class . '::historyRollbackAction',
    ],
];
