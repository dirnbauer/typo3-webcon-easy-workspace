# TYPO3 Security Report

**Generated:** 2026-05-16 21:18:15 Europe/Vienna
**Skill:** `/typo3-security`

## Findings

- State-changing AJAX endpoints relied on backend route tokens but did not
  advertise POST-only methods in route configuration.
- Security scanner reported generic CSRF/security-header warnings because
  it does not understand TYPO3 backend route tokens.
- Existing code already used TYPO3 backend route URLs via
  `TYPO3.settings.ajaxUrls`.
- Existing controller and service logic already enforced table allow-lists,
  workspace ownership and server-side TSconfig gates.

## Suggested Changes

- Declare explicit route methods for all backend AJAX endpoints.
- Document TYPO3 route-token behavior in `SECURITY.md`.
- Keep using TYPO3 `DataHandler`, route `UriBuilder` and backend AJAX APIs.
