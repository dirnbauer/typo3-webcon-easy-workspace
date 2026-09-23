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
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Controller\Backend\EasyWorkspaceAjaxController;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

final class EasyWorkspaceAjaxControllerBadgeTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces'];

    protected array $testExtensionsToLoad = ['webconsulting/webcon-easy-workspace'];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Service/Fixtures/PublishScenario.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    private function backendUserInWorkspace(int $userUid, int $workspaceId): BackendUserAuthentication
    {
        $backendUser = $this->setUpBackendUser($userUid);
        $backendUser->setWorkspace($workspaceId);
        // Core's BackendUserAuthenticator middleware does this for a real request.
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect($workspaceId));

        return $backendUser;
    }

    /**
     * @param array<string, string|int> $query
     */
    private function request(BackendUserAuthentication $backendUser, string $method = 'GET', array $query = [], string $jsonBody = ''): ServerRequest
    {
        $request = new ServerRequest('https://typo3-testing.local/typo3/ajax/webcon-easy-workspace', $method)
            ->withAttribute('backend.user', $backendUser)
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
    private function json(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode());

        return Value::stringKeyArray(json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function badgeReportsBothTheWorkspaceAndThePageCountWithAStableShape(): void
    {
        $backendUser = $this->backendUserInWorkspace(1, 1);
        $subject = $this->get(EasyWorkspaceAjaxController::class);

        $payload = $this->json($subject->badgeAction($this->request($backendUser, query: ['pageUid' => 1])));

        self::assertSame(
            ['context', 'workspaceId', 'workspaceTitle', 'contextCount', 'changedCount', 'byTable', 'byState', 'latestChangeAt', 'stamp'],
            array_keys($payload),
        );
        self::assertSame('page', $payload['context']);
        self::assertSame(1, $payload['workspaceId']);
        self::assertSame('Workspace One', $payload['workspaceTitle']);
        self::assertSame(1, $payload['contextCount']);
        self::assertSame(1, $payload['changedCount']);
        self::assertSame(['tt_content' => 1], $payload['byTable']);
        self::assertSame(['new' => 0, 'changed' => 1, 'deleted' => 0, 'moved' => 0], $payload['byState']);
        self::assertIsString($payload['stamp']);
        self::assertSame(40, strlen($payload['stamp']));

        // The workspace total stays context-free; only contextCount drops out
        // when the client reports no page, so the badge can fall back to it.
        $contextFree = $this->json($subject->badgeAction($this->request($backendUser)));
        self::assertSame('none', $contextFree['context']);
        self::assertNull($contextFree['contextCount']);
        self::assertSame(1, $contextFree['changedCount']);
        self::assertSame($payload['stamp'], $contextFree['stamp']);
    }

    #[Test]
    public function contextCountIgnoresChangesOnOtherPages(): void
    {
        $backendUser = $this->backendUserInWorkspace(1, 1);
        $subject = $this->get(EasyWorkspaceAjaxController::class);

        // Page 999 does not exist, so nothing of the workspace belongs to it.
        $payload = $this->json($subject->badgeAction($this->request($backendUser, query: ['pageUid' => 999])));

        self::assertSame(0, $payload['contextCount']);
        self::assertSame(1, $payload['changedCount']);
    }

    #[Test]
    public function hasChangesMirrorsTheBadgePayload(): void
    {
        $backendUser = $this->backendUserInWorkspace(1, 1);
        $subject = $this->get(EasyWorkspaceAjaxController::class);

        $payload = $this->json($subject->hasChangesAction($this->request($backendUser, query: ['pageUid' => 1])));

        self::assertTrue($payload['hasChanges']);
        self::assertSame(1, $payload['changedCount']);
        self::assertArrayHasKey('stamp', $payload);
    }

    #[Test]
    public function badgeIsZeroInLive(): void
    {
        $backendUser = $this->setUpBackendUser(1);
        $subject = $this->get(EasyWorkspaceAjaxController::class);

        $payload = $this->json($subject->badgeAction($this->request($backendUser)));

        self::assertSame(0, $payload['workspaceId']);
        self::assertSame(0, $payload['changedCount']);
    }

    #[Test]
    public function publishResponseCarriesTheDecrementedCount(): void
    {
        $backendUser = $this->backendUserInWorkspace(1, 1);
        $subject = $this->get(EasyWorkspaceAjaxController::class);
        $before = $this->json($subject->badgeAction($this->request($backendUser)));
        self::assertSame(1, $before['changedCount']);

        $result = $this->json($subject->publishAction($this->request(
            $backendUser,
            'POST',
            jsonBody: '{"selections":[{"table":"tt_content","workspaceUid":2}]}',
        )));

        self::assertTrue($result['success'], implode(' / ', Value::stringList($result['errors'] ?? [])));
        self::assertSame(1, $result['published']);
        $badge = Value::stringKeyArray($result['badge'] ?? null);
        self::assertSame(0, $badge['changedCount']);
        self::assertSame(1, $badge['workspaceId']);
        self::assertNotSame($before['stamp'], $badge['stamp']);
    }

    #[Test]
    public function discardResponseCarriesTheFreshCount(): void
    {
        $backendUser = $this->backendUserInWorkspace(1, 1);
        $subject = $this->get(EasyWorkspaceAjaxController::class);

        $result = $this->json($subject->discardAction($this->request(
            $backendUser,
            'POST',
            jsonBody: '{"table":"tt_content","workspaceUid":2}',
        )));

        self::assertTrue($result['success']);
        self::assertSame(0, Value::stringKeyArray($result['badge'] ?? null)['changedCount']);
    }
}
