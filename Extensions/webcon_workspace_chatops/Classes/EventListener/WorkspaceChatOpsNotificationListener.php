<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use Webconsulting\WebconWorkspaceChatops\Event\WorkspaceChatOpsEvent;
use Webconsulting\WebconWorkspaceChatops\Notification\NotificationDispatcher;

#[AsEventListener('webcon-workspace-chatops/dispatch-notifications')]
final readonly class WorkspaceChatOpsNotificationListener
{
    public function __construct(private NotificationDispatcher $notificationDispatcher) {}

    public function __invoke(WorkspaceChatOpsEvent $event): void
    {
        $this->notificationDispatcher->dispatch($event->payload);
    }
}
