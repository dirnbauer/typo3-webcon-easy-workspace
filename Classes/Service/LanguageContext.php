<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use Webconsulting\WebconEasyWorkspace\Security\BackendAccessGuard;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * The languages behind the toolbar list: the site's languages of the page
 * the list is scoped to, and the languages the editor's current module
 * shows — so the list can tell the changes visible in that view from the
 * ones of other languages, which the page only shows after a language
 * switch.
 *
 * The page module (web_layout) and the Visual Editor (web_edit) keep their
 * selected languages in core's module data (`languages`, validated by
 * PageContextFactory); other modules show no particular language.
 */
final readonly class LanguageContext
{
    /**
     * Modules whose module data names the languages they show.
     */
    private const array LANGUAGE_MODULES = ['web_layout', 'web_edit'];

    public function __construct(
        private SiteFinder $siteFinder,
        private BackendAccessGuard $accessGuard,
    ) {}

    /**
     * The site's languages the editor may see, keyed by language id.
     *
     * `code` is the short tag the dropdown prints on its language chips
     * (`DE`); two languages of the site sharing a language code are told
     * apart by their full locale (`DE-AT`, `DE-CH`). `hreflang` goes into
     * the `lang` attribute of a translated title.
     *
     * @return array<int, array{uid: int, title: string, flag: string, code: string, hreflang: string}>
     */
    public function languagesForPage(int $pageUid): array
    {
        if ($pageUid <= 0) {
            return [];
        }
        try {
            $site = $this->siteFinder->getSiteByPageId($pageUid);
        } catch (SiteNotFoundException) {
            return [];
        }
        $siteLanguages = $site->getLanguages();
        $codeUses = [];
        foreach ($siteLanguages as $language) {
            $code = $language->getLocale()->getLanguageCode();
            $codeUses[$code] = ($codeUses[$code] ?? 0) + 1;
        }
        $user = $this->accessGuard->user();
        $languages = [];
        foreach ($siteLanguages as $language) {
            $languageId = $language->getLanguageId();
            if ($user !== null && !$user->checkLanguageAccess($languageId)) {
                continue;
            }
            $locale = $language->getLocale();
            $languages[$languageId] = [
                'uid' => $languageId,
                'title' => $language->getTitle(),
                'flag' => $language->getFlagIdentifier(),
                'code' => strtoupper(($codeUses[$locale->getLanguageCode()] ?? 0) > 1 ? $locale->getName() : $locale->getLanguageCode()),
                'hreflang' => $language->getHreflang(),
            ];
        }
        ksort($languages);

        return $languages;
    }

    /**
     * The languages the named backend module currently shows, from core's
     * module data. Null when the module shows no particular language (a
     * record list, the dashboard) — the list then makes no distinction.
     *
     * @return list<int>|null
     */
    public function viewLanguages(string $module): ?array
    {
        if (!in_array($module, self::LANGUAGE_MODULES, true)) {
            return null;
        }
        $user = $this->accessGuard->user();
        if (!$user instanceof BackendUserAuthentication) {
            return null;
        }
        $moduleData = Value::stringKeyArray($user->getModuleData($module));
        $languages = $moduleData['languages'] ?? null;
        if (!is_array($languages)) {
            // Before 14.3 the page module stored one `language`.
            $languages = isset($moduleData['language']) ? [$moduleData['language']] : [0];
        }
        $ids = [];
        foreach ($languages as $languageId) {
            $languageId = Value::int($languageId);
            if ($languageId >= 0) {
                $ids[$languageId] = $languageId;
            }
        }

        return array_values($ids) ?: [0];
    }
}
