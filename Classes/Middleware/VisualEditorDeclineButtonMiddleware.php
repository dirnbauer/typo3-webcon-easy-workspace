<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Page\AssetCollector;
use Webconsulting\WebconEasyWorkspace\Security\BackendAccessGuard;
use Webconsulting\WebconEasyWorkspace\Service\LocalizationService;

final readonly class VisualEditorDeclineButtonMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AssetCollector $assetCollector,
        private LocalizationService $localizationService,
        private BackendAccessGuard $guard,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->shouldLoad($request)) {
            $this->assetCollector->addJavaScriptModule('@webconsulting/webcon-easy-workspace/visual-editor-decline-button.js');
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

        // The frontend backend-user authenticator, which this middleware runs
        // after, fills $GLOBALS['BE_USER'] — it sets no "backend.user" request
        // attribute, so reading only that attribute kept the button off for
        // every editor. The guard checks both.
        $backendUser = $this->guard->user($request);

        return $backendUser !== null && $backendUser->workspace > 0;
    }
}
