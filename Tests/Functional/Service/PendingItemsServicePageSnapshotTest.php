<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Enum\PendingItemsMode;
use Webconsulting\WebconEasyWorkspace\Service\PendingItemsService;

/**
 * Pins the complete page collection — list, groups, counts and probes — for
 * a page carrying every kind of pending change: edits, drafts without an
 * editor-visible difference, new/delete/move placeholders, a translation, a
 * hidden element, discarded drafts, Content Blocks-style collection children
 * (edited, new, and new in a foreign_match_fields column) and file
 * references, plus changes on other pages and in another workspace.
 *
 * The snapshot was recorded with 1.7.2, before the collection was rebuilt
 * around batched queries, so it proves the rewrite returns exactly what the
 * per-row implementation returned. Record a new one only for an intended
 * behaviour change: EASYWS_UPDATE_SNAPSHOT=1 vendor/bin/phpunit …
 */
final class PendingItemsServicePageSnapshotTest extends FunctionalTestCase
{
    private const string SNAPSHOT = __DIR__ . '/Fixtures/PageInlineScenario.snapshot.json';

    /**
     * URLs carry per-session route tokens; everything else is deterministic.
     */
    private const array VOLATILE_KEYS = ['editUrl', 'contextualEditUrl', 'historyUrl'];

    protected array $coreExtensionsToLoad = ['workspaces'];

    protected array $testExtensionsToLoad = [
        'webconsulting/webcon-easy-workspace',
        __DIR__ . '/../Fixtures/Extensions/inline_stub',
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/PageInlineScenario.csv');
        $backendUser = $this->setUpBackendUser(1);
        $backendUser->setWorkspace(1);
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    #[Test]
    public function pageCollectionMatchesTheRecordedSnapshot(): void
    {
        $subject = $this->get(PendingItemsService::class);
        $actual = [];
        foreach ([2, 3, 4, 999] as $pageUid) {
            foreach ([PendingItemsMode::Changed, PendingItemsMode::All] as $mode) {
                $actual['page:' . $pageUid . ':' . $mode->value] = $subject->payloadForPage($pageUid, $mode)->toPageClientArray();
            }
            $actual['page:' . $pageUid . ':count'] = $subject->countChangesForContext($pageUid, 0, []);
            $actual['page:' . $pageUid . ':hasChanges'] = $subject->hasChangesForPage($pageUid);
            $actual['page:' . $pageUid . ':language1'] = $subject->payloadForPage($pageUid, PendingItemsMode::Changed, [], 1)->toPageClientArray();
        }
        // TSconfig knobs that change what is listed.
        $actual['page:2:hiddenExcluded'] = $subject->payloadForPage(2, PendingItemsMode::Changed, ['showHidden' => false])->toPageClientArray();
        $actual['page:2:maxItems3'] = $subject->payloadForPage(2, PendingItemsMode::Changed, ['maxItems' => 3])->toPageClientArray();
        $actual['page:2:countHiddenExcluded'] = $subject->countChangesForContext(2, 0, ['showHidden' => false]);

        $actual = self::normalize($actual);

        if (getenv('EASYWS_UPDATE_SNAPSHOT') === '1') {
            file_put_contents(self::SNAPSHOT, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            self::markTestSkipped('Snapshot recorded.');
        }

        $expected = json_decode((string)file_get_contents(self::SNAPSHOT), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected, $actual);
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            // Groups repeat the items; their identity and order is what counts.
            if (($key === 'itemGroups' || $key === 'changedItemGroups') && is_array($item)) {
                $out[$key] = array_map(
                    static fn(array $group): array => [
                        'key' => $group['key'] ?? null,
                        'label' => $group['label'] ?? null,
                        'items' => array_map(
                            static fn(array $groupItem): string => ($groupItem['table'] ?? '') . ':' . ($groupItem['liveUid'] ?? '') . '/' . ($groupItem['workspaceUid'] ?? ''),
                            is_array($group['items'] ?? null) ? $group['items'] : [],
                        ),
                    ],
                    $item,
                );
                continue;
            }
            if (is_string($key) && in_array($key, self::VOLATILE_KEYS, true)) {
                $out[$key] = $item === null ? null : '<url>';
                continue;
            }
            $out[$key] = self::normalize($item);
        }
        return $out;
    }
}
