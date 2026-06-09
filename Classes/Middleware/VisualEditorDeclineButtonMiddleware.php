<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Page\AssetCollector;
use Webconsulting\WebconEasyWorkspace\Service\LocalizationService;

final readonly class VisualEditorDeclineButtonMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AssetCollector $assetCollector,
        private LocalizationService $localizationService,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->shouldLoad($request)) {
            $this->assetCollector->addJavaScriptModule('@webconsulting/webcon-easy-workspace/visual-editor-decline-button');
            $this->assetCollector->addInlineJavaScript(
                'webcon-easy-workspace-decline-labels',
                sprintf(
                    'window.webconEasyWorkspaceDeclineLabels = %s;',
                    json_encode(
                        [
                            'title' => $this->localizationService->translate('discardTag.title'),
                            'subtitle' => $this->localizationService->translate('discardTag.subtitle'),
                        ],
                        JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
                    ),
                ),
                [],
                ['priority' => true],
            );
        }

        return $handler->handle($request);
    }

    private function shouldLoad(ServerRequestInterface $request): bool
    {
        if (!isset($request->getQueryParams()['editMode'])) {
            return false;
        }

        // Set by the frontend backend-user-authentication middleware,
        // which this middleware is registered after.
        $backendUser = $request->getAttribute('backend.user');

        return $backendUser instanceof BackendUserAuthentication && $backendUser->workspace > 0;
    }
}
