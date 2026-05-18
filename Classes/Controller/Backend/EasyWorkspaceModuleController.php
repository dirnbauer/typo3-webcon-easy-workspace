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
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use Webconsulting\WebconEasyWorkspace\Configuration\ConfigurationProvider;
use Webconsulting\WebconEasyWorkspace\Service\LocalizationService;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

#[AsController]
final readonly class EasyWorkspaceModuleController
{
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
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $pageUid = Value::int($queryParams['id'] ?? null);
        $newsUid = Value::int($queryParams['newsUid'] ?? null);
        $newsRecord = $this->resolveNewsRecord($newsUid);
        if ($pageUid <= 0 && $newsRecord !== []) {
            $pageUid = Value::int($newsRecord['pid'] ?? null);
        }
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $pageRecord = $this->resolvePageRecord($pageUid);
        $activeWorkspaceId = $this->resolveActiveWorkspaceId();
        $config = $this->configurationProvider->get($pageUid > 0 ? $pageUid : null);

        $this->pageRenderer->loadJavaScriptModule('@webconsulting/webcon-easy-workspace/easy-workspace-menu-element.js');
        $this->pageRenderer->addCssFile('EXT:webcon_easy_workspace/Resources/Public/Css/easy-workspace.css');

        if ($pageRecord !== []) {
            $moduleTemplate->getDocHeaderComponent()->setPageBreadcrumb($pageRecord);
            $this->addPageActionButtons($moduleTemplate, $request, $pageRecord, $pageUid);
        }

        $pageTitle = $newsRecord !== []
            ? BackendUtility::getRecordTitle('tx_news_domain_model_news', $newsRecord)
            : ($pageRecord !== [] ? BackendUtility::getRecordTitle('pages', $pageRecord) : '');
        $moduleTemplate->setTitle($this->localizationService->translate('module.title'), $pageTitle);
        $moduleTemplate->assignMultiple([
            'canRenderEasyWorkspace' => $config['enabled'] && $activeWorkspaceId > 0,
            'disabledMessage' => $this->disabledMessage($config['enabled'], $activeWorkspaceId),
            'configJson' => json_encode($this->buildJavaScriptConfig($config, $activeWorkspaceId, $pageUid, $newsUid), JSON_THROW_ON_ERROR),
        ]);

        return $moduleTemplate->renderResponse('Backend/EasyWorkspace/Index');
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function buildJavaScriptConfig(array $config, int $activeWorkspaceId, int $pageUid, int $newsUid): array
    {
        return $config + [
            'activeWorkspaceId' => $activeWorkspaceId,
            'pageUid' => $newsUid > 0 ? 0 : $pageUid,
            'newsUid' => $newsUid,
            'hasVisualEditor' => ExtensionManagementUtility::isLoaded('visual_editor'),
            'hasViewpage' => ExtensionManagementUtility::isLoaded('viewpage'),
            'labels' => $this->localizationService->labelsForJavaScript(),
        ];
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
     */
    private function addPageActionButtons(ModuleTemplate $moduleTemplate, ServerRequestInterface $request, array $pageRecord, int $pageUid): void
    {
        $rootLine = $this->resolveRootLine($pageUid);
        $moduleTemplate->addButtonToButtonBar(
            $this->componentFactory->createViewButton(
                PreviewUriBuilder::create($pageRecord)
                    ->withRootLine($rootLine)
                    ->buildDispatcherDataAttributes() ?? [],
            ),
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
