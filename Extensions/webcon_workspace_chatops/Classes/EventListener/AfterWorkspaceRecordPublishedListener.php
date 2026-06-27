<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Workspaces\Event\AfterRecordPublishedEvent;
use Webconsulting\WebconWorkspaceChatops\Configuration\ChatOpsConfiguration;
use Webconsulting\WebconWorkspaceChatops\Dto\WorkspaceEventPayload;
use Webconsulting\WebconWorkspaceChatops\Dto\WorkspaceRecordSelection;
use Webconsulting\WebconWorkspaceChatops\Enum\WorkspaceEventType;
use Webconsulting\WebconWorkspaceChatops\Notification\NotificationDispatcher;
use Webconsulting\WebconWorkspaceChatops\Service\LocalizationService;

#[AsEventListener('webcon-workspace-chatops/after-record-published')]
final readonly class AfterWorkspaceRecordPublishedListener
{
    public function __construct(
        private ChatOpsConfiguration $configuration,
        private NotificationDispatcher $notificationDispatcher,
        private LocalizationService $localizationService,
    ) {}

    public function __invoke(AfterRecordPublishedEvent $event): void
    {
        if (!$this->configuration->isEnabled() || !$this->configuration->notifyAfterRecordPublished()) {
            return;
        }
        $payload = new WorkspaceEventPayload(
            type: WorkspaceEventType::Published,
            title: $this->localizationService->translate(WorkspaceEventType::Published->titleLabelKey()),
            message: $event->getTable() . ':' . $event->getRecordId(),
            workspaceId: $event->getWorkspaceId(),
            records: [new WorkspaceRecordSelection($event->getTable(), $event->getRecordId())],
        );

        $this->notificationDispatcher->dispatch($payload);
    }
}
