<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Hooks;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Service\PublishSelectedService;
use Webconsulting\WebconEasyWorkspace\Tests\Functional\Service\TranslationCompanionScenario;

/**
 * Discarding a change's translation copies is one DataHandler run over
 * several records with records nested below them — the batch shape core's
 * CommandMap rebuilds with an empty `version` command per record (see
 * BatchDiscardTest). Measured on the lab: a collection element, its three
 * translations and their sixteen items. Warnings are escalated as the
 * Development context does.
 */
final class BatchDiscardCompanionsTest extends FunctionalTestCase
{
    use TranslationCompanionScenario;

    protected array $coreExtensionsToLoad = ['workspaces'];

    protected array $testExtensionsToLoad = [
        'webconsulting/webcon-easy-workspace',
        __DIR__ . '/../Fixtures/Extensions/inline_stub',
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCompanionScenario();
        // An item of the collection element changes; TYPO3 versions the
        // element, its translations and every item of each.
        $this->editInWorkspace(['tt_content' => [130 => ['header' => 'Collection']], 'tx_easyws_item' => [300 => ['header' => 'Item (edited)']]]);
    }

    #[Test]
    public function theOwnerItsCopiesAndTheirItemsAreDiscardedInOneRun(): void
    {
        $commandMap = [];
        foreach ([130, 131, 132, 133] as $liveUid) {
            $commandMap['tt_content'][$this->versionOf('tt_content', $liveUid)] = ['discard' => true];
        }
        self::assertCount(12, $this->workspaceRows(), '4 elements and their 8 items');

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $commandMap);
        set_error_handler(static function (int $level, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $level, $file, $line);
        }, E_WARNING);
        try {
            $dataHandler->process_cmdmap();
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $dataHandler->errorLog, implode(' / ', $dataHandler->errorLog));
        self::assertSame([], $this->workspaceRows());
    }

    #[Test]
    public function discardingTheChangeRunsTheCopiesBatchWithoutErrors(): void
    {
        $user = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $user);

        set_error_handler(static function (int $level, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $level, $file, $line);
        }, E_WARNING);
        try {
            $result = $this->get(PublishSelectedService::class)->discard('tt_content', $this->versionOf('tt_content', 130), $user);
        } finally {
            restore_error_handler();
        }

        self::assertTrue($result['success'], implode(' / ', $result['errors']));
        self::assertSame(3, $result['companionsDiscarded']);
        self::assertSame([], $result['warnings']);
        self::assertSame([], $this->workspaceRows());
    }
}
