<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Configuration;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

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
        // Master switch
        'enabled' => true,
        // Per-user defaults
        'userEnabledDefault' => true,
        'showSubelementsInToolbar' => false,
        'showSubelementsInModule' => true,
        // Header
        'enableWorkspaceChip' => true,
        'enablePreviewLink' => true,
        // List filter
        'enableFilter' => true,
        'defaultMode' => 'changed',
        // List rendering
        'enableThumbnails' => true,
        'enableTypeLabels' => true,
        'enableHiddenBadge' => true,
        'showHidden' => true,
        'maxItems' => 200,
        // Aggregation scope
        'enableNewsBundles' => true,
        // Per-row actions
        'enableHoverHighlight' => true,
        'enableRevert' => true,
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
     *     userEnabled: bool,
     *     showSubelementsInToolbar: bool,
     *     showSubelementsInModule: bool,
     *     enableWorkspaceChip: bool,
     *     enablePreviewLink: bool,
     *     enableFilter: bool,
     *     defaultMode: string,
     *     enableThumbnails: bool,
     *     enableTypeLabels: bool,
     *     enableHiddenBadge: bool,
     *     showHidden: bool,
     *     maxItems: int,
     *     enableNewsBundles: bool,
     *     enableHoverHighlight: bool,
     *     enableRevert: bool
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
            $pageOptions = $this->extractOptions(Value::stringKeyArray(BackendUtility::getPagesTSconfig($pageUid)));
            $merged = array_merge($merged, $pageOptions);
        }

        // Normalize types.
        $tsConfigEnabled = $this->toBool($merged['enabled']);
        $userEnabled = $this->getUserSettingBool('webconEasyWorkspaceEnabled', $this->toBool($merged['userEnabledDefault']));
        return [
            'enabled' => $tsConfigEnabled && $userEnabled,
            'userEnabled' => $userEnabled,
            'showSubelementsInToolbar' => $this->getUserSettingBool('webconEasyWorkspaceShowSubelementsToolbar', $this->toBool($merged['showSubelementsInToolbar'])),
            'showSubelementsInModule' => $this->getUserSettingBool('webconEasyWorkspaceShowSubelementsModule', $this->toBool($merged['showSubelementsInModule'])),
            'enableWorkspaceChip' => $this->toBool($merged['enableWorkspaceChip']),
            'enablePreviewLink' => $this->toBool($merged['enablePreviewLink']),
            'enableFilter' => $this->toBool($merged['enableFilter']),
            'defaultMode' => $this->normalizeMode(Value::string($merged['defaultMode'])),
            'enableThumbnails' => $this->toBool($merged['enableThumbnails']),
            'enableTypeLabels' => $this->toBool($merged['enableTypeLabels']),
            'enableHiddenBadge' => $this->toBool($merged['enableHiddenBadge']),
            'showHidden' => $this->toBool($merged['showHidden']),
            'maxItems' => max(1, Value::int($merged['maxItems'])),
            'enableNewsBundles' => $this->toBool($merged['enableNewsBundles']),
            'enableHoverHighlight' => $this->toBool($merged['enableHoverHighlight']),
            'enableRevert' => $this->toBool($merged['enableRevert']),
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
        $optionsRoot = Value::stringKeyArray($tsConfig['options.'] ?? null);
        $options = $optionsRoot[self::NAMESPACE_KEY] ?? null;
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
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return [];
        }
        return Value::stringKeyArray($backendUser->getTSConfig());
    }

    private function getUserSettingBool(string $key, bool $default): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $default;
        }

        $userSettings = $backendUser->getUserSettings();
        if (!$userSettings->has($key)) {
            return $default;
        }

        return $this->toBool($userSettings->get($key));
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
