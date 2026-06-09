<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use Webconsulting\WebconEasyWorkspace\Configuration\ConfigurationProvider;
use Webconsulting\WebconEasyWorkspace\Security\BackendAccessGuard;
use Webconsulting\WebconEasyWorkspace\Service\EasyWorkspaceModuleDocHeaderBuilder;
use Webconsulting\WebconEasyWorkspace\Service\LocalizationService;
use Webconsulting\WebconEasyWorkspace\Service\ModuleSectionViewDataFactory;
use Webconsulting\WebconEasyWorkspace\Service\PublishSelectedService;
use Webconsulting\WebconEasyWorkspace\Utility\PublishSelectionNormalizer;
use Webconsulting\WebconEasyWorkspace\Utility\Value;
use Webconsulting\WebconEasyWorkspace\Utility\WorkspaceTablePolicy;

#[AsController]
final readonly class EasyWorkspaceModuleController
{
    private const SECTIONS = ['pending', 'all', 'diagnostics'];

    private const MODULE_SECTIONS = [
        'webcon_easy_workspace' => 'pending',
        'webcon_easy_workspace_pending' => 'pending',
        'webcon_easy_workspace_records' => 'all',
        'webcon_easy_workspace_diagnostics' => 'diagnostics',
    ];

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private PageRenderer $pageRenderer,
        private BackendUriBuilder $backendUriBuilder,
        private TcaSchemaFactory $tcaSchemaFactory,
        private BackendAccessGuard $accessGuard,
        private ConfigurationProvider $configurationProvider,
        private LocalizationService $localizationService,
        private PublishSelectedService $publishService,
        private FlashMessageService $flashMessageService,
        private WorkspaceTablePolicy $workspaceTablePolicy,
        private PublishSelectionNormalizer $publishSelectionNormalizer,
        private ModuleSectionViewDataFactory $sectionViewDataFactory,
        private EasyWorkspaceModuleDocHeaderBuilder $docHeaderBuilder,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if ($method === 'POST') {
            $parsed = $this->parsedBody($request);
            $action = Value::string(($parsed['_action'] ?? null));
            if ($action === 'publish') {
                return $this->handlePublish($request);
            }
            if ($action === 'discard') {
                return $this->handleDiscard($request);
            }
        }

        return $this->renderModule($request);
    }

    private function renderModule(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $pageUid = Value::int($queryParams['id'] ?? null);
        $newsUid = Value::int($queryParams['newsUid'] ?? null);
        $section = $this->resolveSection($request);

        $newsRecord = $this->resolveNewsRecord($newsUid);
        if ($pageUid <= 0 && $newsRecord !== []) {
            $pageUid = Value::int($newsRecord['pid'] ?? null);
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $pageRecord = $this->resolvePageRecord($pageUid, $request);
        $rootLine = $this->resolveRootLine($pageUid, $request);
        $activeWorkspaceId = $this->accessGuard->activeWorkspaceId($request);
        $config = $this->configurationProvider->get($pageUid > 0 ? $pageUid : null);

        $this->pageRenderer->loadJavaScriptModule('@webconsulting/webcon-easy-workspace/easy-workspace-module.js');
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/element/contextual-record-edit-trigger.js');
        $this->pageRenderer->addCssFile('EXT:webcon_easy_workspace/Resources/Public/Css/easy-workspace.css');

        $moduleTemplate->makeDocHeaderModuleMenu($this->buildModuleMenuParameters($pageUid, $newsUid));

        if ($pageRecord !== []) {
            $moduleTemplate->getDocHeaderComponent()->setPageBreadcrumb($pageRecord);
            $this->docHeaderBuilder->addPageActionButtons($moduleTemplate, $request, $pageRecord, $pageUid, $rootLine);
        }

        $pageTitle = $newsRecord !== []
            ? BackendUtility::getRecordTitle('tx_news_domain_model_news', $newsRecord)
            : ($pageRecord !== [] ? BackendUtility::getRecordTitle('pages', $pageRecord) : '');
        $moduleTemplate->setTitle($this->localizationService->translate('module.title'), $pageTitle);

        $canRender = $config['enabled'] && $activeWorkspaceId > 0;
        $disabledMessage = $this->disabledMessage($config['enabled'], $activeWorkspaceId);
        $hasContext = $section === 'diagnostics' || $pageUid > 0 || $newsUid > 0;

        $viewData = [
            'moduleTitle' => $this->localizationService->translate('module.title'),
            'moduleDescription' => $this->localizationService->translate('module.description'),
            'sectionTitle' => $this->localizationService->translate($this->sectionTitleKey($section)),
            'sectionDescription' => $this->localizationService->translate($this->sectionDescriptionKey($section)),
            'pageTitle' => $pageTitle,
            'canRenderEasyWorkspace' => $canRender,
            'disabledMessage' => $disabledMessage,
            'section' => $section,
            'canSeeDiagnostics' => $this->accessGuard->user($request)?->isAdmin() ?? false,
            'moduleUrls' => $this->buildModuleUrls($pageUid, $newsUid),
            'flashMessages' => $this->flushFlashMessages(),
            'config' => $config,
            'hasContext' => $hasContext,
            'pageUid' => $pageUid,
            'newsUid' => $newsUid,
            'activeWorkspaceId' => $activeWorkspaceId,
            'formAction' => $this->buildSelfUrl($request),
            'previewLinkUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_webcon_easy_workspace_preview_link'),
            'diffUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_webcon_easy_workspace_diff'),
            'jsLabelsJson' => json_encode($this->buildJsLabelMap(), JSON_THROW_ON_ERROR),
        ];

        if ($canRender && $hasContext) {
            $viewData['data'] = $this->sectionViewDataFactory->build($section, $pageUid, $newsUid, $config, $activeWorkspaceId);
        }

        $moduleTemplate->assignMultiple($viewData);

        return $moduleTemplate->renderResponse('Backend/EasyWorkspace/Index');
    }

    private function handlePublish(ServerRequestInterface $request): ResponseInterface
    {
        $parsed = $this->parsedBody($request);
        $rawSelections = is_array($parsed['selections'] ?? null) ? $parsed['selections'] : [];
        $config = $this->configurationProvider->get();
        if (!$config['enabled']) {
            $this->enqueueFlash($this->localizationService->translate('error.disabled'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectBack($request);
        }

        $result = $this->publishService->publish(
            $this->publishSelectionNormalizer->fromModuleForm($rawSelections),
            $this->accessGuard->user($request),
        );
        if ($result['success']) {
            $message = $result['published'] > 0
                ? $this->localizationService->translate('publish.success.message', ['count' => $result['published']])
                : $this->localizationService->translate('module.publish.empty');
            $severity = $result['published'] > 0 ? ContextualFeedbackSeverity::OK : ContextualFeedbackSeverity::INFO;
            $this->enqueueFlash($message, $severity, $this->localizationService->translate('publish.success.title'));
        } else {
            $errors = implode(' / ', $result['errors'] ?: [$this->localizationService->translate('error.unknown')]);
            $this->enqueueFlash($errors, ContextualFeedbackSeverity::WARNING, $this->localizationService->translate('publish.warning.title'));
        }

        return $this->redirectBack($request);
    }

    private function handleDiscard(ServerRequestInterface $request): ResponseInterface
    {
        $parsed = $this->parsedBody($request);
        $table = Value::string($parsed['table'] ?? null);
        $workspaceUid = Value::int($parsed['workspaceUid'] ?? null);
        $config = $this->configurationProvider->get();
        if (!$config['enabled']) {
            $this->enqueueFlash($this->localizationService->translate('error.disabled'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectBack($request);
        }
        if (!$config['enableRevert']) {
            $this->enqueueFlash($this->localizationService->translate('error.revertDisabled'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectBack($request);
        }
        if (!$this->workspaceTablePolicy->isAllowed($table) || $workspaceUid <= 0) {
            $this->enqueueFlash($this->localizationService->translate('error.missingTableWorkspace'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectBack($request);
        }
        if (!$this->accessGuard->canModifyTable($table, $request)) {
            $this->enqueueFlash($this->localizationService->translate('error.noTablePermission'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectBack($request);
        }

        $result = $this->publishService->discard($table, $workspaceUid, $this->accessGuard->user($request));
        if ($result['success']) {
            $this->enqueueFlash(
                $this->localizationService->translate('discard.success.message', ['title' => '#' . $workspaceUid]),
                ContextualFeedbackSeverity::OK,
                $this->localizationService->translate('discard.success.title'),
            );
        } else {
            $errors = implode(' / ', $result['errors'] ?: [$this->localizationService->translate('error.unknown')]);
            $this->enqueueFlash($errors, ContextualFeedbackSeverity::ERROR, $this->localizationService->translate('discard.error.title'));
        }

        return $this->redirectBack($request);
    }

    /**
     * @return array{pending: string, all: string, diagnostics: string}
     */
    private function buildModuleUrls(int $pageUid, int $newsUid): array
    {
        $parameters = $this->buildModuleMenuParameters($pageUid, $newsUid);

        return [
            'pending' => (string)$this->backendUriBuilder->buildUriFromRoute('webcon_easy_workspace_pending', $parameters),
            'all' => (string)$this->backendUriBuilder->buildUriFromRoute('webcon_easy_workspace_records', $parameters),
            'diagnostics' => (string)$this->backendUriBuilder->buildUriFromRoute('webcon_easy_workspace_diagnostics', $parameters),
        ];
    }

    private function resolveSection(ServerRequestInterface $request): string
    {
        $section = $this->requestedSection($request);
        // Diagnostics expose schema-level scan details (orphaned rows,
        // raw repair SQL) — admin only, mirroring the module registration.
        if ($section === 'diagnostics' && !($this->accessGuard->user($request)?->isAdmin() ?? false)) {
            return 'pending';
        }

        return $section;
    }

    private function requestedSection(ServerRequestInterface $request): string
    {
        $path = rtrim($request->getUri()->getPath(), '/');
        if (str_ends_with($path, '/module/content/easy-workspace')) {
            return 'pending';
        }

        $module = $request->getAttribute('module');
        if ($module instanceof ModuleInterface) {
            return self::MODULE_SECTIONS[$module->getIdentifier()] ?? 'pending';
        }

        $candidate = Value::string($request->getQueryParams()['section'] ?? null);

        return in_array($candidate, self::SECTIONS, true) ? $candidate : 'pending';
    }

    private function sectionTitleKey(string $section): string
    {
        return match ($section) {
            'all' => 'module.section.all',
            'diagnostics' => 'module.section.testsDiagnostics',
            default => 'module.section.pending',
        };
    }

    private function sectionDescriptionKey(string $section): string
    {
        return match ($section) {
            'all' => 'module.all.subtitle',
            'diagnostics' => 'module.testsDiagnostics.subtitle',
            default => 'module.pending.subtitle',
        };
    }

    /**
     * @return array<string, string>
     */
    private function buildJsLabelMap(): array
    {
        $keys = [
            'discard.modal.title',
            'discard.modal.message',
            'discard.modal.message.modified',
            'discard.modal.message.new',
            'discard.modal.message.delete',
            'discard.modal.message.move',
            'discard.modal.cancel',
            'discard.modal.confirm',
            'edit.title',
            'edit.noForm',
            'edit.modalTitle',
            'diff.noTitle',
            'diff.modal.historyTitle',
            'rollback.failedTitle',
            'rollback.missingData',
            'rollback.noField',
            'rollback.confirmField',
            'rollback.confirmLinear',
            'rollback.successTitle',
            'rollback.successField',
            'rollback.successLinear',
            'rollback.errorTitle',
            'error.unknown',
            'preview.button.preview',
            'preview.open.opening',
            'preview.link.title',
            'preview.link.noUrl',
            'error.unexpected',
            'toolbar.publishToLive',
            'module.publishBar.summary',
            'module.publishBar.unselected',
        ];
        $map = [];
        foreach ($keys as $key) {
            $map[$key] = $this->localizationService->translate($key);
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function buildSelfUrl(ServerRequestInterface $request, array $parameters = []): string
    {
        $query = $request->getQueryParams();
        unset($query['_action']);
        unset($query['section']);
        $merged = array_replace($query, $parameters);

        return (string)$this->backendUriBuilder->buildUriFromRoute($this->currentModuleIdentifier($request), $merged);
    }

    /**
     * @return array<string, mixed>
     */
    private function parsedBody(ServerRequestInterface $request): array
    {
        $parsedBody = $request->getParsedBody();

        return is_array($parsedBody) ? Value::stringKeyArray($parsedBody) : [];
    }

    private function redirectBack(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        unset($query['_action']);
        unset($query['section']);
        $url = (string)$this->backendUriBuilder->buildUriFromRoute($this->currentModuleIdentifier($request), $query);

        return new RedirectResponse($url, 303);
    }

    /**
     * @return array<string, int>
     */
    private function buildModuleMenuParameters(int $pageUid, int $newsUid): array
    {
        return array_filter(
            [
                'id' => $pageUid,
                'newsUid' => $newsUid,
            ],
            static fn(int $value): bool => $value > 0,
        );
    }

    private function currentModuleIdentifier(ServerRequestInterface $request): string
    {
        $module = $request->getAttribute('module');
        if ($module instanceof ModuleInterface && $module->getIdentifier() !== 'webcon_easy_workspace') {
            return $module->getIdentifier();
        }

        return 'webcon_easy_workspace_pending';
    }

    private function enqueueFlash(string $message, ContextualFeedbackSeverity $severity, string $title = ''): void
    {
        $queue = $this->flashMessageService->getMessageQueueByIdentifier();
        $queue->enqueue(new FlashMessage($message, $title, $severity, true));
    }

    /**
     * @return list<array{title: string, message: string, severity: int}>
     */
    private function flushFlashMessages(): array
    {
        $queue = $this->flashMessageService->getMessageQueueByIdentifier();
        $messages = $queue->getAllMessagesAndFlush();
        $rendered = [];
        foreach ($messages as $message) {
            $rendered[] = [
                'title' => $message->getTitle(),
                'message' => $message->getMessage(),
                'severity' => $message->getSeverity()->value,
            ];
        }

        return $rendered;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePageRecord(int $pageUid, ServerRequestInterface $request): array
    {
        $backendUser = $this->accessGuard->user($request);
        if ($pageUid <= 0 || $backendUser === null) {
            return [];
        }

        $pageRecord = BackendUtility::readPageAccess($pageUid, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));

        return is_array($pageRecord) ? Value::stringKeyArray($pageRecord) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveNewsRecord(int $newsUid): array
    {
        if ($newsUid <= 0 || !$this->tcaSchemaFactory->has('tx_news_domain_model_news')) {
            return [];
        }

        $newsRecord = BackendUtility::getRecord('tx_news_domain_model_news', $newsUid);

        return is_array($newsRecord) ? Value::stringKeyArray($newsRecord) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveRootLine(int $pageUid, ServerRequestInterface $request): array
    {
        $backendUser = $this->accessGuard->user($request);
        if ($backendUser === null || $pageUid <= 0) {
            return [];
        }

        try {
            $rootLine = BackendUtility::BEgetRootLine($pageUid, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));

            return array_values(array_map(
                static fn(array $row): array => Value::stringKeyArray($row),
                array_filter($rootLine, is_array(...)),
            ));
        } catch (\Throwable) {
            return [];
        }
    }

    private function disabledMessage(bool $enabled, int $activeWorkspaceId): string
    {
        if (!$enabled) {
            return $this->localizationService->translate('module.disabledBySettings');
        }
        if ($activeWorkspaceId <= 0) {
            return $this->localizationService->translate('module.liveWorkspace');
        }

        return '';
    }
}
