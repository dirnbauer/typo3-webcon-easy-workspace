<?php

declare(strict_types=1);

namespace Webconsulting\WebconWorkspaceChatops\Service;

use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

final readonly class LocalizationService
{
    private const FILE = 'LLL:EXT:webcon_workspace_chatops/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function translate(string $key): string
    {
        $label = $this->languageService()->sL(self::FILE . $key);

        return $label !== '' ? $label : $key;
    }

    private function languageService(): LanguageService
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if ($languageService instanceof LanguageService) {
            return $languageService;
        }

        $backendUser = ($GLOBALS['BE_USER'] ?? null) instanceof AbstractUserAuthentication ? $GLOBALS['BE_USER'] : null;

        return $this->languageServiceFactory->createFromUserPreferences($backendUser);
    }
}
