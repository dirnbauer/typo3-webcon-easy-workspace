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

Requires PHP 8.2+ and TYPO3 14.3.6+. This optional package is not installed
automatically with Easy Workspace and is not published on Packagist.
Add this directory as a path repository in the host TYPO3 project (adjust
the path to your checkout):

```bash
composer config repositories.webcon-workspace-chatops path ../typo3-webcon-easy-workspace/Extensions/webcon_workspace_chatops
composer require webconsulting/webcon-workspace-chatops:@dev
vendor/bin/typo3 extension:setup
vendor/bin/typo3 cache:flush
```

Once installed, the API is enabled by default. Configure authentication
before using the examples below; production requests require a bearer token.

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
