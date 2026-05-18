<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

final readonly class LocalizationService
{
    private const FILE = 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:';

    private const JS_LABEL_KEYS = [
        'discardTag.title',
        'discardTag.subtitle',
        'toolbar.title',
        'toolbar.activeWorkspace',
        'toolbar.filter.aria',
        'toolbar.tab.changed',
        'toolbar.tab.all',
        'toolbar.records',
        'toolbar.loading',
        'toolbar.noContext',
        'toolbar.empty.all',
        'toolbar.empty.changed',
        'toolbar.loadError',
        'toolbar.column',
        'toolbar.hidden.title',
        'toolbar.hidden',
        'toolbar.allSelected',
        'toolbar.someSelected',
        'toolbar.selectAll',
        'toolbar.deselectAll',
        'toolbar.selectAllChanges',
        'toolbar.deselectAllChanges',
        'toolbar.selectedOf',
        'toolbar.of',
        'toolbar.publishing',
        'toolbar.publishToLive',
        'toolbar.badge.pending',
        'toolbar.context.recordsPending',
        'toolbar.context.pending',
        'toolbar.context.none',
        'context.page',
        'context.news',
        'record.pageRecord',
        'record.newsRecord',
        'state.live',
        'state.new',
        'state.delete',
        'state.move',
        'state.modified',
        'table.pages',
        'table.tt_content',
        'table.tx_news_domain_model_news',
        'table.sys_file_metadata',
        'table.tt_address',
        'diff.viewHistory',
        'diff.title.newWithChanges',
        'diff.title.newDetails',
        'diff.title.removal',
        'diff.title.move',
        'diff.title.changed',
        'diff.title.history',
        'diff.modal.historyTitle',
        'diff.modal.editTitle',
        'diff.noTitle',
        'discard.button.title',
        'discard.button.disabledTitle',
        'discard.button.aria',
        'discard.button.disabledAria',
        'discard.modal.title',
        'discard.modal.message',
        'discard.modal.cancel',
        'discard.modal.confirm',
        'discard.success.title',
        'discard.success.message',
        'discard.error.title',
        'discard.error.failedTitle',
        'preview.show.title',
        'preview.noIframe.visualEditor',
        'preview.noIframe.viewpage',
        'preview.noIframe.install',
        'preview.loginHint',
        'preview.notFound',
        'preview.button.title',
        'preview.button.copying',
        'preview.button.copied',
        'preview.button.preview',
        'preview.link.title',
        'preview.link.noUrl',
        'preview.link.copied',
        'edit.title',
        'edit.tooltip.part',
        'edit.tooltip.visualEditor',
        'edit.tooltip.preview',
        'edit.noForm',
        'edit.modalTitle',
        'edit.saved.title',
        'edit.saved.messageWithTitle',
        'edit.saved.message',
        'latest.title',
        'latest.openHint',
        'latest.loading',
        'latest.loadError',
        'latest.empty',
        'latest.noDiff',
        'latest.diff.empty',
        'latest.diff.added',
        'latest.diff.removed',
        'rollback.failedTitle',
        'rollback.missingData',
        'rollback.noField',
        'rollback.confirmField',
        'rollback.confirmLinear',
        'rollback.successTitle',
        'rollback.successField',
        'rollback.successLinear',
        'rollback.errorTitle',
        'publish.success.title',
        'publish.success.message',
        'publish.warning.title',
        'publish.failedTitle',
        'error.unknown',
        'error.unexpected',
    ];

    public function __construct(
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    public function translate(string $key, array $arguments = []): string
    {
        $languageService = $this->getLanguageService();
        $label = $languageService->label(self::FILE . $key, $arguments);
        return is_string($label) && $label !== '' ? $label : $key;
    }

    /**
     * @return array<string, string>
     */
    public function labelsForJavaScript(): array
    {
        $labels = [];
        foreach (self::JS_LABEL_KEYS as $key) {
            $labels[$key] = $this->translate($key);
        }
        return $labels;
    }

    private function getLanguageService(): LanguageService
    {
        if (($GLOBALS['LANG'] ?? null) instanceof LanguageService) {
            return $GLOBALS['LANG'];
        }
        $backendUser = ($GLOBALS['BE_USER'] ?? null) instanceof AbstractUserAuthentication ? $GLOBALS['BE_USER'] : null;
        return $this->languageServiceFactory->createFromUserPreferences($backendUser);
    }
}
