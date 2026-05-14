<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder;
use Webconsulting\WebconEasyWorkspace\Configuration\ConfigurationProvider;
use Webconsulting\WebconEasyWorkspace\Service\LatestChangesService;
use Webconsulting\WebconEasyWorkspace\Service\PendingItemsService;
use TYPO3\CMS\Backend\History\RecordHistoryRollback;
use Webconsulting\WebconEasyWorkspace\Service\PublishSelectedService;
use Webconsulting\WebconEasyWorkspace\Service\RecordDiffService;
use Webconsulting\WebconEasyWorkspace\Service\RecordHistoryTimelineService;

final readonly class EasyWorkspaceAjaxController
{
    /**
     * Tables the dropdown is allowed to operate on. Anything else is
     * silently rejected — protects against a crafted request passing
     * an arbitrary $TCA table (e.g. be_users, sys_log) through to
     * DataHandler. The UI itself only ever produces these three.
     */
    private const ALLOWED_TABLES = [
        'pages',
        'tt_content',
        'tx_news_domain_model_news',
    ];

    public function __construct(
        private PendingItemsService $pendingItemsService,
        private PublishSelectedService $publishService,
        private PreviewUriBuilder $previewUriBuilder,
        private ConfigurationProvider $configurationProvider,
        private LatestChangesService $latestChangesService,
        private RecordDiffService $recordDiffService,
        private ViewFactoryInterface $viewFactory,
        private BackendUriBuilder $backendUriBuilder,
        private RecordHistoryTimelineService $historyTimelineService,
        private RecordHistoryRollback $recordHistoryRollback,
    ) {}

    public function itemsAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $newsUid = (int)($query['newsUid'] ?? 0);
        $pageUid = (int)($query['pageUid'] ?? 0);
        $config = $this->configurationProvider->get($pageUid > 0 ? $pageUid : null);

        if (!$config['enabled']) {
            return new JsonResponse(['error' => 'Easy Workspace is disabled by TSconfig.'], 403);
        }

        $defaultMode = $config['defaultMode'];
        $requestedMode = (string)($query['mode'] ?? $defaultMode);
        $mode = $config['enableFilter']
            ? ($requestedMode === PendingItemsService::MODE_ALL ? PendingItemsService::MODE_ALL : PendingItemsService::MODE_CHANGED)
            : PendingItemsService::MODE_CHANGED;

        if ($newsUid > 0) {
            return new JsonResponse([
                'context' => 'news',
                ...$this->pendingItemsService->forNews($newsUid, $mode, $config),
            ]);
        }
        if ($pageUid > 0) {
            return new JsonResponse([
                'context' => 'page',
                ...$this->pendingItemsService->forPage($pageUid, $mode, $config),
            ]);
        }
        return new JsonResponse([
            'context' => 'none',
            'items' => [],
            'workspaceId' => 0,
            'mode' => $mode,
        ]);
    }

    /**
     * Cross-page "latest workspace changes" feed.
     *
     * Powers the lazy-loaded accordion at the bottom of the toolbar
     * dropdown — only invoked when the editor expands it, so the
     * common case (dropdown opened, accordion stays closed) costs
     * zero database round-trips.
     *
     * No page/news context is needed — the result is always scoped
     * to the editor's current workspace.
     */
    public function latestAction(ServerRequestInterface $request): ResponseInterface
    {
        $config = $this->configurationProvider->get(null);
        if (!$config['enabled']) {
            return new JsonResponse(['error' => 'Easy Workspace is disabled by TSconfig.'], 403);
        }

        $query = $request->getQueryParams();
        $requestedLimit = (int)($query['limit'] ?? LatestChangesService::DEFAULT_LIMIT);
        // Clamp to a sane range. 1 keeps degenerate ?limit=0 calls
        // from returning the entire workspace, 50 caps the response
        // size for the dropdown UI.
        $limit = max(1, min(50, $requestedLimit));

        return new JsonResponse($this->latestChangesService->list($limit, $config));
    }

    /**
     * Renders the field-level diff for a single workspace record as
     * a Fluid template and returns it as plain HTML. Consumed by
     * the dropdown's per-row "N changes" pill, which opens it inside
     * a TYPO3 backend Modal (Modal.advanced({type: 'ajax'})).
     *
     * Why a server-rendered HTML response and not JSON?
     *  - We can leverage TYPO3 core's DiffUtility::diff() to emit
     *    the same `<ins>`/`<del>` inline-diff format the standalone
     *    Workspaces module uses. Editors already know how to read
     *    that.
     *  - Fluid keeps the markup declarative, sandboxes the template
     *    file behind core's view factory, and avoids string
     *    concatenation in PHP.
     */
    public function diffAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $config = $this->configurationProvider->get(null);
        if (!$config['enabled']) {
            return new HtmlResponse('<p class="alert alert-danger">Easy Workspace is disabled by TSconfig.</p>', 403);
        }

        $table = (string)($query['table'] ?? '');
        $workspaceUid = (int)($query['workspaceUid'] ?? 0);
        if (!in_array($table, self::ALLOWED_TABLES, true) || $workspaceUid <= 0) {
            return new HtmlResponse('<p class="alert alert-danger">Invalid table or record id.</p>', 400);
        }

        $row = BackendUtility::getRecord($table, $workspaceUid);
        if (!is_array($row)) {
            return new HtmlResponse('<p class="alert alert-warning">Record not found or not accessible in this workspace.</p>', 404);
        }

        $payload = $this->recordDiffService->diffWithHtml($table, $row);
        $editUrl = null;
        $liveUid = $payload['liveUid'] ?: $workspaceUid;
        if ($liveUid > 0) {
            try {
                $editUrl = (string)$this->backendUriBuilder->buildUriFromRoute(
                    'record_edit',
                    [
                        'edit' => [$table => [$liveUid => 'edit']],
                        'returnUrl' => (string)($request->getServerParams()['HTTP_REFERER'] ?? ''),
                    ],
                    RouterInterface::ABSOLUTE_URL,
                );
            } catch (\Throwable) {
                $editUrl = null;
            }
        }

        // Per-record edit timeline from sys_history. Rendered as a
        // second tab in the modal so editors can scrub through every
        // workspace edit and roll back to any point. Empty list is
        // fine — the template handles it.
        $timeline = $this->historyTimelineService->build($table, $workspaceUid);

        $view = $this->viewFactory->create(new ViewFactoryData(
            templatePathAndFilename: 'EXT:webcon_easy_workspace/Resources/Private/Templates/Diff/Record.html',
            request: $request,
        ));
        $view->assignMultiple($payload + [
            'editUrl' => $editUrl,
            'timeline' => $timeline,
            'rollbackEnabled' => true,
        ]);

        return new HtmlResponse($view->render());
    }

    /**
     * Rollback a sys_history entry. Two modes:
     *
     *  - `mode=linear`   — undo this entry and every later edit on
     *                       this record (TYPO3 native "Rollback"
     *                       semantics).
     *  - `mode=field`    — undo just one field's change from this
     *                       entry. Later edits on other fields stay.
     *
     * Both delegate to core's RecordHistoryRollback::performRollback,
     * which composes a DataHandler cmdmap from the diff and runs it
     * — meaning all the normal data-integrity, hooks, and workspace
     * versioning kicks in. We don't bypass anything.
     */
    public function historyRollbackAction(ServerRequestInterface $request): ResponseInterface
    {
        $config = $this->configurationProvider->get(null);
        if (!$config['enabled']) {
            return new JsonResponse(['error' => 'Easy Workspace is disabled by TSconfig.'], 403);
        }

        $body = (array)($request->getParsedBody() ?? []);
        $table = (string)($body['table'] ?? '');
        $uid = (int)($body['uid'] ?? 0);
        $historyUid = (int)($body['historyUid'] ?? 0);
        $mode = (string)($body['mode'] ?? 'linear');
        $field = (string)($body['field'] ?? '');

        if (!in_array($table, self::ALLOWED_TABLES, true) || $uid <= 0 || $historyUid <= 0) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid arguments.'], 400);
        }
        if ($mode !== 'linear' && $mode !== 'field') {
            return new JsonResponse(['success' => false, 'error' => 'Unknown rollback mode.'], 400);
        }

        // performRollback's first arg is a "rollbackFields" selector:
        // either "ALL" or "table:uid:field" for a single field. The
        // diff array is the {<sys_history.uid>: {oldRecord, newRecord, …}}
        // structure RecordHistory::getDiff produces.
        $rollbackSelector = $mode === 'field' && $field !== ''
            ? sprintf('%s:%d:%s', $table, $uid, $field)
            : sprintf('%s:%d', $table, $uid);
        $historyService = new \TYPO3\CMS\Backend\History\RecordHistory(sprintf('%s:%d', $table, $uid));
        $historyService->setLastHistoryEntryNumber($historyUid);
        $diff = $historyService->getDiff($historyService->getChangeLog());

        // performRollback can throw for many reasons (DataHandler
        // refused a cmdmap, permission denied, broken sys_history
        // row, …). Surface the actual message to the editor by
        // returning 200 with success:false — TYPO3's AjaxRequest
        // throws on 4xx/5xx and swallows the body, so 500 here
        // would produce the JS "Unexpected error" fallback.
        try {
            if (empty($diff['insertsDeletes'] ?? null) && empty($diff['oldData'] ?? null)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Nothing to roll back at this entry. The history entry may be older than the live baseline, or the diff is empty.',
                ]);
            }
            $this->recordHistoryRollback->performRollback($rollbackSelector, $diff, $GLOBALS['BE_USER'] ?? null);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => sprintf('%s (%s:%d)', $e->getMessage(), basename($e->getFile()), $e->getLine()),
            ]);
        }

        return new JsonResponse(['success' => true, 'mode' => $mode, 'field' => $field]);
    }

    public function publishAction(ServerRequestInterface $request): ResponseInterface
    {
        // Mirror the gating the other endpoints do — without this the
        // toolbar item could be hidden by TSconfig (enabled = 0) but
        // the publish endpoint would still happily accept POSTs.
        $config = $this->configurationProvider->get();
        if (!$config['enabled']) {
            return new JsonResponse(['error' => 'Easy Workspace is disabled by TSconfig.'], 403);
        }

        $payload = $this->decodeBody($request);
        $rawSelections = $payload['selections'] ?? [];
        if (!is_array($rawSelections)) {
            return new JsonResponse(['error' => 'Invalid selections payload.'], 400);
        }

        $selections = [];
        foreach ($rawSelections as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $table = (string)($entry['table'] ?? '');
            $workspaceUid = (int)($entry['workspaceUid'] ?? 0);
            // Allow-list — keeps arbitrary TCA tables (be_users,
            // sys_log, …) out of the DataHandler cmdmap.
            if (!in_array($table, self::ALLOWED_TABLES, true) || $workspaceUid <= 0) {
                continue;
            }
            $selections[] = ['table' => $table, 'workspaceUid' => $workspaceUid];
        }

        return new JsonResponse($this->publishService->publish($selections));
    }

    public function discardAction(ServerRequestInterface $request): ResponseInterface
    {
        $payload = $this->decodeBody($request);
        $table = (string)($payload['table'] ?? '');
        $workspaceUid = (int)($payload['workspaceUid'] ?? 0);
        if (!in_array($table, self::ALLOWED_TABLES, true) || $workspaceUid <= 0) {
            return new JsonResponse(['error' => 'Missing or unsupported table / workspaceUid.'], 400);
        }
        $config = $this->configurationProvider->get();
        if (!$config['enabled']) {
            return new JsonResponse(['error' => 'Easy Workspace is disabled by TSconfig.'], 403);
        }
        if (!($config['enableRevert'] ?? true)) {
            return new JsonResponse(['error' => 'Revert is disabled by TSconfig.'], 403);
        }
        return new JsonResponse($this->publishService->discard($table, $workspaceUid));
    }

    public function previewLinkAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageUid = (int)($request->getQueryParams()['pageUid'] ?? 0);
        if ($pageUid <= 0) {
            return new JsonResponse(['error' => 'Missing pageUid.'], 400);
        }
        $config = $this->configurationProvider->get($pageUid);
        if (!$config['enablePreviewLink']) {
            return new JsonResponse(['error' => 'Preview link is disabled by TSconfig.'], 403);
        }
        try {
            $url = $this->previewUriBuilder->buildUriForPage($pageUid);
        } catch (\Throwable) {
            // Generic message to the client; the underlying exception
            // is already logged by Core's error handler.
            return new JsonResponse(['error' => 'Could not build a preview link for this page.'], 500);
        }
        return new JsonResponse(['url' => $url]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(ServerRequestInterface $request): array
    {
        if (str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            $body = (string)$request->getBody();
            if ($body === '') {
                return [];
            }
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : [];
        }
        $parsed = $request->getParsedBody();
        return is_array($parsed) ? $parsed : [];
    }
}
