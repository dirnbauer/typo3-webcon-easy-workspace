.. _start:

========================
Webcon Workspace ChatOps
========================

Webcon Workspace ChatOps connects TYPO3 workspace approval workflows to
Slack, Microsoft Teams, WhatsApp, and MCP-style chat agents.

The extension keeps TYPO3 Workspaces as the source of truth. Stage
changes and publishing are executed through TYPO3 Core DataHandler
commands.

.. toctree::
    :maxdepth: 2
    :titlesonly:

    Configuration
    Api
    UserSettings

.. _features:

Features
========

* Token-protected JSON API for chat agents and automation tools
* Development-mode request bypass for local DDEV-style workflows
* Slack incoming webhook notifications
* Microsoft Teams adaptive-card webhook notifications
* WhatsApp Cloud API notifications
* Backend user settings for identity mapping and notification preferences
* Workspace actions for review requests and selected publishing

.. important::

    The extension does not grant publishing permission by itself.
    The resolved backend user still needs TYPO3 workspace and table
    permissions. DataHandler remains the enforcement layer.
