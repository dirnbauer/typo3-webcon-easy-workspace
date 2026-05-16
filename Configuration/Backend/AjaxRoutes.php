<?php

declare(strict_types=1);

use Webconsulting\WebconEasyWorkspace\Controller\Backend\EasyWorkspaceAjaxController;

return [
    'webcon_easy_workspace_items' => [
        'path' => '/webcon-easy-workspace/items',
        'target' => EasyWorkspaceAjaxController::class . '::itemsAction',
    ],
    'webcon_easy_workspace_publish' => [
        'path' => '/webcon-easy-workspace/publish',
        'target' => EasyWorkspaceAjaxController::class . '::publishAction',
    ],
    'webcon_easy_workspace_preview_link' => [
        'path' => '/webcon-easy-workspace/preview-link',
        'target' => EasyWorkspaceAjaxController::class . '::previewLinkAction',
    ],
    'webcon_easy_workspace_discard' => [
        'path' => '/webcon-easy-workspace/discard',
        'target' => EasyWorkspaceAjaxController::class . '::discardAction',
    ],
];
