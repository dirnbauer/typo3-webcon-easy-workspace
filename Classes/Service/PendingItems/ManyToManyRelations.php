<?php

declare(strict_types=1);

namespace Webconsulting\WebconEasyWorkspace\Service\PendingItems;

use Doctrine\DBAL\Exception as DbalException;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\AbstractInvalidDataStructureException;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Schema\Field\FlexFormFieldType;
use TYPO3\CMS\Core\Schema\Field\GroupFieldType;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use Webconsulting\WebconEasyWorkspace\Utility\Value;

/**
 * The relations a version keeps in an MM table, compared with its live
 * record's.
 *
 * A relation stored in an MM table (type=category, select or group with
 * `MM`, in a column or in a FlexForm) leaves only the number of relations in
 * the record's own column; the relations themselves are MM rows keyed by the
 * version's uid. Core treats them as part of the version: it moves them to
 * the live record on publish (DataHandler::versionPublishManyToManyRelations())
 * and deletes them on discard (DataHandler::discardMmRelations()). Swapping
 * one related record for another, or reordering them, leaves the version's
 * row identical to live — the change is in the MM rows only.
 *
 * The rows are read as core's RelationHandler reads them (readMM()): from the
 * record's side of the relation (uid_local, or uid_foreign for the opposite
 * side of a relation), with the field's match fields, in their sorting.
 * A relation list is ordered: a reorder is a change.
 *
 * Fail safe: a field whose relations cannot be read counts as changed, so no
 * destructive path treats the version as identical.
 */
final class ManyToManyRelations
{
    /** Uids per MM query (versions plus their live records). */
    private const int CHUNK = 500;

    /**
     * MM fields of a table's columns, per schema.
     *
     * @var \WeakMap<TcaSchema, array<string, array<string, mixed>>>
     */
    private \WeakMap $columnFields;

    /**
     * MM fields of a FlexForm data structure, by its identifier.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $flexFields = [];

    public function __construct(
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly ConnectionPool $connectionPool,
        private readonly FlexFormTools $flexFormTools,
    ) {
        $this->columnFields = new \WeakMap();
    }

    /**
     * Per version: the fields whose MM relations differ from its live
     * record's, each with a hash of the version's ordered relations (all
     * MM fields of a FlexForm together, under the FlexForm's column).
     *
     * Two versions whose relations core set to the same records carry the
     * same hash: a translation that synchronizes the field still mirrors its
     * source, a translator's own swap does not.
     *
     * @param array<int, array<string, mixed>> $versionRows Full version rows by uid; new records (t3ver_state 1) and versions without a live row are left out.
     * @param array<int, array<string, mixed>> $liveRows Full live rows by uid.
     * @return array<int, array<string, string>> version uid => field => sha1 of the version's relations
     */
    public function changedFields(string $table, array $versionRows, array $liveRows): array
    {
        $pairs = [];
        foreach ($versionRows as $uid => $row) {
            $liveUid = Value::int($row['t3ver_oid'] ?? null);
            if (Value::int($row['t3ver_state'] ?? null) !== 1 && $liveUid > 0 && isset($liveRows[$liveUid])) {
                $pairs[$uid] = $liveUid;
            }
        }
        if ($pairs === [] || !$this->tcaSchemaFactory->has($table)) {
            return [];
        }

        // Each relation set (an MM field: a column, or a FlexForm field of
        // one data structure) and, per uid and reported field, the sets that
        // apply to it — a FlexForm's depend on the row.
        $configs = [];
        $setsOf = [];
        foreach ($this->columnFields($table) as $field => $config) {
            $key = 'column:' . $field;
            $configs[$key] = $config;
            foreach ($pairs as $uid => $liveUid) {
                $setsOf[$uid][$field][] = $key;
                $setsOf[$liveUid][$field][] = $key;
            }
        }
        foreach ($this->flexColumns($table) as $field => $fieldConfig) {
            foreach ($pairs as $uid => $liveUid) {
                foreach ([$uid => $versionRows[$uid], $liveUid => $liveRows[$liveUid]] as $rowUid => $row) {
                    foreach ($this->flexMmFields($table, $field, $fieldConfig, $row) as $path => $config) {
                        $key = 'flex:' . $field . ':' . $path . ':' . sha1(serialize($config));
                        $configs[$key] = $config;
                        $setsOf[$rowUid][$field][] = $key;
                    }
                }
            }
        }
        if ($configs === []) {
            return [];
        }
        $uidsOf = [];
        foreach ($setsOf as $uid => $fields) {
            foreach ($fields as $keys) {
                foreach ($keys as $key) {
                    $uidsOf[$key][$uid] = $uid;
                }
            }
        }

        $lists = [];
        foreach ($configs as $key => $config) {
            $lists[$key] = $this->relations($table, $config, array_values($uidsOf[$key] ?? []));
        }

        $changed = [];
        foreach ($pairs as $uid => $liveUid) {
            $fields = array_unique([...array_keys($setsOf[$uid] ?? []), ...array_keys($setsOf[$liveUid] ?? [])]);
            foreach ($fields as $field) {
                $keys = array_unique([...$setsOf[$uid][$field] ?? [], ...$setsOf[$liveUid][$field] ?? []]);
                sort($keys);
                $version = [];
                $live = [];
                $unreadable = false;
                foreach ($keys as $key) {
                    if ($lists[$key] === null) {
                        $unreadable = true;
                        break;
                    }
                    $version[] = $key . '=' . implode(',', $lists[$key][$uid] ?? []);
                    $live[] = $key . '=' . implode(',', $lists[$key][$liveUid] ?? []);
                }
                if ($unreadable) {
                    // Never the same hash twice: such a version mirrors nothing.
                    $changed[$uid][$field] = sha1('unreadable|' . $table . ':' . $uid . '|' . $field);
                } elseif ($version !== $live) {
                    $changed[$uid][$field] = sha1(implode('|', $version));
                }
            }
        }

        return $changed;
    }

