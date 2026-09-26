<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Dto\WorkspaceChange;
use Webconsulting\WebconEasyWorkspace\Service\PendingItems\CoreWorkspaceChanges;

/**
 * A version that matches its live record — one TYPO3 made along with an
 * edit elsewhere — is listed, but not as a content change.
 */
final class CoreWorkspaceChangesContentChangeTest extends FunctionalTestCase
{
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
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        $backendUser = $this->setUpBackendUser(1);
        $backendUser->setWorkspace(1);
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
    }

    #[Test]
    public function aVersionMatchingItsLiveRecordIsNoContentChange(): void
    {
        $flags = [];
        foreach ($this->get(CoreWorkspaceChanges::class)->rows(1) as $row) {
            $flags[$row->table . ':' . $row->workspaceUid] = $row->contentChanged;
        }

        // "Touched but unchanged": a version identical to live.
        self::assertFalse($flags['tt_content:14']);
        // Edited, new, deleted and moved records are changes.
        self::assertTrue($flags['tt_content:12']);
        self::assertTrue($flags['tt_content:15']);
        self::assertTrue($flags['tt_content:17']);
        self::assertTrue($flags['tt_content:24']);
        // Collection items and file references are compared too.
        self::assertTrue($flags['tx_easyws_item:31']);
        self::assertTrue($flags['sys_file_reference:71']);
    }

    #[Test]
    public function theFlagSurvivesTheCache(): void
    {
        $changes = $this->get(CoreWorkspaceChanges::class);
        $changes->rows(1);
        $cached = array_values(array_filter(
            $changes->rows(1),
            static fn(WorkspaceChange $row): bool => $row->table === 'tt_content' && $row->workspaceUid === 14,
        ));

        self::assertCount(1, $cached);
        self::assertFalse($cached[0]->contentChanged);
    }
}
