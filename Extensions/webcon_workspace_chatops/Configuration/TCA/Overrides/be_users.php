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
        $tab = '--div--;LLL:EXT:webcon_workspace_chatops/Resources/Private/Language/locallang.xlf:userSettings.tab';
        if (!str_contains($currentShowitem, $tab)) {
            $tca['be_users']['columns']['user_settings']['showitem'] = rtrim($currentShowitem, ', ') . ',' . $tab;
        }
    })($GLOBALS['TCA']);
}

ExtensionManagementUtility::addUserSetting(
    'webconWorkspaceChatopsEnabled',
    [
        'label' => 'LLL:EXT:webcon_workspace_chatops/Resources/Private/Language/locallang.xlf:userSettings.enabled',
        'persistentUpdate' => true,
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 1,
        ],
    ],
    'after:--div--;LLL:EXT:webcon_workspace_chatops/Resources/Private/Language/locallang.xlf:userSettings.tab',
);

ExtensionManagementUtility::addUserSetting(
    'webconWorkspaceChatopsCanApproveFromChat',
    [
        'label' => 'LLL:EXT:webcon_workspace_chatops/Resources/Private/Language/locallang.xlf:userSettings.canApproveFromChat',
        'persistentUpdate' => true,
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 0,
        ],
    ],
    'after:webconWorkspaceChatopsEnabled',
);

ExtensionManagementUtility::addUserSetting(
    'webconWorkspaceChatopsSlackEnabled',
    [
        'label' => 'LLL:EXT:webcon_workspace_chatops/Resources/Private/Language/locallang.xlf:userSettings.slackEnabled',
        'persistentUpdate' => true,
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 1,
        ],
    ],
    'after:webconWorkspaceChatopsCanApproveFromChat',
);

ExtensionManagementUtility::addUserSetting(
    'webconWorkspaceChatopsSlackUserId',
    [
        'label' => 'LLL:EXT:webcon_workspace_chatops/Resources/Private/Language/locallang.xlf:userSettings.slackUserId',
        'description' => 'LLL:EXT:webcon_workspace_chatops/Resources/Private/Language/locallang.xlf:userSettings.slackUserId.description',
        'persistentUpdate' => true,
        'config' => [
            'type' => 'input',
            'size' => 24,
            'placeholder' => 'U012AB3CD',
            'nullable' => true,
        ],
    ],
    'after:webconWorkspaceChatopsSlackEnabled',
);

ExtensionManagementUtility::addUserSetting(
    'webconWorkspaceChatopsTeamsEnabled',
    [
        'label' => 'LLL:EXT:webcon_workspace_chatops/Resources/Private/Language/locallang.xlf:userSettings.teamsEnabled',
        'persistentUpdate' => true,
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 0,
        ],
    ],
    'after:webconWorkspaceChatopsSlackUserId',
);

ExtensionManagementUtility::addUserSetting(
    'webconWorkspaceChatopsTeamsUserId',
    [
        'label' => 'LLL:EXT:webcon_workspace_chatops/Resources/Private/Language/locallang.xlf:userSettings.teamsUserId',
        'description' => 'LLL:EXT:webcon_workspace_chatops/Resources/Private/Language/locallang.xlf:userSettings.teamsUserId.description',
        'persistentUpdate' => true,
        'config' => [
            'type' => 'input',
            'size' => 48,
            'placeholder' => '8:orgid:00000000-0000-0000-0000-000000000000',
            'nullable' => true,
        ],
    ],
    'after:webconWorkspaceChatopsTeamsEnabled',
);

ExtensionManagementUtility::addUserSetting(
    'webconWorkspaceChatopsWhatsappEnabled',
    [
        'label' => 'LLL:EXT:webcon_workspace_chatops/Resources/Private/Language/locallang.xlf:userSettings.whatsappEnabled',
        'persistentUpdate' => true,
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 0,
        ],
    ],
    'after:webconWorkspaceChatopsTeamsUserId',
);

ExtensionManagementUtility::addUserSetting(
    'webconWorkspaceChatopsWhatsappPhone',
    [
        'label' => 'LLL:EXT:webcon_workspace_chatops/Resources/Private/Language/locallang.xlf:userSettings.whatsappPhone',
        'description' => 'LLL:EXT:webcon_workspace_chatops/Resources/Private/Language/locallang.xlf:userSettings.whatsappPhone.description',
        'persistentUpdate' => true,
        'config' => [
            'type' => 'input',
            'size' => 24,
            'placeholder' => '+436641234567',
            'nullable' => true,
        ],
    ],
    'after:webconWorkspaceChatopsWhatsappEnabled',
);

foreach ([
    'webconWorkspaceChatopsNotifyReviewRequested' => 'userSettings.notifyReviewRequested',
    'webconWorkspaceChatopsNotifyPublished' => 'userSettings.notifyPublished',
    'webconWorkspaceChatopsNotifyScheduledPublication' => 'userSettings.notifyScheduledPublication',
    'webconWorkspaceChatopsNotifyDeploymentStatus' => 'userSettings.notifyDeploymentStatus',
    'webconWorkspaceChatopsNotifyIncident' => 'userSettings.notifyIncident',
] as $fieldName => $labelKey) {
    ExtensionManagementUtility::addUserSetting(
        $fieldName,
        [
            'label' => 'LLL:EXT:webcon_workspace_chatops/Resources/Private/Language/locallang.xlf:' . $labelKey,
            'persistentUpdate' => true,
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 1,
            ],
        ],
    );
}
