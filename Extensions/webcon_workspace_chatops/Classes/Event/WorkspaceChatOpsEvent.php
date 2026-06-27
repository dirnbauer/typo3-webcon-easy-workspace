<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Event;

use Webconsulting\WebconWorkspaceChatops\Dto\WorkspaceEventPayload;

final readonly class WorkspaceChatOpsEvent
{
    public function __construct(public WorkspaceEventPayload $payload) {}
}
