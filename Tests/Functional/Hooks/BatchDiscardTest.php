<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Hooks;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Hooks\DiscardDependencyCommandHook;

/**
 * When one DataHandler run discards records together with records that
 * depend on them, TYPO3 v14's workspaces CommandMap rebuilds the batch and
 * gives each record an empty `version` command next to its `discard`. The
 * workspaces hook then reads a missing `action`, and the Development context
 * turns that warning into an exception. These tests feed DataHandler that
 * exact shape, with warnings escalated as the Development context does.
 */
final class BatchDiscardTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../Service/Fixtures/PageInlineScenario.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        $backendUser = $this->setUpBackendUser(1);
        $backendUser->setWorkspace(1);
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
    }

    #[Test]
    public function theDiscardRunsDespiteTheEmptyVersionCommand(): void
    {
        $dataHandler = $this->discard(['tt_content' => [12 => ['version' => [], 'discard' => true]]]);

        self::assertSame([], $dataHandler->errorLog, implode(' / ', $dataHandler->errorLog));
        self::assertFalse($this->versionExists(12), 'the draft of "Edited text" is discarded');
    }

    /**
     * Pins the Core behaviour the hook works around. When this starts to
     * fail, Core handles the rebuilt map itself and the hook can be removed.
     */
    #[Test]
    public function withoutTheHookCoreFailsOnTheEmptyVersionCommand(): void
    {
        $hooks = &$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'];
        self::assertSame(DiscardDependencyCommandHook::class, $hooks['webcon_easy_workspace_discard'] ?? null);
        unset($hooks['webcon_easy_workspace_discard']);

        $this->expectException(\ErrorException::class);
        $this->expectExceptionMessage('Undefined array key "action"');
        $this->discard(['tt_content' => [12 => ['version' => [], 'discard' => true]]]);
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $commandMap
     */
    private function discard(array $commandMap): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $commandMap);
        // What the Development context does with a warning: an exception.
        set_error_handler(static function (int $level, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $level, $file, $line);
        }, E_WARNING);
        try {
            $dataHandler->process_cmdmap();
        } finally {
            restore_error_handler();
        }

        return $dataHandler;
    }

    private function versionExists(int $uid): bool
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder->count('uid')->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $uid), $queryBuilder->expr()->eq('deleted', 0))
            ->executeQuery()->fetchOne() > 0;
    }
}
