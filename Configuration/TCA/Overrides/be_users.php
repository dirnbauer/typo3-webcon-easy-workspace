<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

if (isset($GLOBALS['TCA']) && is_array($GLOBALS['TCA'])) {
    (static function (array &$tca): void {
        $tca['be_users'] ??= [];
        if (!is_array($tca['be_users'])) {
            return;
        }
        $tca['be_users']['columns'] ??= [];
        if (!is_array($tca['be_users']['columns'])) {
            return;
        }
        $tca['be_users']['columns']['user_settings'] ??= [];
        if (!is_array($tca['be_users']['columns']['user_settings'])) {
            return;
        }
        $currentShowitem = $tca['be_users']['columns']['user_settings']['showitem'] ?? '';
        if (!is_string($currentShowitem)) {
            $currentShowitem = '';
        }
        $tca['be_users']['columns']['user_settings']['showitem'] = rtrim($currentShowitem, ', ')
            . ',--div--;LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:userSettings.tab';
    })($GLOBALS['TCA']);
}

ExtensionManagementUtility::addUserSetting(
    'webconEasyWorkspaceEnabled',
    [
        'label' => 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:userSettings.enabled',
        'persistentUpdate' => true,
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 1,
        ],
    ],
    'after:--div--;LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:userSettings.tab',
);

// The dropdown's layout, chosen per editor; the TSconfig default
// options.webcon_easy_workspace.layout applies until they choose one, and
// whenever they choose "Site default" (the empty value a form save without
// a choice stores). No persistentUpdate: core sends it as 0/1 for checkboxes
// only, and the dropdown reads the layout from its server-rendered config.
ExtensionManagementUtility::addUserSetting(
    'webconEasyWorkspaceLayout',
    [
        'label' => 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:userSettings.layout',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'default' => '',
            'items' => [
                ['label' => 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:userSettings.layout.default', 'value' => ''],
                ['label' => 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:userSettings.layout.clarity', 'value' => 'clarity'],
                ['label' => 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:userSettings.layout.efficiency', 'value' => 'efficiency'],
                ['label' => 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:userSettings.layout.core', 'value' => 'core'],
            ],
        ],
    ],
    'after:webconEasyWorkspaceEnabled',
);

ExtensionManagementUtility::addUserSetting(
    'webconEasyWorkspaceShowSubelementsToolbar',
    [
        'label' => 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:userSettings.showSubelementsToolbar',
        'persistentUpdate' => true,
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 0,
        ],
    ],
    'after:webconEasyWorkspaceLayout',
);

ExtensionManagementUtility::addUserSetting(
    'webconEasyWorkspaceShowSubelementsModule',
    [
        'label' => 'LLL:EXT:webcon_easy_workspace/Resources/Private/Language/locallang.xlf:userSettings.showSubelementsModule',
        'persistentUpdate' => true,
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 1,
        ],
    ],
    'after:webconEasyWorkspaceShowSubelementsToolbar',
);
