<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Versioning\VersionState;
use Webconsulting\WebconEasyWorkspace\Dto\PendingItem;
use Webconsulting\WebconEasyWorkspace\Service\LocalizationService;
use Webconsulting\WebconEasyWorkspace\Service\RecordDiffService;
use Webconsulting\WebconEasyWorkspace\Service\RecordHistoryTimelineService;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * Builds {@see PendingItem} DTOs from workspace overlay rows.
 *
 * Field diffs are loaded on demand via the diff AJAX endpoint — not here.
 */
final readonly class PendingItemFactory
{
    public function __construct(
        private PendingItemLabelResolver $labelResolver,
        private PendingItemMediaResolver $mediaResolver,
        private PendingItemUrlBuilder $urlBuilder,
        private PendingItemTimelineResolver $timelineResolver,
        private RecordHistoryTimelineService $historyTimelineService,
        private RecordDiffService $recordDiffService,
        private LocalizationService $localizationService,
        private WorkspaceRecordQuery $workspaceRecordQuery,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public function resolveRecordItem(string $table, int $liveUid, int $workspaceId, bool $isPrimary, array $config = []): ?PendingItem
    {
        $row = $this->workspaceRecordQuery->resolveRecordRow($table, $liveUid, $workspaceId);
        return $row !== null ? $this->buildItem($table, $row, $isPrimary, $config) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $config
     * @param array<int, string> $columnLabels
     */
    public function buildItem(
        string $table,
        array $row,
        bool $isPrimary,
        array $config = [],
        array $columnLabels = [],
        ?string $locateTable = null,
        ?int $locateLiveUid = null,
        ?int $locateWorkspaceUid = null,
    ): ?PendingItem {
        $rawUid = Value::int($row['uid'] ?? null);
        if ($rawUid <= 0 || Value::int($row['deleted'] ?? null) !== 0) {
            return null;
        }

        $isHidden = (bool)($row['hidden'] ?? false);
        if ($isHidden && isset($config['showHidden']) && !$config['showHidden']) {
            return null;
        }

        $isChanged = isset($row['_ORIG_uid']) || Value::int($row['t3ver_wsid'] ?? null) > 0;
        if ($isChanged) {
            $workspaceUid = Value::int($row['_ORIG_uid'] ?? $row['uid'] ?? null);
            $liveUid = Value::int($row['t3ver_oid'] ?? null) ?: Value::int($row['uid'] ?? null);
            if (!$this->hasEditorVisibleWorkspaceChange($table, $workspaceUid)) {
                $isChanged = false;
                $workspaceUid = $liveUid;
            }
        } else {
            $workspaceUid = $rawUid;
            $liveUid = $rawUid;
        }

        $title = $this->labelResolver->resolveTitle($table, $row);
        $state = VersionState::tryFrom(Value::int($row['t3ver_state'] ?? null)) ?? VersionState::DEFAULT_STATE;
        if (!$isChanged) {
            $kindKey = 'live';
            $kindLabel = $this->localizationService->translate('state.live');
            $badge = 'secondary';
        } else {
            [$kindKey, $kindLabel, $badge] = match ($state) {
                VersionState::NEW_PLACEHOLDER => ['new', $this->localizationService->translate('state.new'), 'success'],
                VersionState::DELETE_PLACEHOLDER => ['delete', $this->localizationService->translate('state.delete'), 'danger'],
                VersionState::MOVE_POINTER => ['move', $this->localizationService->translate('state.move'), 'warning'],
                default => ['modified', $this->localizationService->translate('state.modified'), 'info'],
            };
        }

        $enableThumbnails = !isset($config['enableThumbnails']) || (bool)$config['enableThumbnails'];
        $editUrl = $this->urlBuilder->buildEditUrl($table, $liveUid);
        $contextualEditUrl = $this->urlBuilder->buildContextualEditUrl($table, $liveUid);
        $historyUrl = $this->urlBuilder->buildRecordHistoryUrl($table, $workspaceUid);

        $timeline = $isChanged ? $this->historyTimelineService->build($table, $workspaceUid) : [];
        $latestChange = $this->timelineResolver->latestChangeFromTimeline($timeline, Value::int($row['tstamp'] ?? null));
        $changeBadges = $isChanged
            ? $this->timelineResolver->changeBadgesFromTimeline($timeline, $kindKey, $kindLabel, $badge)
            : [];
        $historyDiffCount = $isChanged && $state === VersionState::NEW_PLACEHOLDER
            ? $this->timelineResolver->countModifiedFieldsInTimeline($timeline)
            : 0;

        $colPos = null;
        $colPosLabel = null;
        if ($table === 'tt_content' && array_key_exists('colPos', $row)) {
            $colPos = Value::int($row['colPos'] ?? null);
            $colPosLabel = $this->labelResolver->resolveColPosLabel($colPos, $columnLabels);
        }

        return (new PendingItem(
            table: $table,
            liveUid: $liveUid,
            workspaceUid: $workspaceUid,
            title: $title,
            kindKey: $kindKey,
            kindLabel: $kindLabel,
            badge: $badge,
            iconIdentifier: $this->labelResolver->resolveIconIdentifier($table, $row),
            thumbnailUrl: $enableThumbnails ? $this->mediaResolver->resolveThumbnailUrl($table, $workspaceUid) : null,
            isPrimary: $isPrimary,
            isChanged: $isChanged,
            isHidden: $isHidden,
            tableLabel: $this->labelResolver->resolveTableLabel($table),
            typeLabel: $this->labelResolver->resolveTypeLabel($table, $row),
            editUrl: $editUrl,
            contextualEditUrl: $contextualEditUrl,
            historyUrl: $historyUrl,
            diff: [],
            changeBadges: $changeBadges,
            childChanges: [],
            historyDiffCount: $historyDiffCount,
            colPos: $colPos,
            colPosLabel: $colPosLabel,
            locateTable: $locateTable,
            locateLiveUid: $locateLiveUid,
            locateWorkspaceUid: $locateWorkspaceUid,
            tstamp: Value::int($row['tstamp'] ?? null),
            latestChangeAt: $latestChange['tstamp'],
            latestChangeUserUid: $latestChange['userUid'],
            latestChangeUser: $latestChange['user'],
        ))->withPublishMetadata();
    }

    private function hasEditorVisibleWorkspaceChange(string $table, int $workspaceUid): bool
    {
        if ($workspaceUid <= 0) {
            return false;
        }

        $versionRow = BackendUtility::getRecord($table, $workspaceUid);
        if (!is_array($versionRow)) {
            return true;
        }

        return $this->recordDiffService->hasEditorVisibleChanges($table, Value::stringKeyArray($versionRow));
    }

    /**
     * @return array<int, string>
     */
    public function resolveColumnLabels(int $pageUid): array
    {
        return $this->labelResolver->resolveColumnLabels($pageUid);
    }
}
