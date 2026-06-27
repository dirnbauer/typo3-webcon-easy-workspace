<?php

declare(strict_types=1);

use Webconsulting\WebconWorkspaceChatops\Middleware\ChatOpsApiMiddleware;

return [
    'frontend' => [
        'webconsulting/webcon-workspace-chatops/api' => [
            'target' => ChatOpsApiMiddleware::class,
            'before' => [
                'typo3/cms-frontend/site',
            ],
        ],
    ],
];
