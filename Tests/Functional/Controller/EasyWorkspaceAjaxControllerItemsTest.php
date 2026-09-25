<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Controller\Backend\EasyWorkspaceAjaxController;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * The dropdown's list: the items once, each with its language, the site's
 * languages and the languages the client's module shows.
 */
final class EasyWorkspaceAjaxControllerItemsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces'];

    protected array $testExtensionsToLoad = [
        'webconsulting/webcon-easy-workspace',
        __DIR__ . '/../Fixtures/Extensions/inline_stub',
    ];

    private BackendUserAuthentication $backendUser;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Service/Fixtures/PageInlineScenario.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        $this->writeSite([
            ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'flag' => 'us'],
            ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_AT.UTF-8', 'base' => '/de/', 'flag' => 'de'],
        ]);
        $this->backendUser = $this->setUpBackendUser(1);
        $this->backendUser->setWorkspace(1);
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(1));
    }

    /**
     * @param array<string, string|int> $query
     * @return array<string, mixed>
     */
    private function items(array $query): array
    {
        $request = new ServerRequest('https://typo3-testing.local/typo3/ajax/webcon-easy-workspace/items', 'GET')
            ->withAttribute('backend.user', $this->backendUser)
            ->withQueryParams($query);
        $response = $this->get(EasyWorkspaceAjaxController::class)->itemsAction($request);
        self::assertSame(200, $response->getStatusCode());

        return Value::stringKeyArray(json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * A site with the given languages, written where core reads sites
     * from and made visible to the site finder.
     *
     * @param list<array<string, mixed>> $languages
     */
    private function writeSite(array $languages): void
    {
        $path = Environment::getConfigPath() . '/sites/inline';
        GeneralUtility::mkdir_deep($path);
        file_put_contents($path . '/config.yaml', Yaml::dump([
            'rootPageId' => 1,
            'base' => 'https://inline.test/',
            'languages' => $languages,
        ]));
        $this->get(CacheManager::class)->getCache('core')->remove('sites-configuration');
    }

    #[Test]
    public function theListCarriesEachRowsLanguageAndTheLanguagesOfTheSiteAndTheModule(): void
    {
        $this->backendUser->pushModuleData('web_layout', ['languages' => [1]], true);

        $payload = $this->items(['pageUid' => 2, 'module' => 'web_layout']);

        self::assertSame('page', $payload['context']);
        self::assertSame('web_layout', $payload['viewModule']);
        self::assertSame([1], $payload['viewLanguages']);
        self::assertSame(
            [['uid' => 0, 'title' => 'English', 'flag' => 'flags-us'], ['uid' => 1, 'title' => 'Deutsch', 'flag' => 'flags-de']],
            $payload['languages'],
        );
        // The items once — the module's groups stay with the module.
        self::assertArrayNotHasKey('itemGroups', $payload);
        self::assertArrayNotHasKey('changedItemGroups', $payload);

        $languages = [];
        foreach ((array)$payload['items'] as $item) {
            $item = Value::stringKeyArray($item);
            $languages[$item['table'] . ':' . $item['workspaceUid']] = $item['languageUid'];
        }
        self::assertSame(1, $languages['tt_content:26'], 'the translated element');
        self::assertSame(0, $languages['tt_content:12']);

        // Nothing the dropdown does not render.
        $first = Value::stringKeyArray($payload['items'][0]);
        self::assertArrayNotHasKey('changeRecords', $first);
        self::assertArrayNotHasKey('changeBadges', $first);
        self::assertArrayHasKey('publishRecords', $first);
        self::assertArrayHasKey('parent', $first);
    }

    #[Test]
    public function aModuleWithoutLanguagesReportsNone(): void
    {
        $payload = $this->items(['pageUid' => 2, 'module' => 'web_list']);

        self::assertNull($payload['viewLanguages']);
        self::assertSame('web_list', $payload['viewModule']);
        self::assertNotEmpty($payload['items']);

        $payload = $this->items(['pageUid' => 2]);
        self::assertNull($payload['viewLanguages']);
        self::assertSame('', $payload['viewModule']);
    }
}
