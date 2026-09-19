<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service;

use TYPO3\CMS\Core\Schema\Capability\FieldCapability;
use TYPO3\CMS\Core\Schema\Capability\SystemInternalFieldCapability;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Schema lookups for the system fields workspace queries are built on.
 *
 * TYPO3 v14 no longer exposes `t3ver_*`, `deleted` or `tstamp` as TCA
 * `columns`; they are schema capabilities. Guarding a query with
 * `TcaUtility::hasColumn($table, 't3ver_wsid')` is therefore always false,
 * which silently disables the guarded query — and a dropped `deleted = 0`
 * constraint silently widens it. Every such check goes through this
 * service; `TcaUtility::hasColumn()` stays for real TCA columns
 * (`sys_language_uid`, `l10n_parent`, sort fields, relation fields).
 *
 * The schema's own field collection omits the `t3ver_*` fields as well, so
 * `isWorkspaceAware()` — not a field lookup — is what tells a query whether
 * they exist on the table.
 */
final readonly class RecordSchemaInspector
{
    public function __construct(private TcaSchemaFactory $tcaSchemaFactory) {}

    /**
     * True when the table carries workspace versions (`t3ver_wsid`,
     * `t3ver_oid`, `t3ver_state`).
     */
    public function isWorkspaceAware(string $table): bool
    {
        return $this->tcaSchemaFactory->has($table)
            && $this->tcaSchemaFactory->get($table)->isWorkspaceAware();
    }

    /**
     * The soft-delete field of the table, or null when rows are deleted
     * for real.
     */
    public function softDeleteField(string $table): ?string
    {
        return $this->capabilityField($table, TcaSchemaCapability::SoftDelete);
    }

    /**
     * The "changed at" timestamp field, or null when the table has none.
     */
    public function updatedAtField(string $table): ?string
    {
        return $this->capabilityField($table, TcaSchemaCapability::UpdatedAt);
    }

    private function capabilityField(string $table, TcaSchemaCapability $capability): ?string
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return null;
        }
        $schema = $this->tcaSchemaFactory->get($table);
        if (!$schema->hasCapability($capability)) {
            return null;
        }
        $fieldCapability = $schema->getCapability($capability);

        return $fieldCapability instanceof SystemInternalFieldCapability || $fieldCapability instanceof FieldCapability
            ? $fieldCapability->getFieldName()
            : null;
    }
}
