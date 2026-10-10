<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Controller\Backend\EasyWorkspaceAjaxController;
use Webconsulting\WebconEasyWorkspace\Tests\Functional\Service\TranslationCompanionScenario;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * The dropdown's endpoints for the lab's case: one edit of a translated
 * element reads as one change everywhere, its translation copies ride on
 * its row, and unchanged copies are cleaned up through their own endpoint.
 */
final class EasyWorkspaceAjaxControllerCompanionTest extends FunctionalTestCase
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
    }

    private function actAs(int $userUid, int $workspaceId = 1): BackendUserAuthentication
    {
        $user = $this->setUpBackendUser($userUid);
        $user->setWorkspace($workspaceId);
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect($workspaceId));

        return $user;
    }

    /**
     * @param array<string, string|int> $query
     */
    private function request(string $method = 'GET', array $query = [], string $jsonBody = ''): ServerRequest
    {
        $user = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $user);
        $request = new ServerRequest('https://typo3-testing.local/typo3/ajax/webcon-easy-workspace', $method)
            ->withAttribute('backend.user', $user)
            ->withQueryParams($query);
        if ($jsonBody !== '') {
            $stream = new Stream('php://temp', 'rw');
            $stream->write($jsonBody);
            $stream->rewind();
            $request = $request->withHeader('Content-Type', 'application/json')->withBody($stream);
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(ResponseInterface $response, int $status = 200): array
    {
        self::assertSame($status, $response->getStatusCode(), (string)$response->getBody());

        return Value::stringKeyArray(json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    private function controller(): EasyWorkspaceAjaxController
    {
        return $this->get(EasyWorkspaceAjaxController::class);
    }

    #[Test]
    public function oneEditReadsAsOneChangeWithItsTranslationsOnTheRow(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);

        $payload = self::json($this->controller()->itemsAction($this->request(query: ['pageUid' => 2, 'module' => 'web_layout'])));

        self::assertSame(1, $payload['userUid']);
        $languages = is_array($payload['languages']) ? $payload['languages'] : [];
        self::assertSame(['EN', 'DE', 'ZH', 'HU'], array_column($languages, 'code'));
        self::assertSame(['en-US', 'de-AT', 'zh-CN', 'hu-HU'], array_column($languages, 'hreflang'));
        $items = is_array($payload['items']) ? $payload['items'] : [];
        self::assertCount(1, $items);
        $hero = Value::stringKeyArray($items[0]);
        self::assertSame('change', $hero['role']);
        self::assertSame('', $hero['copyGroup']);
        self::assertSame([], $hero['dependents']);
        $companions = is_array($hero['companions']) ? $hero['companions'] : [];
        self::assertSame([1, 2, 3], array_column($companions, 'languageUid'));
        self::assertSame(['identical', 'identical', 'identical'], array_column($companions, 'kind'));
        self::assertSame([101, 102, 103], array_column($companions, 'liveUid'));
        self::assertNotEmpty(Value::stringKeyArray($companions[0])['editUrl']);
        // Published with the row by the server, never sent by the client.
        self::assertSame(
            ['tt_content', 'sys_file_reference'],
            array_column(is_array($hero['publishRecords']) ? $hero['publishRecords'] : [], 'table'),
        );

        $badge = Value::stringKeyArray($payload['badge']);
        self::assertSame(1, $badge['contextCount']);
        self::assertSame(0, $badge['copies']);
        self::assertSame(1, $badge['changedCount']);
        self::assertSame(4, $badge['versionCount']);
        self::assertSame(0, $badge['copyCount']);
        self::assertSame([['table' => 'tt_content', 'liveUid' => 100, 'workspaceUid' => $this->versionOf('tt_content', 100)]], $badge['records']);
    }

    #[Test]
    public function aSynchronizedFieldIsNamedOnTheCopies(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['starttime' => 1767225600]]]);

        $payload = self::json($this->controller()->itemsAction($this->request(query: ['pageUid' => 2])));
        $hero = Value::stringKeyArray(is_array($payload['items']) ? $payload['items'][0] : null);
        $german = Value::stringKeyArray(is_array($hero['companions']) ? $hero['companions'][0] : null);

        self::assertSame('synced', $german['kind']);
        self::assertSame(['Publish Date'], $german['fields']);
    }

    #[Test]
    public function aNewElementNamesItsNewTranslationForTheDiscardConfirmation(): void
    {
        $this->editInWorkspace(['tt_content' => ['NEW1' => ['pid' => 2, 'CType' => 'text', 'header' => 'Brand new', 'colPos' => 0]]]);
        $new = 0;
        foreach ($this->workspaceRows() as $row) {
            $new = max($new, (int)explode(':', $row)[1]);
        }
        $this->editInWorkspace([], ['tt_content' => [$new => ['localize' => 1]]]);

        $payload = self::json($this->controller()->itemsAction($this->request(query: ['pageUid' => 2])));
        $items = [];
        foreach (is_array($payload['items']) ? $payload['items'] : [] as $item) {
            $item = Value::stringKeyArray($item);
            $items[Value::int($item['workspaceUid'])] = $item;
        }

        self::assertCount(2, $items, 'the translation is a change of its own');
        self::assertSame([['table' => 'tt_content', 'workspaceUid' => $new + 1, 'languageUid' => 1, 'edited' => false]], $items[$new]['dependents']);
    }

    #[Test]
    public function aMoveNamesTheTranslatorsEditCoreDiscardsWithIt(): void
    {
        $this->editInWorkspace([], ['tt_content' => [110 => ['move' => -120]]]);
        $this->editInWorkspace(['tt_content' => [111 => ['header' => 'Zweites (Übersetzer)']]]);
        $moved = $this->versionOf('tt_content', 110);
        $german = $this->versionOf('tt_content', 111);

        $payload = self::json($this->controller()->itemsAction($this->request(query: ['pageUid' => 2])));
        $items = [];
        foreach (is_array($payload['items']) ? $payload['items'] : [] as $item) {
            $item = Value::stringKeyArray($item);
            $items[Value::int($item['workspaceUid'])] = $item;
        }

        self::assertSame('change', $items[$german]['role'] ?? null, 'the German edit is a change of its own');
        self::assertSame([['table' => 'tt_content', 'workspaceUid' => $german, 'languageUid' => 1, 'edited' => true]], $items[$moved]['dependents'] ?? null);
        self::assertSame([2, 3], array_column(is_array($items[$moved]['companions'] ?? null) ? $items[$moved]['companions'] : [], 'languageUid'));

        $result = self::json($this->controller()->discardAction($this->request('POST', jsonBody: (string)json_encode([
            'table' => 'tt_content',
            'workspaceUid' => $moved,
            'pageUid' => 2,
        ]))));

        self::assertTrue($result['success']);
        self::assertSame(2, $result['companionsDiscarded']);
        self::assertSame(1, $result['dependentsDiscarded']);
        self::assertSame([], $this->workspaceRows());
    }

    #[Test]
    public function discardingTheRowReportsTheCopiesDiscardedWithIt(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);

        $result = self::json($this->controller()->discardAction($this->request('POST', jsonBody: (string)json_encode([
            'table' => 'tt_content',
            'workspaceUid' => $this->versionOf('tt_content', 100),
            'pageUid' => 2,
        ]))));

        self::assertTrue($result['success']);
        self::assertSame(1, $result['discarded']);
        self::assertSame(3, $result['companionsDiscarded']);
        self::assertSame(0, $result['dependentsDiscarded']);
        self::assertSame(0, Value::stringKeyArray($result['badge'])['contextCount']);
        self::assertSame([], $this->workspaceRows());
    }

    #[Test]
    public function unchangedCopiesAreCountedApartAndCleanedUp(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero']]]);
        $copy = $this->versionOf('tt_content', 100);

        $badge = self::json($this->controller()->badgeAction($this->request(query: ['pageUid' => 2])));
        self::assertSame(0, $badge['contextCount']);
        self::assertSame(4, $badge['copies']);
        self::assertSame(0, $badge['changedCount']);
        self::assertSame(4, $badge['copyCount']);
        self::assertSame([], $badge['records'], 'no decline button on an unchanged copy');

        $result = self::json($this->controller()->discardCopiesAction($this->request('POST', jsonBody: (string)json_encode([
            'pageUid' => 2,
            'records' => [['table' => 'tt_content', 'workspaceUid' => $copy], ['table' => 'tt_content', 'workspaceUid' => 999]],
        ]))));

        self::assertTrue($result['success']);
        self::assertSame(4, $result['discarded']);
        self::assertSame(1, $result['skipped'], 'the posted record that is no copy of the page');
        self::assertSame([], $result['errors']);
        self::assertSame(0, Value::stringKeyArray($result['badge'])['copies']);
        self::assertSame([], $this->workspaceRows());
    }

    #[Test]
    public function theCleanupLeavesChangesAlone(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero (edited)']]]);

        $result = self::json($this->controller()->discardCopiesAction($this->request('POST', jsonBody: (string)json_encode([
            'pageUid' => 2,
            'records' => [['table' => 'tt_content', 'workspaceUid' => $this->versionOf('tt_content', 100)]],
        ]))));

        self::assertSame(0, $result['discarded']);
        self::assertSame(1, $result['skipped']);
        self::assertCount(8, $this->workspaceRows());
    }

    #[Test]
    public function theCleanupChecksTheSwitchesTheAccessAndTheWorkspace(): void
    {
        $this->editInWorkspace(['tt_content' => [100 => ['header' => 'Hero']]]);
        $body = (string)json_encode(['pageUid' => 2]);

        // enableRevert = 0, enabled = 0, no access to the page, in Live.
        foreach ([[3, 1, 403], [4, 1, 403], [5, 1, 403], [1, 0, 400]] as [$userUid, $workspaceId, $status]) {
            $this->actAs($userUid, $workspaceId);
            $error = self::json($this->controller()->discardCopiesAction($this->request('POST', jsonBody: $body)), $status)['error'] ?? null;
            self::assertNotSame('', Value::string($error), 'user ' . $userUid);
        }

        self::assertCount(8, $this->workspaceRows(), 'nothing was discarded');
    }
}
