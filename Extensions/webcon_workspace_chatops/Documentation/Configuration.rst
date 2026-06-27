.. _configuration:

Configuration
=============

Administrator configuration is read from
``$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['webcon_workspace_chatops']``.
Secrets can also be supplied through environment variables.

.. confval:: enabled
    :name: webcon-workspace-chatops-enabled
    :type: bool
    :default: true

    Enables the ChatOps API and notification dispatcher.

.. confval:: apiPath
    :name: webcon-workspace-chatops-api-path
    :type: string
    :default: /webcon-chatops/api

    Public endpoint path handled by the frontend middleware.

.. confval:: apiToken
    :name: webcon-workspace-chatops-api-token
    :type: string
    :default: empty

    Bearer token required for production API calls. If empty, unsigned
    requests are accepted only in development context when explicitly
    allowed.

.. confval:: allowUnsignedDevelopmentRequests
    :name: webcon-workspace-chatops-dev-bypass
    :type: bool
    :default: true

    Allows unsigned API calls in TYPO3 development context. Disable this
    in shared staging systems.

.. confval:: developmentBackendUserId
    :name: webcon-workspace-chatops-dev-user
    :type: int
    :default: 1

    Backend user used for unsigned development requests.

.. confval:: defaultWorkspaceId
    :name: webcon-workspace-chatops-default-workspace
    :type: int
    :default: 0

    Fallback workspace for API calls that do not specify a workspace.

.. confval:: approvalStageId
    :name: webcon-workspace-chatops-approval-stage
    :type: int
    :default: 1

    Custom workspace stage used for chief editor approval.

.. confval:: publishStageId
    :name: webcon-workspace-chatops-publish-stage
    :type: int
    :default: -10

    TYPO3's internal ``Ready to publish`` stage.

.. confval:: slackWebhookUrl
    :name: webcon-workspace-chatops-slack-webhook
    :type: string
    :default: environment WEBCON_WORKSPACE_CHATOPS_SLACK_WEBHOOK

    Slack incoming webhook URL.

.. confval:: teamsWebhookUrl
    :name: webcon-workspace-chatops-teams-webhook
    :type: string
    :default: environment WEBCON_WORKSPACE_CHATOPS_TEAMS_WEBHOOK

    Microsoft Teams webhook URL. Prefer the current Teams webhook or
    Workflow URL instead of legacy Office 365 connectors.

.. confval:: whatsappPhoneNumberId
    :name: webcon-workspace-chatops-whatsapp-phone-number-id
    :type: string
    :default: environment WEBCON_WORKSPACE_CHATOPS_WHATSAPP_PHONE_NUMBER_ID

    WhatsApp Cloud API phone number ID.

.. confval:: whatsappAccessToken
    :name: webcon-workspace-chatops-whatsapp-access-token
    :type: string
    :default: environment WEBCON_WORKSPACE_CHATOPS_WHATSAPP_ACCESS_TOKEN

    WhatsApp Cloud API bearer token.

.. confval:: whatsappDefaultRecipients
    :name: webcon-workspace-chatops-whatsapp-recipients
    :type: string
    :default: empty

    Comma-separated phone numbers for WhatsApp notifications.
