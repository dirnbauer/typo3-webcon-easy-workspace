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
];
