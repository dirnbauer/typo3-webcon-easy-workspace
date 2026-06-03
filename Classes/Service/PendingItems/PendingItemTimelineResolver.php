<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use Webconsulting\WebconEasyWorkspace\Service\LocalizationService;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

final readonly class PendingItemTimelineResolver
{
    public function __construct(
        private LocalizationService $localizationService,
    ) {}

    /**
     * @param list<array{tstamp: int, userUid: int, user: string}> $timeline
     * @return array{tstamp: int, userUid: int, user: string}
     */
    public function latestChangeFromTimeline(array $timeline, int $fallbackTimestamp): array
    {
        $latest = ['tstamp' => $fallbackTimestamp, 'userUid' => 0, 'user' => ''];
        foreach ($timeline as $entry) {
            $timestamp = Value::int($entry['tstamp'] ?? null);
            if ($timestamp <= 0 || $timestamp < $latest['tstamp']) {
                continue;
            }
            $latest = [
                'tstamp' => $timestamp,
                'userUid' => Value::int($entry['userUid'] ?? null),
                'user' => Value::string($entry['user'] ?? null),
            ];
        }
        return $latest;
    }

    /**
     * @param list<array{actionKey: string}> $timeline
     * @return list<array{kindKey: string, kindLabel: string, badge: string}>
     */
    public function changeBadgesFromTimeline(array $timeline, string $fallbackKindKey, string $fallbackKindLabel, string $fallbackBadge): array
    {
        $badges = [];
        foreach ($timeline as $entry) {
            $kindKey = $this->normalizeChangeBadgeKey(Value::string($entry['actionKey'] ?? null));
            if ($kindKey === '' || isset($badges[$kindKey])) {
                continue;
            }
            $badges[$kindKey] = $this->changeBadgeForKind($kindKey);
        }

        if ($badges === []) {
            $kindKey = $this->normalizeChangeBadgeKey($fallbackKindKey);
            if ($kindKey !== '') {
                $badges[$kindKey] = in_array($kindKey, ['created', 'modified', 'moved', 'deleted'], true)
                    ? $this->changeBadgeForKind($kindKey)
                    : [
                        'kindKey' => $kindKey,
                        'kindLabel' => $fallbackKindLabel,
                        'badge' => $fallbackBadge ?: 'info',
                    ];
            }
        }

        return array_values($badges);
    }

    /**
     * @return array{kindKey: string, kindLabel: string, badge: string}
     */
    public function changeBadgeForKind(string $kindKey): array
    {
        return match ($kindKey) {
            'created' => [
                'kindKey' => 'created',
                'kindLabel' => $this->localizationService->translate('history.action.created'),
                'badge' => 'success',
            ],
            'moved' => [
                'kindKey' => 'moved',
                'kindLabel' => $this->localizationService->translate('history.action.moved'),
                'badge' => 'warning',
            ],
            'deleted' => [
                'kindKey' => 'deleted',
                'kindLabel' => $this->localizationService->translate('history.action.deleted'),
                'badge' => 'danger',
            ],
            default => [
                'kindKey' => 'modified',
                'kindLabel' => $this->localizationService->translate('history.action.modified'),
                'badge' => 'info',
            ],
        };
    }

    /**
     * @param list<array{actionKey: string, diffs: list<array{field: string}>}> $timeline
     */
    public function countModifiedFieldsInTimeline(array $timeline): int
    {
        $fields = [];
        foreach ($timeline as $entry) {
            if (Value::string($entry['actionKey'] ?? null) !== 'modified') {
                continue;
            }
            foreach ($this->listArray($entry['diffs'] ?? null) as $diff) {
                if (!is_array($diff)) {
                    continue;
                }
                $field = Value::string($diff['field'] ?? null);
                if ($field !== '') {
                    $fields[$field] = true;
                }
            }
        }
        return count($fields);
    }

    public function normalizeChangeBadgeKey(string $kindKey): string
    {
        return match ($kindKey) {
            'changed' => 'modified',
            'move' => 'moved',
            'new' => 'created',
            'delete' => 'deleted',
            default => $kindKey,
        };
    }

    /**
     * @return list<mixed>
     */
    private function listArray(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
