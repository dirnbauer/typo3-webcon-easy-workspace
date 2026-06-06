<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder as BackendPreviewUriBuilder;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder as WorkspacePreviewUriBuilder;
use Webconsulting\WebconEasyWorkspace\Configuration\ConfigurationProvider;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * Registers doc-header action buttons for the Easy Workspace module.
 */
final readonly class EasyWorkspaceModuleDocHeaderBuilder
{
    public function __construct(
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
        private BackendUriBuilder $backendUriBuilder,
        private WorkspacePreviewUriBuilder $workspacePreviewUriBuilder,
        private ConfigurationProvider $configurationProvider,
        private LocalizationService $localizationService,
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    /**
     * @param array<string, mixed> $pageRecord
     * @param list<array<string, mixed>> $rootLine
     */
    public function addPageActionButtons(
        ModuleTemplate $moduleTemplate,
        ServerRequestInterface $request,
        array $pageRecord,
        int $pageUid,
        array $rootLine,
    ): void {
        $viewButton = $this->componentFactory->createViewButton(
            BackendPreviewUriBuilder::create($pageRecord)
                ->withRootLine($rootLine)
                ->buildDispatcherDataAttributes() ?? [],
        );
        $moduleTemplate->addButtonToButtonBar(
            $viewButton,
            ButtonBar::BUTTON_POSITION_LEFT,
            15,
        );

        $config = $this->configurationProvider->get($pageUid);
        if ($config['enablePreviewLink']) {
            $previewLink = '';
            try {
                $previewLink = $this->workspacePreviewUriBuilder->buildUriForPage($pageUid);
            } catch (\Throwable) {
                // The AJAX fallback will return the localized error when clicked.
            }
            $previewButton = $this->componentFactory->createGenericButton();
            if ($previewLink !== '') {
                $previewButton
                    ->setTag('a')
                    ->setHref($previewLink);
            } else {
                $previewButton
                    ->setTag('button');
            }
            $previewAttributes = [
                'data-wew-preview-trigger' => '',
                'data-wew-preview-page-uid' => (string)$pageUid,
                'data-wew-preview-link' => $previewLink,
            ];
            if ($previewLink !== '') {
                $previewAttributes['target'] = '_blank';
                $previewAttributes['rel'] = 'noopener noreferrer';
            } else {
                $previewAttributes['type'] = 'button';
            }
            $previewButton
                ->setAttributes($previewAttributes)
                ->setLabel($this->localizationService->translate('preview.button.preview'))
                ->setTitle($this->localizationService->translate('preview.open.title'))
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('actions-link', IconSize::SMALL));

            $moduleTemplate->addButtonToButtonBar($previewButton, ButtonBar::BUTTON_POSITION_LEFT, 16);
        }

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

    private function getCurrentRequestUri(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams instanceof NormalizedParams) {
            return $normalizedParams->getRequestUri();
        }

        return $request->getRequestTarget();
    }
}
