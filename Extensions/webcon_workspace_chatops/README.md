# Webcon Workspace ChatOps

Separate TYPO3 extension for connecting workspace approval workflows to
Slack, Microsoft Teams, WhatsApp, and MCP-style chat agents.

The extension does not replace TYPO3 Workspaces. It wraps TYPO3 Core
DataHandler commands so stage changes and publishing still use Core
permissions, history, notifications, and publish-stage checks.

## Features

- Public token-protected ChatOps API at `/webcon-chatops/api`
- DDEV/development bypass for unsigned local requests
- Slack incoming webhook notifications
- Microsoft Teams webhook/adaptive-card notifications
- WhatsApp Cloud API notifications
- Backend user settings for provider preferences and external identity mapping
- Workspace actions:
  - list pending workspace records
  - move selected records to an approval stage
  - move selected records to `Ready to publish` and publish them

## Installation

Install as a path package or move this directory into its own repository:

```bash
composer require webconsulting/webcon-workspace-chatops
bin/typo3 extension:setup
```

For local development in this repository, add it as a Composer path
repository in the TYPO3 project that should load it.

## Required workspace setup

Create a custom workspace and add a custom stage between `Editing` and
`Ready to publish`, for example `Chief editor approval`.

Set these extension configuration values:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['webcon_workspace_chatops'] = [
    'enabled' => true,
    'apiToken' => getenv('WEBCON_WORKSPACE_CHATOPS_API_TOKEN') ?: '',
    'approvalStageId' => 1,
    'defaultWorkspaceId' => 1,
    'slackEnabled' => true,
    'slackWebhookUrl' => getenv('WEBCON_WORKSPACE_CHATOPS_SLACK_WEBHOOK') ?: '',
    'teamsEnabled' => false,
    'teamsWebhookUrl' => getenv('WEBCON_WORKSPACE_CHATOPS_TEAMS_WEBHOOK') ?: '',
    'whatsappEnabled' => false,
    'whatsappPhoneNumberId' => getenv('WEBCON_WORKSPACE_CHATOPS_WHATSAPP_PHONE_NUMBER_ID') ?: '',
    'whatsappAccessToken' => getenv('WEBCON_WORKSPACE_CHATOPS_WHATSAPP_ACCESS_TOKEN') ?: '',
    'whatsappDefaultRecipients' => '',
];
```

## API examples

List pending records:

```bash
curl -s https://example.test/webcon-chatops/api \
  -H "Authorization: Bearer $WEBCON_WORKSPACE_CHATOPS_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"action":"workspace.pending","workspaceId":1,"pageUid":42}'
```

Request approval:

```bash
curl -s https://example.test/webcon-chatops/api \
  -H "Authorization: Bearer $WEBCON_WORKSPACE_CHATOPS_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "workspace.review.request",
    "workspaceId": 1,
    "backendUserId": 5,
    "comment": "Ready for chief editor approval",
    "records": [
      {"table": "pages", "workspaceUid": 123},
      {"table": "tt_content", "workspaceUid": 456}
    ]
  }'
```

Approve and publish:

```bash
curl -s https://example.test/webcon-chatops/api \
  -H "Authorization: Bearer $WEBCON_WORKSPACE_CHATOPS_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "action": "workspace.review.approve",
    "workspaceId": 1,
    "backendUserId": 7,
    "comment": "Approved in Slack",
    "records": [
      {"table": "pages", "workspaceUid": 123}
    ]
  }'
```

Production approval actions should resolve the actor through a connected
chat identity. In development context, unsigned requests can use the
configured development backend user.

## Documentation

See `Documentation/Index.rst` for the TYPO3 documentation entry point.
