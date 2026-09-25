<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\WebconEasyWorkspace\Service\LanguageContext;

/**
 * The languages behind the toolbar list: the site's languages of a page,
 * and the languages the editor's module shows (core's module data).
 */
final class LanguageContextTest extends FunctionalTestCase
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
        $this->writeSite([
            ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'flag' => 'us'],
            ['languageId' => 2, 'title' => 'Magyar', 'locale' => 'hu_HU.UTF-8', 'base' => '/hu/', 'flag' => 'hu'],
            ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_AT.UTF-8', 'base' => '/de/', 'flag' => 'de'],
        ]);
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
    public function theSiteLanguagesOfAPageComeInLanguageOrderWithTitleAndFlag(): void
    {
        $this->setUpBackendUser(1);

        $languages = $this->get(LanguageContext::class)->languagesForPage(2);

        self::assertSame([
            0 => ['uid' => 0, 'title' => 'English', 'flag' => 'flags-us'],
            1 => ['uid' => 1, 'title' => 'Deutsch', 'flag' => 'flags-de'],
            2 => ['uid' => 2, 'title' => 'Magyar', 'flag' => 'flags-hu'],
        ], $languages);
        self::assertSame([], $this->get(LanguageContext::class)->languagesForPage(0));
        self::assertSame([], $this->get(LanguageContext::class)->languagesForPage(999999));
    }

    #[Test]
    public function theViewLanguagesAreTheModulesSelectedLanguagesFromCoresModuleData(): void
    {
        $backendUser = $this->setUpBackendUser(1);
        $subject = $this->get(LanguageContext::class);

        // Nothing stored yet: the page module shows the default language.
        self::assertSame([0], $subject->viewLanguages('web_layout'));

        $backendUser->pushModuleData('web_layout', ['languages' => [2, 0], 'viewMode' => 2], true);
        self::assertSame([2, 0], $subject->viewLanguages('web_layout'));

        $backendUser->pushModuleData('web_edit', ['languages' => ['1'], 'viewMode' => 1], true);
        self::assertSame([1], $subject->viewLanguages('web_edit'));

        // The old single `language` key of the page module.
        $backendUser->pushModuleData('web_layout', ['language' => 1], true);
        self::assertSame([1], $subject->viewLanguages('web_layout'));

        // Modules without a language, and no module at all.
        self::assertNull($subject->viewLanguages('web_list'));
        self::assertNull($subject->viewLanguages(''));
    }
}
