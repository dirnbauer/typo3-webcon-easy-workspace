<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

final readonly class LocalizationService
{
    private const string FILE = 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:';

    private const array JS_LABEL_KEYS = [
        'discardTag.title',
        'discardTag.subtitle',
        'discardTag.title.modified',
        'discardTag.subtitle.modified',
        'discardTag.title.new',
        'discardTag.subtitle.new',
        'discardTag.title.delete',
        'discardTag.subtitle.delete',
        'discardTag.title.move',
        'discardTag.subtitle.move',
        'toolbar.title',
        'toolbar.activeWorkspace',
        'toolbar.loading',
        'toolbar.noContext',
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
        'toolbar.selected',
        'toolbar.selectedOf',
        'toolbar.of',
        'toolbar.publishing',
        'toolbar.publishToLive',
        'toolbar.badge.pending',
        'toolbar.badge.pendingHere',
        'toolbar.dialog.aria',
        'toolbar.refresh',
        'toolbar.count.onPage',
        'toolbar.count.elsewhere',
        'toolbar.count.workspace',
        'toolbar.count.none',
        'toolbar.stage.mixed',
        'toolbar.empty.title',
        'toolbar.retry',
        'toolbar.openModule',
        'toolbar.publishCount',
        'toolbar.preview.open',
        'toolbar.preview.openTitle',
        'toolbar.preview.copyLink',
        'toolbar.preview.more',
        'toolbar.row.select',
        'toolbar.row.edit',
        'toolbar.row.diff',
        'toolbar.row.preview',
        'toolbar.group.files',
        'toolbar.keyboardHint',
        'toolbar.stage.label',
        'toolbar.summary.page',
        'toolbar.summary.news',
        'toolbar.summary.elsewhere',
        'toolbar.summary.otherLanguages',
        'toolbar.selection.count',
        'toolbar.languages.inView',
        'toolbar.languages.inViewMany',
        'toolbar.languages.other',
        'toolbar.languages.otherHint',
        'toolbar.languages.otherHintMany',
        'toolbar.languages.otherHintShort',
        'toolbar.languages.otherHintShortMany',
        'toolbar.languages.count',
        'toolbar.row.language',
        'toolbar.row.partOf',
        'toolbar.row.notInView',
        'toolbar.row.children',
        'toolbar.languages.translations',
        'toolbar.summary.nonePage',
        'toolbar.summary.noneNews',
        'toolbar.summary.copies',
        'toolbar.summary.drafts',
        'toolbar.companions.identical',
        'toolbar.companions.synced',
        'toolbar.companions.moved',
        'toolbar.companions.deleted',
        'toolbar.companions.help',
        'toolbar.companions.edit',
        'toolbar.companions.nowOwn',
        'toolbar.cleanup.title',
        'toolbar.cleanup.hint',
        'toolbar.cleanup.discard',
        'toolbar.cleanup.discarding',
        'toolbar.cleanup.success.title',
        'toolbar.cleanup.success.message',
        'toolbar.cleanup.skipped',
        'toolbar.cleanup.edit',
        'toolbar.section.select',
        'toolbar.row.byYou',
        'toolbar.row.by',
        'toolbar.selection.all',
        'toolbar.publish.noneSelected',
        'toolbar.empty.elsewhere',
        'toolbar.status.refreshed',
        'toolbar.stage.prefix',
        'toolbar.refreshing',
        // Layout "clarity": the dismissible explainer.
        'toolbar.explainer.title',
        'toolbar.explainer.text',
        'toolbar.explainer.dismiss',
        'toolbar.explainer.dismissTitle',
        // Layout "efficiency": shortcut hints and <kbd> titles.
        'toolbar.shortcut.publish',
        'toolbar.shortcut.discard',
        'toolbar.shortcut.move',
        'toolbar.shortcut.selectAll',
        'toolbar.shortcut.publishTitle',
        'toolbar.shortcut.discardTitle',
        'toolbar.kbd.ctrl',
        'toolbar.kbd.ctrlTitle',
        'toolbar.kbd.cmd',
        'toolbar.kbd.cmdTitle',
        'toolbar.kbd.enter',
        'toolbar.kbd.enterTitle',
        'toolbar.kbd.delete',
        'toolbar.kbd.deleteTitle',
        'toolbar.kbd.arrows',
        'toolbar.kbd.arrowsTitle',
        'toolbar.keyboardHint.efficiency',
        'toolbar.keyboardHint.efficiencyNoDiscard',
        // Layout "core": drafts wording, confirm before cleanup, per-copy discard.
        'toolbar.cleanup.titleDrafts',
        'toolbar.cleanup.draftsShort',
        'toolbar.cleanup.draftsHelp',
        'toolbar.cleanup.confirm.title',
        'toolbar.cleanup.confirm.message',
        'toolbar.cleanup.confirm.button',
        'toolbar.cleanup.discardOne',
        'toolbar.cleanup.editDraft',
        'toolbar.cleanup.successDrafts',
        'toolbar.cleanup.skippedDrafts',
        'change.new',
        'change.changed',
        'change.deleted',
        'change.moved',
        'time.now',
        'time.minutes',
        'time.hours',
        'time.days',
        // `module.*` labels are server-rendered by Fluid in the
        // backend module template; they no longer need to be pushed
        // into JS via `_config.labels`. Only labels still consumed
        // by the toolbar Lit element stay below.
        'context.page',
        'context.news',
        'record.pageRecord',
        'record.newsRecord',
        'toolbar.primaryIntent.page',
        'toolbar.primaryIntent.news',
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
        'discard.modal.message.modified',
        'discard.modal.message.new',
        'discard.modal.message.delete',
        'discard.modal.message.move',
        'discard.modal.companions.identical',
        'discard.modal.companions.synced',
        'discard.modal.companions.moved',
        'discard.modal.companions.deleted',
        'discard.modal.dependents',
        'discard.modal.dependentEdits',
        'discard.warning.title',
        'discard.modal.cancel',
        'discard.modal.confirm',
        'discard.success.title',
        'discard.success.message',
        'discard.success.message.modified',
        'discard.success.message.new',
        'discard.success.message.delete',
        'discard.success.message.move',
        'discard.success.dependents',
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
        'publish.companionsSkipped',
        'publish.failedTitle',
        'error.noPublishableRecords',
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
     * Resolve a full "LLL:EXT:…" label reference (e.g. a TCA column
     * label). Central LanguageService access for all services — keeps
     * the LANG/BE_USER fallback logic in one place.
     */
    public function resolveLabel(string $labelReference): string
    {
        return $this->getLanguageService()->sL($labelReference);
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
