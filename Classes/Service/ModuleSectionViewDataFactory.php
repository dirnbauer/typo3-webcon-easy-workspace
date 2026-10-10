<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use Webconsulting\WebconEasyWorkspace\Enum\ModuleSection;
use Webconsulting\WebconEasyWorkspace\Enum\PendingItemsMode;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * Builds per-section view data for the Easy Workspace backend module:
 * diagnostics scan output or pending/all record lists with statistics.
 */
final readonly class ModuleSectionViewDataFactory
{
    public function __construct(
        private PendingItemsService $pendingItemsService,
        private WorkspaceDiagnosticsService $workspaceDiagnosticsService,
        private WorkspaceTestingReportService $workspaceTestingReportService,
        private LanguageContext $languageContext,
    ) {}

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function build(ModuleSection $section, int $pageUid, int $newsUid, array $config, int $activeWorkspaceId): array
    {
        if ($section === ModuleSection::Diagnostics) {
            $diagnostics = $this->workspaceDiagnosticsService->scan($activeWorkspaceId);
            $diagnostics['testing'] = $this->workspaceTestingReportService->buildFromScan($diagnostics);

            return $diagnostics;
        }

        $payload = $this->emptyListPayload();
        $items = $this->pendingItemsService->listForContext(
            $pageUid,
            $newsUid,
            PendingItemsMode::All,
            $config,
        );
        if ($items === null) {
            return $payload;
        }

        $payload['workspaceId'] = $items['workspaceId'] ?? 0;
        $payload['workspaceTitle'] = $items['workspaceTitle'] ?? '';
        $rawItemList = $items['items'] ?? [];
        if (!is_array($rawItemList)) {
            return $payload;
        }
        /** @var list<array<string, mixed>> $itemList */
        $itemList = array_values(array_filter($rawItemList, is_array(...)));
        $languages = $this->languages($pageUid, $newsUid);
        $itemList = array_map(fn(array $item): array => $this->withCompanionText($item, $languages), $itemList);
        $payload['items'] = $itemList;
        $payload['itemGroups'] = $this->groupsWithCompanionText($items['itemGroups'] ?? [], $languages);
        $payload['changedItemGroups'] = $this->groupsWithCompanionText($items['changedItemGroups'] ?? [], $languages);
        $payload['totalCount'] = count($itemList);
        $payload['contentElementCount'] = count(array_filter($itemList, static fn(array $i): bool => ($i['table'] ?? '') === 'tt_content'));
        $payload['affectedTableCount'] = count($this->extractAffectedTables($itemList));
        // Changes only: translation copies travel with their change, and
        // unchanged copies are not something to publish.
        $payload['changedCount'] = count(array_filter($itemList, static fn(array $i): bool => ($i['role'] ?? null) === 'change'));
        $payload['copyCount'] = array_sum(array_map(
            static fn(array $i): int => ($i['role'] ?? null) === 'copy' ? 1 + count(is_array($i['companions'] ?? null) ? $i['companions'] : []) : 0,
            $itemList,
        ));
        $latestChange = $this->extractLatestChangedSummary($itemList);
        $payload['lastChangedAt'] = $latestChange['tstamp'];
        $payload['lastChangedAtFormatted'] = $payload['lastChangedAt'] > 0 ? BackendUtility::datetime($payload['lastChangedAt']) : '';
        $payload['lastChangedByUid'] = $latestChange['userUid'];
        $payload['lastChangedByName'] = $latestChange['user'];

        return $payload;
    }

    /**
     * The languages of the site the list belongs to: the page's, or that of
     * the folder a news article is stored in.
     *
     * @return array<int, array{uid: int, title: string, flag: string, code: string, hreflang: string}>
     */
    private function languages(int $pageUid, int $newsUid): array
    {
        $sitePageUid = $pageUid;
        if ($sitePageUid <= 0 && $newsUid > 0) {
            $news = BackendUtility::getRecord('tx_news_domain_model_news', $newsUid, 'pid');
            $sitePageUid = is_array($news) ? Value::int($news['pid'] ?? null) : 0;
        }

        return $this->languageContext->languagesForPage($sitePageUid);
    }

    /**
     * The line below a change's title naming its translation copies:
     * `companionText` lists their languages ("DE, ZH, HU"), `companionLabelKey`
     * says how they follow the change (by the first one), `companionFields`
     * the synchronized fields a synced copy carries.
     *
     * The discard confirmation names the change's dependents, the other
     * translations core's discard removes along: `dependentText` the new
     * ones, `dependentEditText` those with edits of their own (language
     * titles, "German, Hungarian").
     *
     * @param array<string, mixed> $item
     * @param array<int, array{uid: int, title: string, flag: string, code: string, hreflang: string}> $languages
     * @return array<string, mixed>
     */
    private function withCompanionText(array $item, array $languages): array
    {
        $companions = array_values(array_filter(is_array($item['companions'] ?? null) ? $item['companions'] : [], is_array(...)));
        $codes = [];
        $fields = [];
        foreach ($companions as $companion) {
            $languageUid = Value::int($companion['languageUid'] ?? null);
            $codes[] = $languages[$languageUid]['code'] ?? (string)$languageUid;
            foreach (is_array($companion['fields'] ?? null) ? $companion['fields'] : [] as $field) {
                $fields[] = Value::string($field);
            }
        }
        $dependentTitles = ['new' => [], 'edited' => []];
        foreach (is_array($item['dependents'] ?? null) ? $item['dependents'] : [] as $dependent) {
            if (!is_array($dependent)) {
                continue;
            }
            $languageUid = Value::int($dependent['languageUid'] ?? null);
            $title = $languages[$languageUid]['title'] ?? (string)$languageUid;
            $dependentTitles[($dependent['edited'] ?? false) === true ? 'edited' : 'new'][$title] = $title;
        }

        return $item + [
            'companionText' => implode(', ', $codes),
            'companionLabelKey' => $companions !== [] ? 'toolbar.companions.' . Value::string($companions[0]['kind'] ?? 'identical') : '',
            'companionFields' => implode(', ', array_unique($fields)),
            'dependentText' => implode(', ', $dependentTitles['new']),
            'dependentEditText' => implode(', ', $dependentTitles['edited']),
        ];
    }

    /**
     * @param array<int, array{uid: int, title: string, flag: string, code: string, hreflang: string}> $languages
     * @return list<array<string, mixed>>
     */
    private function groupsWithCompanionText(mixed $groups, array $languages): array
    {
        $out = [];
        foreach (is_array($groups) ? $groups : [] as $group) {
            if (!is_array($group)) {
                continue;
            }
            $group = Value::stringKeyArray($group);
            $groupItems = array_values(array_filter(is_array($group['items'] ?? null) ? $group['items'] : [], is_array(...)));
            $group['items'] = array_map(fn(array $item): array => $this->withCompanionText(Value::stringKeyArray($item), $languages), $groupItems);
            $out[] = $group;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyListPayload(): array
    {
        return [
            'changedCount' => 0,
            'copyCount' => 0,
            'totalCount' => 0,
            'workspaceTitle' => '',
            'workspaceId' => 0,
            'contentElementCount' => 0,
            'affectedTableCount' => 0,
            'lastChangedAt' => 0,
            'lastChangedAtFormatted' => '',
            'lastChangedByUid' => 0,
            'lastChangedByName' => '',
            'items' => [],
            'itemGroups' => [],
            'changedItemGroups' => [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, true>
     */
    private function extractAffectedTables(array $items, bool $assumeChanged = false): array
    {
        $tables = [];
        foreach ($items as $item) {
            if ($assumeChanged || (bool)($item['isChanged'] ?? false)) {
                $table = Value::string($item['table'] ?? null);
                if ($table !== '') {
                    $tables[$table] = true;
                }
            }
            $childChanges = $item['childChanges'] ?? [];
            if (is_array($childChanges)) {
                $children = [];
                foreach ($childChanges as $childChange) {
                    if (is_array($childChange)) {
                        $children[] = Value::stringKeyArray($childChange);
                    }
                }
                foreach ($this->extractAffectedTables($children, true) as $table => $selected) {
                    $tables[$table] = $selected;
                }
            }
        }
        ksort($tables);

        return $tables;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{tstamp: int, userUid: int, user: string}
     */
    private function extractLatestChangedSummary(array $items): array
    {
        $latest = ['tstamp' => 0, 'userUid' => 0, 'user' => ''];
        foreach ($items as $item) {
            if ((bool)($item['isChanged'] ?? false)) {
                $latest = $this->newerChangeSummary($latest, [
                    'tstamp' => Value::int($item['latestChangeAt'] ?? null) ?: Value::int($item['tstamp'] ?? null),
                    'userUid' => Value::int($item['latestChangeUserUid'] ?? null),
                    'user' => Value::string($item['latestChangeUser'] ?? null),
                ]);
            }
            $childChanges = $item['childChanges'] ?? [];
            if (is_array($childChanges)) {
                $children = [];
                foreach ($childChanges as $childChange) {
                    if (is_array($childChange)) {
                        $children[] = Value::stringKeyArray($childChange);
                    }
                }
                $latest = $this->newerChangeSummary($latest, $this->extractLatestChangedSummary($children));
            }
        }

        return $latest;
    }

    /**
     * @param array{tstamp: int, userUid: int, user: string} $current
     * @param array{tstamp: int, userUid: int, user: string} $candidate
     * @return array{tstamp: int, userUid: int, user: string}
     */
    private function newerChangeSummary(array $current, array $candidate): array
    {
        if ($candidate['tstamp'] > $current['tstamp']) {
            return $candidate;
        }
        if ($candidate['tstamp'] === $current['tstamp'] && $current['userUid'] <= 0 && $candidate['userUid'] > 0) {
            return $candidate;
        }

        return $current;
    }
}
