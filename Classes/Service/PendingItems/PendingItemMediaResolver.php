<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

final readonly class PendingItemMediaResolver
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private ResourceFactory $resourceFactory,
    ) {}

    public function resolveThumbnailUrl(string $table, int $workspaceUid): ?string
    {
        if ($table === 'sys_file_metadata') {
            $row = BackendUtility::getRecord('sys_file_metadata', $workspaceUid, 'file');
            $fileUid = is_array($row) ? Value::int($row['file'] ?? null) : 0;
            return $fileUid > 0 ? $this->referenceToUrl($fileUid) : null;
        }
        if ($table === 'sys_file_reference') {
            $row = BackendUtility::getRecord('sys_file_reference', $workspaceUid, 'uid_local');
            $fileUid = is_array($row) ? Value::int($row['uid_local'] ?? null) : 0;
            return $fileUid > 0 ? $this->referenceToUrl($fileUid) : null;
        }
        $fieldNamesPerTable = [
            'tt_content' => ['image', 'assets', 'media'],
            'tx_news_domain_model_news' => ['fal_media', 'fal_related_files'],
            'pages' => ['media'],
        ];
        if (!isset($fieldNamesPerTable[$table])) {
            return null;
        }
        foreach ($fieldNamesPerTable[$table] as $fieldname) {
            $referenceUid = $this->findFirstReferenceUid($table, $workspaceUid, $fieldname);
            if ($referenceUid <= 0) {
                continue;
            }
            $url = $this->referenceToUrl($referenceUid);
            if ($url !== null) {
                return $url;
            }
        }
        return null;
    }

    public function findFirstReferenceUid(string $parentTable, int $parentUid, string $fieldname): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('uid_local')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($parentTable)),
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($fieldname)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('sorting_foreign', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return is_array($row) ? Value::int($row['uid_local'] ?? null) : 0;
    }

    public function referenceToUrl(int $fileUid): ?string
    {
        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
            if (!str_starts_with($file->getMimeType(), 'image/')) {
                return null;
            }
            $publicUrl = $file
                ->process(ProcessedFile::CONTEXT_IMAGEPREVIEW, ['width' => 96, 'height' => 72])
                ->getPublicUrl();
            return $publicUrl !== null && $publicUrl !== '' ? $publicUrl : null;
        } catch (FileDoesNotExistException | ResourceDoesNotExistException) {
            return null;
        }
    }
}
