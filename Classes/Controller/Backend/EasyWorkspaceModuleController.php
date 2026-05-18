<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use Webconsulting\WebconEasyWorkspace\Configuration\ConfigurationProvider;
use Webconsulting\WebconEasyWorkspace\Service\LatestChangesService;
use Webconsulting\WebconEasyWorkspace\Service\LocalizationService;
use Webconsulting\WebconEasyWorkspace\Service\PendingItemsService;
use Webconsulting\WebconEasyWorkspace\Service\PublishSelectedService;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

#[AsController]
final readonly class EasyWorkspaceModuleController
{
    private const ALLOWED_TABLES = [
        'pages',
        'tt_content',
        'tx_news_domain_model_news',
        'sys_file_metadata',
    ];

    private const SECTIONS = ['dashboard', 'pending', 'all', 'activity'];

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private PageRenderer $pageRenderer,
        private BackendUriBuilder $backendUriBuilder,
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
        private TcaSchemaFactory $tcaSchemaFactory,
        private Context $context,
        private ConfigurationProvider $configurationProvider,
        private LocalizationService $localizationService,
        private PendingItemsService $pendingItemsService,
        private LatestChangesService $latestChangesService,
        private PublishSelectedService $publishService,
        private FlashMessageService $flashMessageService,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if ($method === 'POST') {
            $action = Value::string(($request->getParsedBody()['_action'] ?? null));
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
        $section = $this->resolveSection(Value::string($queryParams['section'] ?? null));

        $newsRecord = $this->resolveNewsRecord($newsUid);
        if ($pageUid <= 0 && $newsRecord !== []) {
            $pageUid = Value::int($newsRecord['pid'] ?? null);
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $pageRecord = $this->resolvePageRecord($pageUid);
        $rootLine = $this->resolveRootLine($pageUid);
        $activeWorkspaceId = $this->resolveActiveWorkspaceId();
        $config = $this->configurationProvider->get($pageUid > 0 ? $pageUid : null);

        $this->pageRenderer->loadJavaScriptModule('@webconsulting/webcon-easy-workspace/easy-workspace-module.js');
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/element/contextual-record-edit-trigger.js');
        $this->pageRenderer->addCssFile('EXT:webcon_easy_workspace/Resources/Public/Css/easy-workspace.css');

        if ($pageRecord !== []) {
            $moduleTemplate->getDocHeaderComponent()->setPageBreadcrumb($pageRecord);
            $this->addPageActionButtons($moduleTemplate, $request, $pageRecord, $pageUid, $rootLine);
        }

        $pageTitle = $newsRecord !== []
            ? BackendUtility::getRecordTitle('tx_news_domain_model_news', $newsRecord)
            : ($pageRecord !== [] ? BackendUtility::getRecordTitle('pages', $pageRecord) : '');
        $moduleTemplate->setTitle($this->localizationService->translate('module.title'), $pageTitle);

        $canRender = $config['enabled'] && $activeWorkspaceId > 0;
        $disabledMessage = $this->disabledMessage($config['enabled'], $activeWorkspaceId);
        $hasContext = $pageUid > 0 || $newsUid > 0;

        $sections = $this->buildSectionNav($section, $request, $pageUid, $newsUid);
        $sectionUrls = [];
        foreach ($sections as $navEntry) {
            $sectionUrls[$navEntry['key']] = $navEntry['url'];
        }

        $viewData = [
            'moduleTitle' => $this->localizationService->translate('module.title'),
            'pageTitle' => $pageTitle,
            'canRenderEasyWorkspace' => $canRender,
            'disabledMessage' => $disabledMessage,
            'section' => $section,
            'sections' => $sections,
            'sectionUrls' => $sectionUrls,
            'flashMessages' => $this->flushFlashMessages($moduleTemplate),
            'config' => $config,
            'hasContext' => $hasContext,
            'pageUid' => $pageUid,
            'newsUid' => $newsUid,
            'activeWorkspaceId' => $activeWorkspaceId,
            'formAction' => $this->buildSelfUrl($request, ['section' => $section]),
            'previewLinkUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_webcon_easy_workspace_preview_link'),
            'diffUrl' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_webcon_easy_workspace_diff'),
            'jsLabelsJson' => json_encode($this->buildJsLabelMap(), JSON_THROW_ON_ERROR),
        ];

        if ($canRender && $hasContext) {
            $viewData['data'] = $this->buildSectionPayload($section, $pageUid, $newsUid, $config);
        }

        $moduleTemplate->assignMultiple($viewData);

        return $moduleTemplate->renderResponse('Backend/EasyWorkspace/Index');
    }

    private function handlePublish(ServerRequestInterface $request): ResponseInterface
    {
        $parsed = $request->getParsedBody();
        $rawSelections = is_array($parsed['selections'] ?? null) ? $parsed['selections'] : [];
        $config = $this->configurationProvider->get();
        if (!$config['enabled']) {
            $this->enqueueFlash($this->localizationService->translate('error.disabled'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectBack($request);
        }

        $selections = [];
        foreach ($rawSelections as $entry) {
            $entry = is_string($entry) ? $entry : '';
            if ($entry === '') {
                continue;
            }
            [$table, $workspaceUid] = array_pad(explode(':', $entry, 2), 2, '');
            $workspaceUid = (int)$workspaceUid;
            if (!in_array($table, self::ALLOWED_TABLES, true) || $workspaceUid <= 0) {
                continue;
            }
            $selections[] = ['table' => $table, 'workspaceUid' => $workspaceUid];
        }

        $result = $this->publishService->publish($selections);
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
        $parsed = $request->getParsedBody();
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
        if (!in_array($table, self::ALLOWED_TABLES, true) || $workspaceUid <= 0) {
            $this->enqueueFlash($this->localizationService->translate('error.missingTableWorkspace'), ContextualFeedbackSeverity::ERROR);
            return $this->redirectBack($request);
        }

        $result = $this->publishService->discard($table, $workspaceUid);
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
     * @return array<string, mixed>
     */
    private function buildSectionPayload(string $section, int $pageUid, int $newsUid, array $config): array
    {
        $payload = [
            'changedCount' => 0,
            'totalCount' => 0,
            'workspaceTitle' => '',
            'workspaceId' => 0,
            'items' => [],
            'itemGroups' => [],
            'changedItemGroups' => [],
            'latestItems' => [],
        ];

        if ($section === 'activity') {
            $latest = $this->latestChangesService->list(LatestChangesService::DEFAULT_LIMIT, $config);
            $payload['latestItems'] = $latest['items'] ?? [];
            $payload['workspaceId'] = $latest['workspaceId'] ?? 0;
            return $payload;
        }

        $items = $newsUid > 0
            ? $this->pendingItemsService->forNews($newsUid, PendingItemsService::MODE_ALL, $config)
            : ($pageUid > 0 ? $this->pendingItemsService->forPage($pageUid, PendingItemsService::MODE_ALL, $config) : null);

        if ($items === null) {
            return $payload;
        }

        $payload['workspaceId'] = $items['workspaceId'] ?? 0;
        $payload['workspaceTitle'] = $items['workspaceTitle'] ?? '';
        $itemList = $items['items'] ?? [];
        $payload['items'] = $itemList;
        $payload['itemGroups'] = $items['itemGroups'] ?? [];
        $payload['changedItemGroups'] = $items['changedItemGroups'] ?? [];
        $payload['totalCount'] = count($itemList);
        $payload['changedCount'] = count(array_filter($itemList, static fn(array $i): bool => (bool)($i['isChanged'] ?? false)));

        return $payload;
    }

    /**
     * @return list<array{key: string, label: string, badge: int|null, badgeTone: string, url: string, current: bool, iconIdentifier: string}>
     */
    private function buildSectionNav(string $activeSection, ServerRequestInterface $request, int $pageUid, int $newsUid): array
    {
        $config = $this->configurationProvider->get($pageUid > 0 ? $pageUid : null);
        $changedCount = null;
        $totalCount = null;
        if ($config['enabled'] && ($pageUid > 0 || $newsUid > 0)) {
            $items = $newsUid > 0
                ? $this->pendingItemsService->forNews($newsUid, PendingItemsService::MODE_ALL, $config)
                : $this->pendingItemsService->forPage($pageUid, PendingItemsService::MODE_ALL, $config);
            $list = $items['items'] ?? [];
            $totalCount = count($list);
            $changedCount = count(array_filter($list, static fn(array $i): bool => (bool)($i['isChanged'] ?? false)));
        }

        $build = function (string $key, string $labelKey, ?int $badge, string $badgeTone, string $iconIdentifier) use ($request, $activeSection): array {
            return [
                'key' => $key,
                'label' => $this->localizationService->translate($labelKey),
                'badge' => $badge,
                'badgeTone' => $badgeTone,
                'iconIdentifier' => $iconIdentifier,
                'url' => $this->buildSelfUrl($request, ['section' => $key]),
                'current' => $activeSection === $key,
            ];
        };

        return [
            $build('dashboard', 'module.section.dashboard', null, 'default', 'actions-dashboard'),
            $build('pending', 'module.section.pending', $changedCount, 'primary', 'actions-document-save'),
            $build('all', 'module.section.all', $totalCount, 'default', 'actions-list'),
            $build('activity', 'module.section.activity', null, 'default', 'actions-clock'),
        ];
    }

    private function resolveSection(string $candidate): string
    {
        return in_array($candidate, self::SECTIONS, true) ? $candidate : 'dashboard';
    }

    /**
     * @return array<string, string>
     */
    private function buildJsLabelMap(): array
    {
        $keys = [
            'discard.modal.title',
            'discard.modal.message',
            'discard.modal.cancel',
            'discard.modal.confirm',
            'edit.title',
            'edit.noForm',
            'edit.modalTitle',
            'diff.noTitle',
            'diff.modal.historyTitle',
            'preview.link.title',
            'preview.link.noUrl',
            'preview.link.copied',
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

    private function buildSelfUrl(ServerRequestInterface $request, array $parameters): string
    {
        $query = $request->getQueryParams();
        unset($query['_action']);
        $merged = array_replace($query, $parameters);
        return (string)$this->backendUriBuilder->buildUriFromRoute('webcon_easy_workspace', $merged);
    }

    private function redirectBack(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        unset($query['_action']);
        $url = (string)$this->backendUriBuilder->buildUriFromRoute('webcon_easy_workspace', $query);
        return new RedirectResponse($url, 303);
    }

    private function enqueueFlash(string $message, ContextualFeedbackSeverity $severity, string $title = ''): void
    {
        $queue = $this->flashMessageService->getMessageQueueByIdentifier();
        $queue->enqueue(new FlashMessage($message, $title, $severity, true));
    }

    /**
     * Drain the persistent flash message queue so they are rendered
     * exactly once in the current response (Fluid template), rather
     * than carried over into a later request.
     *
     * @return list<array{title: string, message: string, severity: int}>
     */
    private function flushFlashMessages(ModuleTemplate $moduleTemplate): array
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
    private function resolvePageRecord(int $pageUid): array
    {
        if ($pageUid <= 0) {
            return [];
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
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
     * @param array<string, mixed> $pageRecord
     * @param list<array<string, mixed>> $rootLine
     */
    private function addPageActionButtons(ModuleTemplate $moduleTemplate, ServerRequestInterface $request, array $pageRecord, int $pageUid, array $rootLine): void
    {
        $viewButton = $this->componentFactory->createViewButton(
            PreviewUriBuilder::create($pageRecord)
                ->withRootLine($rootLine)
                ->buildDispatcherDataAttributes() ?? [],
        );
        $moduleTemplate->addButtonToButtonBar(
            $viewButton,
            ButtonBar::BUTTON_POSITION_LEFT,
            15,
        );

        if (!$this->isPageEditable($pageRecord)) {
            return;
        }

        $editParams = [
            'edit' => ['pages' => [$pageUid => 'edit']],
            'module' => 'webcon_easy_workspace',
            'returnUrl' => $this->getCurrentRequestUri($request),
        ];
        $languageService = $GLOBALS['LANG'] ?? null;
        $editPagePropertiesLabel = $languageService instanceof LanguageService
            ? ($languageService->sL('LLL:EXT:backend/Resources/Private/Language/locallang_layout.xlf:editPageProperties') ?: 'Edit page properties')
            : 'Edit page properties';

        $editButton = $this->componentFactory->createGenericButton()
            ->setTag('typo3-backend-contextual-record-edit-trigger')
            ->setAttributes([
                'url' => (string)$this->backendUriBuilder->buildUriFromRoute('record_edit_contextual', $editParams, RouterInterface::ABSOLUTE_URL),
                'edit-url' => (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', $editParams, RouterInterface::ABSOLUTE_URL),
            ])
            ->setLabel($editPagePropertiesLabel)
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-page-open', IconSize::SMALL));

        $moduleTemplate->addButtonToButtonBar($editButton, ButtonBar::BUTTON_POSITION_LEFT, 20);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveRootLine(int $pageUid): array
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || $pageUid <= 0) {
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

    /**
     * @param array<string, mixed> $pageRecord
     */
    private function isPageEditable(array $pageRecord): bool
    {
        if ($pageRecord === []) {
            return false;
        }

        $schema = $this->tcaSchemaFactory->get('pages');
        if ($schema->hasCapability(TcaSchemaCapability::AccessReadOnly)) {
            return false;
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }

        if ($schema->hasCapability(TcaSchemaCapability::AccessAdminOnly)) {
            return false;
        }

        $isEditLocked = false;
        if ($schema->hasCapability(TcaSchemaCapability::EditLock)) {
            $isEditLocked = (bool)($pageRecord[$schema->getCapability(TcaSchemaCapability::EditLock)->getFieldName()] ?? false);
        }
        if ($isEditLocked) {
            return false;
        }

        return $backendUser->doesUserHaveAccess($pageRecord, Permission::PAGE_EDIT)
            && $backendUser->check('tables_modify', 'pages');
    }

    private function resolveActiveWorkspaceId(): int
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || $backendUser->workspace <= 0) {
            return 0;
        }

        $contextWorkspaceId = Value::int($this->context->getPropertyFromAspect('workspace', 'id', 0));
        return $contextWorkspaceId > 0 ? $contextWorkspaceId : $backendUser->workspace;
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

    private function getCurrentRequestUri(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams instanceof NormalizedParams) {
            return $normalizedParams->getRequestUri();
        }

        return $request->getRequestTarget();
    }
}