    /**
     * The MM fields among a table's columns, as core's publish finds them.
     *
     * @return array<string, array<string, mixed>> field => config
     */
    private function columnFields(string $table): array
    {
        $schema = $this->tcaSchemaFactory->get($table);
        if (!isset($this->columnFields[$schema])) {
            $fields = [];
            foreach ($schema->getFields() as $field) {
                $config = Value::stringKeyArray($field->getConfiguration());
                if (self::isMmRelation($config)) {
                    $fields[$field->getName()] = $config;
                }
            }
            $this->columnFields[$schema] = $fields;
        }

        return $this->columnFields[$schema];
    }

    /**
     * @return array<string, array<string, mixed>> field => config
     */
    private function flexColumns(string $table): array
    {
        $columns = [];
        foreach ($this->tcaSchemaFactory->get($table)->getFields() as $field) {
            if ($field instanceof FlexFormFieldType) {
                $columns[$field->getName()] = $field->getConfiguration();
            }
        }

        return $columns;
    }

    /**
     * The MM fields of the FlexForm data structure this row uses (sections
     * cannot hold relations).
     *
     * @param array<string, mixed> $fieldConfig
     * @param array<string, mixed> $row
     * @return array<string, array<string, mixed>> sheet/field => config
     */
    private function flexMmFields(string $table, string $field, array $fieldConfig, array $row): array
    {
        try {
            $schema = $this->tcaSchemaFactory->get($table);
            $identifier = $this->flexFormTools->getDataStructureIdentifier(['config' => $fieldConfig], $table, $field, $row, $schema);
            if (!isset($this->flexFields[$identifier])) {
                $fields = [];
                $dataStructure = $this->flexFormTools->parseDataStructureByIdentifier($identifier, $schema);
                foreach (is_array($dataStructure['sheets'] ?? null) ? $dataStructure['sheets'] : [] as $sheetName => $sheet) {
                    $elements = is_array($sheet) && is_array($sheet['ROOT']['el'] ?? null) ? $sheet['ROOT']['el'] : [];
                    foreach ($elements as $name => $element) {
                        $config = is_array($element) && ($element['type'] ?? '') !== 'array' && is_array($element['config'] ?? null)
                            ? Value::stringKeyArray($element['config'])
                            : [];
                        if (self::isMmRelation($config)) {
                            $fields[$sheetName . '/' . $name] = $config;
                        }
                    }
                }
                $this->flexFields[$identifier] = $fields;
            }
        } catch (AbstractInvalidDataStructureException|\InvalidArgumentException|\RuntimeException) {
            // No data structure: core's publish and discard find no MM
            // relations in it either.
            return [];
        }

        return $this->flexFields[$identifier];
    }

