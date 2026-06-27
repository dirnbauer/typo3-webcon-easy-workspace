<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Webconsulting\WebconWorkspaceChatops\Configuration\ChatOpsConfiguration;
use Webconsulting\WebconWorkspaceChatops\Controller\ChatOpsApiController;

final readonly class ChatOpsApiMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ChatOpsConfiguration $configuration,
        private ChatOpsApiController $controller,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (rtrim($request->getUri()->getPath(), '/') !== rtrim($this->configuration->apiPath(), '/')) {
            return $handler->handle($request);
        }

        return $this->controller->handle($request);
    }
}
