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
    'after:webconEasyWorkspaceEnabled',
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