    /**
     * A relation field core keeps in an MM table (DataHandler::isReferenceField() with `MM`).
     *
     * @param array<string, mixed> $config
     */
    private static function isMmRelation(array $config): bool
    {
        $type = $config['type'] ?? null;

        return !empty($config['MM'])
            && is_string($config['MM'])
            && ($type === 'group' || (($type === 'select' || $type === 'category') && !empty($config['foreign_table'])));
    }

    /**
     * The ordered relations of these records in one MM field; null when
     * they cannot be read.
     *
     * @param array<string, mixed> $config
     * @param list<int> $uids
     * @return array<int, list<string>>|null uid => related records
     */
    private function relations(string $table, array $config, array $uids): ?array
    {
        $isForeignSide = !empty($config['MM_opposite_field']);
        $own = $isForeignSide ? 'uid_foreign' : 'uid_local';
        $other = $isForeignSide ? 'uid_local' : 'uid_foreign';
        $sorting = $isForeignSide ? 'sorting_foreign' : 'sorting';
        $mmTable = Value::string($config['MM'] ?? null);
        $tableWhere = is_string($config['MM_table_where'] ?? null) ? trim($config['MM_table_where']) : '';
        $allowed = Value::string($config['type'] === 'group' ? ($config['allowed'] ?? null) : ($config['foreign_table'] ?? null));
        $firstTable = $allowed === '*' ? '' : (explode(',', $allowed)[0] ?? '');

        $lists = [];
        try {
            // A where with the record's uid in it is asked per record.
            $batches = $tableWhere !== '' && str_contains($tableWhere, '###THIS_UID###')
                ? array_map(static fn(int $uid): array => [$uid], $uids)
                : array_chunk($uids, self::CHUNK);
            foreach ($batches as $batch) {
                $queryBuilder = $this->connectionPool->getQueryBuilderForTable($mmTable);
                $queryBuilder->getRestrictions()->removeAll();
                $queryBuilder->select('*')
                    ->from($mmTable)
                    ->where($queryBuilder->expr()->in($own, $queryBuilder->createNamedParameter($batch, Connection::PARAM_INT_ARRAY)))
                    ->orderBy($own)
                    ->addOrderBy($sorting)
                    ->addOrderBy($other);
                foreach (is_array($config['MM_match_fields'] ?? null) ? $config['MM_match_fields'] : [] as $matchField => $matchValue) {
                    $queryBuilder->andWhere($queryBuilder->expr()->eq((string)$matchField, $queryBuilder->createNamedParameter(Value::string($matchValue))));
                }
                if ($isForeignSide && $this->usesTablenames($config)) {
                    $queryBuilder->andWhere($queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($table)));
                }
                if ($tableWhere !== '') {
                    $queryBuilder->andWhere(QueryHelper::stripLogicalOperatorPrefix(str_replace(
                        '###THIS_UID###',
                        (string)$batch[0],
                        QueryHelper::quoteDatabaseIdentifiers($queryBuilder->getConnection(), $tableWhere),
                    )));
                }
                $result = $queryBuilder->executeQuery();
                while ($row = $result->fetchAssociative()) {
                    $relatedTable = $isForeignSide ? '' : (Value::string($row['tablenames'] ?? null) ?: $firstTable);
                    $lists[Value::int($row[$own] ?? null)][] = ($relatedTable !== '' ? $relatedTable . ':' : '') . Value::int($row[$other] ?? null);
                }
            }
        } catch (DbalException) {
            // An MM table that is not there (yet): unreadable, not empty.
            return null;
        }

        return $lists;
    }

    /**
     * Whether core reads the opposite side of this relation by table name
     * too (DataHandler::mmQueryShouldUseTablenamesColumn()).
     *
     * @param array<string, mixed> $config
     */
    private function usesTablenames(array $config): bool
    {
        if ($config['type'] === 'group') {
            $allowed = Value::string($config['allowed'] ?? null);
            if (!empty($config['prepend_tname']) || $allowed === '*' || str_contains($allowed, ',')) {
                return true;
            }
        }
        $localTable = Value::string($config['type'] === 'group' ? ($config['allowed'] ?? null) : ($config['foreign_table'] ?? null));
        $localField = Value::string($config['MM_opposite_field'] ?? null);
        if (!$this->tcaSchemaFactory->has($localTable) || !$this->tcaSchemaFactory->get($localTable)->hasField($localField)) {
            return false;
        }
        $field = $this->tcaSchemaFactory->get($localTable)->getField($localField);

        return $field instanceof GroupFieldType && ($field->allowsAllSchemata() || count($field->getAllowedSchemaNames()) > 1);
    }
}
