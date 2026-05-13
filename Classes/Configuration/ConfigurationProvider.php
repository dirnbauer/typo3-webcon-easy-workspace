<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Configuration;

use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Reads the Easy Workspace feature flags from User TSconfig (and
 * optionally Page TSconfig if a page context is provided).
 *
 * Defaults live in EXT:webcon_easy_workspace/Configuration/user.tsconfig
 * (auto-loaded by TYPO3 v14), so this class only needs to handle
 * normalization, type coercion and a couple of safety fallbacks if a
 * site has not touched the defaults at all.
 */
final readonly class ConfigurationProvider
{
    private const DEFAULTS = [
        'enabled' => true,
        'enablePreviewLink' => true,
        'enableFilter' => true,
        'defaultMode' => 'changed',
        'showHidden' => true,
        'enableThumbnails' => true,
        'maxItems' => 200,
    ];

    private const NAMESPACE_KEY = 'webcon_easy_workspace.';

    /**
     * Returns the effective configuration for the current backend user.
     *
     * @param int|null $pageUid Optional page uid — when provided, Page
     *                          TSconfig overrides at that page take
     *                          precedence over User TSconfig.
     * @return array{
     *     enabled: bool,
     *     enablePreviewLink: bool,
     *     enableFilter: bool,
     *     defaultMode: string,
     *     showHidden: bool,
     *     enableThumbnails: bool,
     *     maxItems: int
     * }
     */
    public function get(?int $pageUid = null): array
    {
        $merged = self::DEFAULTS;

        // User TSconfig (applies to every backend page in the current user's session).
        $userOptions = $this->extractOptions($this->getUserTsConfig());
        $merged = array_merge($merged, $userOptions);

        // Page TSconfig overrides for the specific page context, if any.
        if ($pageUid !== null && $pageUid > 0) {
            $pageOptions = $this->extractOptions(BackendUtility::getPagesTSconfig($pageUid));
            $merged = array_merge($merged, $pageOptions);
        }

        // Normalize types.
        return [
            'enabled' => $this->toBool($merged['enabled']),
            'enablePreviewLink' => $this->toBool($merged['enablePreviewLink']),
            'enableFilter' => $this->toBool($merged['enableFilter']),
            'defaultMode' => $this->normalizeMode((string)$merged['defaultMode']),
            'showHidden' => $this->toBool($merged['showHidden']),
            'enableThumbnails' => $this->toBool($merged['enableThumbnails']),
            'maxItems' => max(1, (int)$merged['maxItems']),
        ];
    }

    /**
     * Pull the `options.webcon_easy_workspace.*` subtree out of any
     * TSconfig array and flatten it into a one-level associative array.
     *
     * @param array<string, mixed> $tsConfig
     * @return array<string, mixed>
     */
    private function extractOptions(array $tsConfig): array
    {
        $options = $tsConfig['options.'][self::NAMESPACE_KEY] ?? null;
        if (!is_array($options)) {
            return [];
        }
        $flat = [];
        foreach ($options as $key => $value) {
            if (is_string($key) && !str_ends_with($key, '.')) {
                $flat[$key] = $value;
            }
        }
        return $flat;
    }

    /**
     * @return array<string, mixed>
     */
    private function getUserTsConfig(): array
    {
        if (!isset($GLOBALS['BE_USER'])) {
            return [];
        }
        $tsConfig = $GLOBALS['BE_USER']->getTSConfig();
        return is_array($tsConfig) ? $tsConfig : [];
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $value = strtolower(trim($value));
            return $value === '1' || $value === 'true' || $value === 'on' || $value === 'yes';
        }
        return false;
    }

    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return $mode === 'all' ? 'all' : 'changed';
    }
}
