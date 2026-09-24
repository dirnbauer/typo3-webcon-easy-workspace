<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use Webconsulting\WebconEasyWorkspace\Enum\ToolbarContext;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * The page's (or news article's) pending changes for the badge, remembered
 * for as long as the workspace stamp does not move.
 *
 * The stamp moves with every DataHandler write that can change a count (see
 * WorkspaceRevision), so an entry is valid exactly until the next such
 * write. Switching modules on the same page, or returning to a page, then
 * costs a cache read instead of a collection. Entries are keyed by page and
 * overwritten in place; a lifetime bounds how long a write that bypassed
 * DataHandler can stay unseen.
 */
final readonly class ContextChangeSummary
{
    private const int LIFETIME = 300;

    public function __construct(
        private PendingItemsService $pendingItemsService,
        private FrontendInterface $cache,
    ) {}

    /**
     * @param array<string, mixed> $config Normalized config from ConfigurationProvider.
     * @return array{count: int, records: list<array{table: string, liveUid: int, workspaceUid: int}>}|null
     */
    public function forContext(int $workspaceId, string $stamp, int $pageUid, int $newsUid, array $config): ?array
    {
        $context = ToolbarContext::resolve($pageUid, $newsUid);
        if ($workspaceId <= 0 || $context === ToolbarContext::None) {
            return null;
        }

        $identifier = sha1(implode('|', [
            'context',
            $workspaceId,
            $context->value,
            $context === ToolbarContext::News ? $newsUid : $pageUid,
            // The only settings that change what is listed.
            ($config['showHidden'] ?? true) ? '1' : '0',
            Value::int($config['maxItems'] ?? 200),
        ]));
        $cached = $this->cache->get($identifier);
        if (is_array($cached)
            && ($cached['stamp'] ?? null) === $stamp
            && Value::int($cached['expires'] ?? null) > time()
            && is_array($cached['summary'] ?? null)
        ) {
            /** @var array{count: int, records: list<array{table: string, liveUid: int, workspaceUid: int}>} $summary */
            $summary = $cached['summary'];
            return $summary;
        }

        $summary = $this->pendingItemsService->changesForContext($pageUid, $newsUid, $config);
        if ($summary !== null) {
            $this->cache->set($identifier, ['stamp' => $stamp, 'expires' => time() + self::LIFETIME, 'summary' => $summary]);
        }

        return $summary;
    }
}
