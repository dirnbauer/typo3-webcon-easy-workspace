<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Security;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Core\Environment;
use Webconsulting\WebconWorkspaceChatops\Configuration\ChatOpsConfiguration;

final readonly class ApiAccessGuard
{
    public function __construct(private ChatOpsConfiguration $configuration) {}

    public function isAllowed(ServerRequestInterface $request): bool
    {
        $configuredToken = $this->configuration->apiToken();
        if ($configuredToken !== '') {
            $requestToken = $this->requestToken($request);

            return $requestToken !== '' && hash_equals($configuredToken, $requestToken);
        }

        return $this->allowsUnsignedDevelopmentRequest();
    }

    public function allowsUnsignedDevelopmentRequest(): bool
    {
        return $this->configuration->allowUnsignedDevelopmentRequests()
            && Environment::getContext()->isDevelopment();
    }

    private function requestToken(ServerRequestInterface $request): string
    {
        $authorization = trim($request->getHeaderLine('Authorization'));
        if (str_starts_with(strtolower($authorization), 'bearer ')) {
            return trim(substr($authorization, 7));
        }

        return trim($request->getHeaderLine('X-Webcon-ChatOps-Token'));
    }
}
