<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use Webconsulting\WebconEasyWorkspace\Enum\ToolbarContext;
use Webconsulting\WebconEasyWorkspace\Security\BackendAccessGuard;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * Describes the page or news record the toolbar list is scoped to, so
 * the dropdown can render a group header (icon, title, rootline path).
 */
final readonly class ContextRecordResolver
{
    public function __construct(
        private IconFactory $iconFactory,
        private BackendAccessGuard $accessGuard,
    ) {}

    /**
     * @return array{table: string, uid: int, title: string, path: string, iconIdentifier: string}|null
     */
    public function resolve(ToolbarContext $context, int $pageUid, int $newsUid, ?ServerRequestInterface $request = null): ?array
    {
        [$table, $uid] = match ($context) {
            ToolbarContext::Page => ['pages', $pageUid],
            ToolbarContext::News => ['tx_news_domain_model_news', $newsUid],
            ToolbarContext::None => ['', 0],
        };
        if ($table === '' || $uid <= 0) {
            return null;
        }
        $row = BackendUtility::getRecordWSOL($table, $uid);
        if (!is_array($row)) {
            return null;
        }
        $row = Value::stringKeyArray($row);
        $permsClause = $this->accessGuard->user($request)?->getPagePermsClause(Permission::PAGE_SHOW) ?? '';
        $path = BackendUtility::getRecordPath(Value::int($row['pid'] ?? null), $permsClause, 40);

        return [
            'table' => $table,
            'uid' => $uid,
            'title' => BackendUtility::getRecordTitle($table, $row),
            'path' => trim(Value::string($path), '/'),
            'iconIdentifier' => $this->iconFactory->getIconForRecord($table, $row, IconSize::SMALL)->getIdentifier(),
        ];
    }
}
