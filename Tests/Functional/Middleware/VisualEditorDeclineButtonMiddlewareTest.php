<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Middleware;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Middleware\VisualEditorDeclineButtonMiddleware;

/**
 * The decline button loads for a backend user in a workspace who edits in the
 * Visual Editor. The request is built the way TYPO3's frontend builds it: the
 * backend user is in $GLOBALS['BE_USER'] and on no request attribute.
 */
final class VisualEditorDeclineButtonMiddlewareTest extends FunctionalTestCase
{
    private const string MODULE = '@webconsulting/webcon-easy-workspace/visual-editor-decline-button.js';

    protected array $coreExtensionsToLoad = ['workspaces'];

    protected array $testExtensionsToLoad = [
        'webconsulting/webcon-easy-workspace',
        // The shared scenario (editor, workspace 4) also holds news records.
        __DIR__ . '/../Fixtures/Extensions/news_stub',
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Service/Fixtures/ChangeCounterScenario.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    #[Test]
    public function loadsForAnEditorInAWorkspace(): void
    {
        $this->editorInWorkspace(4);

        self::assertContains(self::MODULE, $this->process(['editMode' => '1'])->getJavaScriptModules());
    }

    #[Test]
    public function staysOffInTheLiveWorkspace(): void
    {
        $this->editorInWorkspace(0);

        self::assertNotContains(self::MODULE, $this->process(['editMode' => '1'])->getJavaScriptModules());
    }

    #[Test]
    public function staysOffOutsideTheEditMode(): void
    {
        $this->editorInWorkspace(4);

        self::assertNotContains(self::MODULE, $this->process([])->getJavaScriptModules());
    }

    #[Test]
    public function staysOffWithoutABackendUser(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertNotContains(self::MODULE, $this->process(['editMode' => '1'])->getJavaScriptModules());
    }

    private function editorInWorkspace(int $workspaceId): void
    {
        $backendUser = $this->setUpBackendUser(1);
        $backendUser->setWorkspace($workspaceId);
        $GLOBALS['BE_USER'] = $backendUser;
    }

    /**
     * @param array<string, string> $query
     */
    private function process(array $query): AssetCollector
    {
        $collector = $this->get(AssetCollector::class);
        $request = new ServerRequest('https://typo3-testing.local/page')->withQueryParams($query);
        $this->get(VisualEditorDeclineButtonMiddleware::class)->process($request, new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        });

        return $collector;
    }
}
