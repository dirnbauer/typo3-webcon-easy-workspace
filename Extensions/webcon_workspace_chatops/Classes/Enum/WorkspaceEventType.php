<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Enum;

enum WorkspaceEventType: string
{
    case ReviewRequested = 'workspace.review.requested';
    case Published = 'workspace.published';
    case ScheduledPublication = 'workspace.publication.scheduled';
    case DeploymentStatus = 'deployment.status';
    case Incident = 'incident';
    case Generic = 'generic';

    public static function fromString(string $value): self
    {
        return match (strtolower(trim($value))) {
            self::ReviewRequested->value, 'review_requested', 'review-requested' => self::ReviewRequested,
            self::Published->value, 'published', 'content_published' => self::Published,
            self::ScheduledPublication->value, 'scheduled_publication', 'publication_scheduled' => self::ScheduledPublication,
            self::DeploymentStatus->value, 'deployment', 'deployment_status' => self::DeploymentStatus,
            self::Incident->value, 'sentry', 'monitoring', 'alert' => self::Incident,
            default => self::Generic,
        };
    }

    public function titleLabelKey(): string
    {
        return match ($this) {
            self::ReviewRequested => 'notification.reviewRequested.title',
            self::Published => 'notification.published.title',
            self::ScheduledPublication => 'notification.scheduled.title',
            self::DeploymentStatus => 'notification.deployment.title',
            self::Incident => 'notification.incident.title',
            self::Generic => 'userSettings.tab',
        };
    }

    public function userSettingKey(): string
    {
        return match ($this) {
            self::ReviewRequested => 'webconWorkspaceChatopsNotifyReviewRequested',
            self::Published => 'webconWorkspaceChatopsNotifyPublished',
            self::ScheduledPublication => 'webconWorkspaceChatopsNotifyScheduledPublication',
            self::DeploymentStatus => 'webconWorkspaceChatopsNotifyDeploymentStatus',
            self::Incident => 'webconWorkspaceChatopsNotifyIncident',
            self::Generic => 'webconWorkspaceChatopsEnabled',
        };
    }
}
