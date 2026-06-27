.. _user-settings:

User settings
=============

The extension adds a ``Workspace ChatOps`` tab to backend user settings.

Users can configure:

* whether ChatOps notifications are enabled
* whether approval actions are allowed from connected chat accounts
* Slack user ID
* Microsoft Teams user ID
* WhatsApp phone number
* event preferences for approval, publishing, scheduled publication,
  deployment status, and monitoring alerts

Identity examples:

* Slack user ID: ``U012AB3CD``
* Microsoft Teams user ID: ``8:orgid:00000000-0000-0000-0000-000000000000``
  or the Azure AD object ID used by the connector mapping
* WhatsApp phone number: ``+436641234567`` in E.164 format

.. important::

    The approval toggle is only an additional opt-in. It does not bypass
    TYPO3 workspace permissions, table permissions, page permissions, or
    publish-stage restrictions.
