.. _api:

API
===

The API accepts JSON ``POST`` requests at ``/webcon-chatops/api``.
Production requests must include:

.. code-block:: text
    :caption: Authorization header

    Authorization: Bearer <apiToken>

.. _api-actions:

Actions
========

``ping``
    Returns a health response.

``notify``
    Sends a generic notification to configured providers.

``workspace.pending``
    Lists workspace records for a workspace and optional page.

``workspace.review.request``
    Moves selected workspace records to the configured approval stage.

``workspace.review.approve``
    Moves selected workspace records to ``Ready to publish`` and publishes
    them through TYPO3 DataHandler.

.. _api-selection-format:

Selection format
================

Workspace actions accept a ``records`` array:

.. code-block:: json
    :caption: Selected workspace records

    [
      {"table": "pages", "workspaceUid": 123},
      {"table": "tt_content", "workspaceUid": 456}
    ]

The value is always the workspace version UID, not the live UID.

.. _api-actor-resolution:

Actor resolution
================

Production write actions should send either ``backendUserId`` or an
external chat identity:

.. code-block:: json
    :caption: External actor

    {
      "actor": {
        "provider": "slack",
        "externalId": "U123456"
      }
    }

The external ID is matched against backend user settings.
