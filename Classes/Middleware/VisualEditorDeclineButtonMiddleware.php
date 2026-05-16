<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Page\AssetCollector;

final readonly class VisualEditorDeclineButtonMiddleware implements MiddlewareInterface
{
    public function __construct(private AssetCollector $assetCollector) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->shouldLoad($request)) {
            $this->assetCollector->addJavaScriptModule('@webconsulting/webcon-easy-workspace/visual-editor-decline-button');
        }

        return $handler->handle($request);
    }

    private function shouldLoad(ServerRequestInterface $request): bool
    {
        $queryParams = $request->getQueryParams();
        if (!isset($queryParams['editMode'])) {
            return false;
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }

        return (int)($backendUser->workspace ?? 0) > 0;
    }
}
