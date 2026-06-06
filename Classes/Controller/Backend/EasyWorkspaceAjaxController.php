<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Backend\History\RecordHistory;
use TYPO3\CMS\Backend\History\RecordHistoryRollback;
use TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder;
use Webconsulting\WebconEasyWorkspace\Configuration\ConfigurationProvider;
use Webconsulting\WebconEasyWorkspace\Service\LocalizationService;
use Webconsulting\WebconEasyWorkspace\Service\PendingItemsService;
use Webconsulting\WebconEasyWorkspace\Service\PublishSelectedService;
use Webconsulting\WebconEasyWorkspace\Service\RecordDiffService;
use Webconsulting\WebconEasyWorkspace\Service\RecordHistoryTimelineService;
use Webconsulting\WebconEasyWorkspace\Utility\PublishSelectionNormalizer;
use Webconsulting\WebconEasyWorkspace\Utility\Value;
use Webconsulting\WebconEasyWorkspace\Utility\WorkspaceTablePolicy;

final readonly class EasyWorkspaceAjaxController
{
    /**
     * Primary tables the dropdown is allowed to operate on. Additional
     * inline child tables are accepted only when TCA marks them as
     * workspace-aware children of a workspace-aware parent.
     */
    public function __construct(
        private PendingItemsService $pendingItemsService,
        private PublishSelectedService $publishService,
        private PreviewUriBuilder $previewUriBuilder,
        private ConfigurationProvider $configurationProvider,
        private RecordDiffService $recordDiffService,
        private ViewFactoryInterface $viewFactory,
        private BackendUriBuilder $backendUriBuilder,
        private RecordHistoryTimelineService $historyTimelineService,
        private RecordHistoryRollback $recordHistoryRollback,
        private LocalizationService $localizationService,
        private WorkspaceTablePolicy $workspaceTablePolicy,
        private PublishSelectionNormalizer $publishSelectionNormalizer,
    ) {}

    public function itemsAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $newsUid = Value::int($query['newsUid'] ?? null);
        $pageUid = Value::int($query['pageUid'] ?? null);
        $languageUid = array_key_exists('languageUid', $query) ? Value::int($query['languageUid']) : null;
        $config = $this->configurationProvider->get($pageUid > 0 ? $pageUid : null);

        if (!$config['enabled']) {
            return new JsonResponse(['error' => $this->localizationService->translate('error.disabled')], 403);
        }

        $defaultMode = $config['defaultMode'];
        $requestedMode = Value::string($query['mode'] ?? $defaultMode);
        $viewMode = $config['enableFilter']
            ? ($requestedMode === PendingItemsService::MODE_ALL ? PendingItemsService::MODE_ALL : PendingItemsService::MODE_CHANGED)
            : PendingItemsService::MODE_CHANGED;
        // Always collect all records; Fluid renders both filter panels.
        $collectionMode = PendingItemsService::MODE_ALL;

        $collection = $this->pendingItemsService->toolbarCollectionForContext(
            $pageUid,
            $newsUid,
            $collectionMode,
            $config,
            $languageUid,
        );
        if ($collection['context'] === PendingItemsService::CONTEXT_NONE || $collection['payload'] === null) {
            return new JsonResponse([
                'context' => PendingItemsService::CONTEXT_NONE,
                'items' => [],
                'itemGroups' => [],
                'changedItemGroups' => [],
                'workspaceId' => 0,
                'mode' => $viewMode,
            ]);
        }

        $context = $collection['context'];
        $payload = $collection['payload'];

        return new JsonResponse([
            'context' => $context,
            ...$payload->toToolbarClientArray($context, includeDiff: false),
        ]);
    }

    public function hasChangesAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $newsUid = Value::int($query['newsUid'] ?? null);
        $pageUid = Value::int($query['pageUid'] ?? null);
        $languageUid = array_key_exists('languageUid', $query) ? Value::int($query['languageUid']) : null;
        $config = $this->configurationProvider->get($pageUid > 0 ? $pageUid : null);

        if (!$config['enabled']) {
            return new JsonResponse(['error' => $this->localizationService->translate('error.disabled')], 403);
        }

        return new JsonResponse(
            $this->pendingItemsService->hasChangesForContext($pageUid, $newsUid, $config, $languageUid),
        );
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
            return new HtmlResponse('<p class="alert alert-danger">' . htmlspecialchars($this->localizationService->translate('error.disabled')) . '</p>', 403);
        }

        $table = Value::string($query['table'] ?? null);
        $workspaceUid = Value::int($query['workspaceUid'] ?? null);
        if (!$this->workspaceTablePolicy->isAllowed($table) || $workspaceUid <= 0) {
            return new HtmlResponse('<p class="alert alert-danger">' . htmlspecialchars($this->localizationService->translate('error.invalidRecord')) . '</p>', 400);
        }

        $row = BackendUtility::getRecord($table, $workspaceUid);
        if (!is_array($row)) {
            return new HtmlResponse('<p class="alert alert-warning">' . htmlspecialchars($this->localizationService->translate('error.recordNotFound')) . '</p>', 404);
        }

        $payload = $this->recordDiffService->diffWithHtml($table, Value::stringKeyArray($row));
        $returnUrl = Value::string($request->getServerParams()['HTTP_REFERER'] ?? null);
        $editUrl = null;
        $liveUid = $payload['liveUid'] ?: $workspaceUid;
        if ($liveUid > 0) {
            try {
                $editUrl = (string)$this->backendUriBuilder->buildUriFromRoute(
                    'record_edit',
                    [
                        'edit' => [$table => [$liveUid => 'edit']],
                        'returnUrl' => $returnUrl,
                    ],
                    RouterInterface::ABSOLUTE_URL,
                );
            } catch (\Throwable) {
                $editUrl = null;
            }
        }

        // Timeline is built from TYPO3 core's RecordHistory service.
        // The Fluid template renders it without embedding the full
        // standalone record_history module chrome in the modal.
        $timeline = $this->historyTimelineService->build($table, $workspaceUid);
        $pageUid = $this->resolveContainingPageUid($table, Value::stringKeyArray($row), $payload);
        $pageTimeline = $pageUid > 0 ? $this->historyTimelineService->buildPage($pageUid) : [];

        $view = $this->viewFactory->create(new ViewFactoryData(
            templatePathAndFilename: 'EXT:webcon_easy_workspace/Resources/Private/Templates/Diff/Record.html',
            request: $request,
        ));
        $view->assignMultiple($payload + [
            'editUrl' => $editUrl,
            'timeline' => $timeline,
            'pageTimeline' => $pageTimeline,
            'rollbackEnabled' => true,
            'labels' => [
                'workspaceUid' => $this->localizationService->translate('diff.template.workspaceUid', ['uid' => $payload['workspaceUid']]),
                'liveUid' => $this->localizationService->translate('diff.template.liveUid', ['uid' => $payload['liveUid']]),
                'historyCount' => $this->localizationService->translate('history.editCount', ['count' => count($timeline)]),
                'recordHistory' => $this->localizationService->translate('history.tab.record'),
                'pageHistory' => $this->localizationService->translate('history.tab.page'),
                'historyAria' => $this->localizationService->translate('history.aria'),
                'rollbackLinearTitle' => $this->localizationService->translate('history.rollback.linearTitle'),
                'rollbackLinear' => $this->localizationService->translate('history.rollback.linear'),
                'rollbackFieldTitle' => $this->localizationService->translate('history.rollback.fieldTitle'),
                'rollbackFieldAria' => $this->localizationService->translate('history.rollback.fieldAria'),
                'empty' => $this->localizationService->translate('history.empty'),
                'openEditor' => $this->localizationService->translate('history.openEditor'),
            ],
        ]);

        return new HtmlResponse($view->render());
    }

    /**
     * @param array<string, mixed> $row
     * @param array{liveUid: int, workspaceUid: int} $payload
     */
    private function resolveContainingPageUid(string $table, array $row, array $payload): int
    {
        if ($table === 'pages') {
            return Value::int($payload['liveUid'] ?? null) ?: Value::int($payload['workspaceUid'] ?? null);
        }

        return Value::int($row['pid'] ?? null);
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
            return new JsonResponse(['error' => $this->localizationService->translate('error.disabled')], 403);
        }

        // JS posts Content-Type: application/json — PSR-7's
        // getParsedBody only auto-parses application/x-www-form-urlencoded,
        // so we need the decodeBody helper to read the JSON body.
        $body = $this->decodeBody($request);
        $table = Value::string($body['table'] ?? null);
        $uid = Value::int($body['uid'] ?? null);
        $historyUid = Value::int($body['historyUid'] ?? null);
        $mode = Value::string($body['mode'] ?? 'linear');
        $field = Value::string($body['field'] ?? null);

        if (!$this->workspaceTablePolicy->isAllowed($table) || $uid <= 0 || $historyUid <= 0) {
            return new JsonResponse(['success' => false, 'error' => $this->localizationService->translate('error.invalidArguments')], 400);
        }
        if ($mode !== 'linear' && $mode !== 'field') {
            return new JsonResponse(['success' => false, 'error' => $this->localizationService->translate('error.unknownRollbackMode')], 400);
        }

        // performRollback's first arg is a "rollbackFields" selector:
        // either "ALL" or "table:uid:field" for a single field. The
        // diff array is the {<sys_history.uid>: {oldRecord, newRecord, …}}
        // structure RecordHistory::getDiff produces.
        //
        // Everything that touches sys_history / DataHandler goes in
        // the try block: getChangeLog reads the sys_history table,
        // getDiff materializes the cmdmap, performRollback runs it.
        // Any of them can throw — and a 500 escape would just be
        // surfaced as a generic "Unexpected error" because TYPO3's
        // AjaxRequest swallows 5xx response bodies.
        try {
            $rollbackSelector = $mode === 'field' && $field !== ''
                ? sprintf('%s:%d:%s', $table, $uid, $field)
                : sprintf('%s:%d', $table, $uid);
            $historyService = new RecordHistory(sprintf('%s:%d', $table, $uid));
            $historyService->setLastHistoryEntryNumber($historyUid);
            $diff = $historyService->getDiff($historyService->getChangeLog());
            if (empty($diff['insertsDeletes'] ?? null) && empty($diff['oldData'] ?? null)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => $this->localizationService->translate('error.nothingToRollback'),
                ]);
            }
            $backendUser = ($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication ? $GLOBALS['BE_USER'] : null;
            $this->recordHistoryRollback->performRollback($rollbackSelector, $diff, $backendUser);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $this->localizationService->translate('error.rollbackFailed'),
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
            return new JsonResponse(['error' => $this->localizationService->translate('error.disabled')], 403);
        }

        $payload = $this->decodeBody($request);
        $rawSelections = $payload['selections'] ?? [];
        if (!is_array($rawSelections)) {
            return new JsonResponse(['error' => $this->localizationService->translate('error.invalidSelections')], 400);
        }

        $selections = $this->publishSelectionNormalizer->fromAjaxJson($rawSelections);
        if ($selections === []) {
            return new JsonResponse([
                'success' => false,
                'published' => 0,
                'errors' => [$this->localizationService->translate('error.noPublishableRecords')],
            ]);
        }

        return new JsonResponse($this->publishService->publish($selections));
    }

    public function discardAction(ServerRequestInterface $request): ResponseInterface
    {
        $payload = $this->decodeBody($request);
        $table = Value::string($payload['table'] ?? null);
        $workspaceUid = Value::int($payload['workspaceUid'] ?? null);
        if (!$this->workspaceTablePolicy->isAllowed($table) || $workspaceUid <= 0) {
            return new JsonResponse(['error' => $this->localizationService->translate('error.missingTableWorkspace')], 400);
        }
        $config = $this->configurationProvider->get();
        if (!$config['enabled']) {
            return new JsonResponse(['error' => $this->localizationService->translate('error.disabled')], 403);
        }
        if (!$config['enableRevert']) {
            return new JsonResponse(['error' => $this->localizationService->translate('error.revertDisabled')], 403);
        }
        return new JsonResponse($this->publishService->discard($table, $workspaceUid));
    }

    public function previewLinkAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageUid = Value::int($request->getQueryParams()['pageUid'] ?? null);
        if ($pageUid <= 0) {
            return new JsonResponse(['error' => $this->localizationService->translate('error.missingPageUid')], 400);
        }
        $config = $this->configurationProvider->get($pageUid);
        if (!$config['enablePreviewLink']) {
            return new JsonResponse(['error' => $this->localizationService->translate('error.previewLinkDisabled')], 403);
        }
        try {
            $url = $this->previewUriBuilder->buildUriForPage($pageUid);
        } catch (\Throwable) {
            // Generic message to the client; the underlying exception
            // is already logged by Core's error handler.
            return new JsonResponse(['error' => $this->localizationService->translate('error.previewLinkBuild')], 500);
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
            return Value::stringKeyArray($decoded);
        }
        $parsed = $request->getParsedBody();
        return Value::stringKeyArray($parsed);
    }
}
