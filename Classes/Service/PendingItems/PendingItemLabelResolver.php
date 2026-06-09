<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Schema\Exception\InvalidSchemaTypeException;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use Webconsulting\WebconEasyWorkspace\Service\LocalizationService;
use Webconsulting\WebconEasyWorkspace\Utility\TcaUtility;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

final readonly class PendingItemLabelResolver
{
    public function __construct(
        private TcaSchemaFactory $tcaSchemaFactory,
        private ResourceFactory $resourceFactory,
        private BackendLayoutView $backendLayoutView,
        private LocalizationService $localizationService,
    ) {}

    /**
     * @return array<int, string>
     */
    public function resolveColumnLabels(int $pageUid): array
    {
        try {
            $structure = $this->backendLayoutView->getSelectedBackendLayout($pageUid);
        } catch (\Throwable) {
            return [];
        }
        $columns = $structure['usedColumns'] ?? [];
        if (!is_array($columns)) {
            return [];
        }
        $out = [];
        foreach ($columns as $colPos => $rawLabel) {
            $resolved = $this->localizationService->resolveLabel(Value::string($rawLabel));
            $out[Value::int($colPos)] = $resolved !== '' ? $resolved : Value::string($rawLabel);
        }
        return $out;
    }

    public function resolveTableLabel(string $table): string
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return $table;
        }
        $title = $this->tcaSchemaFactory->get($table)->getTitle(
            $this->localizationService->resolveLabel(...),
        );
        return $title !== '' ? $title : $table;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function resolveTitle(string $table, array $row): string
    {
        if ($table === 'sys_file_metadata') {
            $fileName = $this->resolveFileMetadataTitle($row);
            if ($fileName !== '') {
                return $fileName;
            }
        }
        if ($table === 'sys_file_reference') {
            $fileName = $this->resolveFileReferenceTitle($row);
            if ($fileName !== '') {
                return $fileName;
            }
        }
        $title = trim((string)BackendUtility::getRecordTitle($table, $row));
        if ($title !== '' && !str_starts_with($title, '[no title]')) {
            return $title;
        }
        if ($table === 'tt_content' && isset($row['bodytext'])) {
            $fallback = $this->extractTextSnippet(Value::string($row['bodytext']));
            if ($fallback !== '') {
                return $fallback;
            }
        }
        $typeLabel = $this->resolveTypeLabel($table, $row);
        if ($typeLabel !== '') {
            return $typeLabel . ' · #' . Value::int($row['uid'] ?? null);
        }
        return $table . ' #' . Value::int($row['uid'] ?? null);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function resolveTypeLabel(string $table, array $row): string
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return $table;
        }
        $schema = $this->tcaSchemaFactory->get($table);
        try {
            $typeField = $schema->getSubSchemaTypeInformation()->getFieldName();
        } catch (InvalidSchemaTypeException) {
            $typeField = null;
        }

        if ($typeField === null || !isset($row[$typeField])) {
            $rawConfiguration = $schema->getRawConfiguration();
            $ctrl = Value::stringKeyArray($rawConfiguration['ctrl'] ?? null);
            $title = Value::string($ctrl['title'] ?? $table);
            $label = $this->localizationService->resolveLabel($title);
            return $label !== '' ? $label : $table;
        }

        $value = Value::string($row[$typeField] ?? null);
        $label = BackendUtility::getLabelFromItemlist($table, $typeField, $value);
        if ($label !== '') {
            return $this->localizationService->resolveLabel($label);
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function resolveIconIdentifier(string $table, array $row): string
    {
        $tca = TcaUtility::table($table);
        $ctrl = Value::stringKeyArray($tca['ctrl'] ?? null);
        $typeIconClasses = Value::stringKeyArray($ctrl['typeicon_classes'] ?? null);

        $typeField = Value::string($ctrl['type'] ?? null);
        if ($typeField !== '') {
            $typeValue = Value::string($row[$typeField] ?? null);
            if ($typeValue !== '' && isset($typeIconClasses[$typeValue]) && is_string($typeIconClasses[$typeValue])) {
                return $typeIconClasses[$typeValue];
            }
        }

        if (isset($typeIconClasses['default']) && is_string($typeIconClasses['default']) && $typeIconClasses['default'] !== '') {
            return $typeIconClasses['default'];
        }

        return match ($table) {
            'pages' => 'apps-pagetree-page-default',
            'tt_content' => 'mimetypes-x-content-text',
            'sys_file_metadata', 'sys_file_reference' => 'mimetypes-other-other',
            default => 'mimetypes-other-other',
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    public function resolveFileMetadataTitle(array $row): string
    {
        $fileUid = Value::int($row['file'] ?? null);
        if ($fileUid <= 0) {
            return '';
        }
        try {
            return $this->resourceFactory->getFileObject($fileUid)->getName();
        } catch (FileDoesNotExistException | ResourceDoesNotExistException) {
            return '';
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    public function resolveFileReferenceTitle(array $row): string
    {
        $uidLocal = Value::int($row['uid_local'] ?? null);
        if ($uidLocal <= 0) {
            return '';
        }
        try {
            return $this->resourceFactory->getFileObject($uidLocal)->getName();
        } catch (FileDoesNotExistException | ResourceDoesNotExistException) {
            return '';
        }
    }

    public function extractTextSnippet(string $raw): string
    {
        $stripped = trim(strip_tags($raw));
        if ($stripped === '') {
            return '';
        }
        $clean = (string)preg_replace('/\s+/u', ' ', $stripped);
        if (mb_strlen($clean) <= 80) {
            return $clean;
        }
        return mb_substr($clean, 0, 80) . '…';
    }

    /**
     * @param array<int, string> $columnLabels
     */
    public function resolveColPosLabel(int $colPos, array $columnLabels): string
    {
        $label = $columnLabels[$colPos] ?? null;
        if (is_string($label) && $label !== '') {
            return $label;
        }
        return $this->localizationService->translate('toolbar.column', ['number' => $colPos]);
    }
}
