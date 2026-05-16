<?php

declare(strict_types=1);

use Webconsulting\WebconEasyWorkspace\Middleware\VisualEditorDeclineButtonMiddleware;

return [
    'frontend' => [
        'webconsulting/webcon-easy-workspace/visual-editor-decline-button' => [
            'target' => VisualEditorDeclineButtonMiddleware::class,
            'after' => [
                'typo3/cms-frontend/backend-user-authentication',
                'typo3/cms-frontend/page-resolver',
            ],
            'before' => [
                'typo3/cms-frontend/prepare-tsfe-rendering',
            ],
        ],
    ],
];
