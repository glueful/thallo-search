<?php

declare(strict_types=1);

namespace Thallo\Search\Schema;

use Glueful\Database\Connection;
use Glueful\Extensions\Schema\StructuralVerifierInterface;

/**
 * Structural verifier for glueful/thallo-search (schema policy spec B7). The create migration
 * proves the table it creates — and, on Postgres, the `tsv` column the engine cannot work
 * without — and the lifecycle migration proves every table it creates and the document column it
 * adds, so a half-applied migration is never adopted as done. Unknown basenames never are.
 */
final class SearchSchemaVerifier implements StructuralVerifierInterface
{
    /** @var array<string, list<string>> create migration => every table it creates */
    private const CREATED_TABLES = [
        '001_CreateSearchDocumentsTable.php' => ['search_documents'],
        '002_SearchIndexLifecycle.php' => [
            'search_index_state', 'search_index_changes', 'search_index_acks', 'search_index_demand',
        ],
    ];

    /** @var array<string, array{0: string, 1: string}> migration => [table, column] it adds */
    private const ADDED_COLUMNS = [
        '002_SearchIndexLifecycle.php' => ['search_documents', 'generation'],
    ];

    public function source(): string
    {
        return 'glueful/thallo-search';
    }

    /** @return list<string> */
    public function migrationBasenames(): array
    {
        $names = array_keys(self::CREATED_TABLES);
        sort($names);
        return $names;
    }

    public function verify(Connection $db, string $migrationBasename): bool
    {
        if (!isset(self::CREATED_TABLES[$migrationBasename])) {
            return false;
        }
        $schema = $db->getSchemaBuilder();
        foreach (self::CREATED_TABLES[$migrationBasename] as $table) {
            if (!$schema->hasTable($table)) {
                return false;
            }
        }
        if (isset(self::ADDED_COLUMNS[$migrationBasename])) {
            [$table, $column] = self::ADDED_COLUMNS[$migrationBasename];
            if (!$schema->hasColumn($table, $column)) {
                return false;
            }
        }
        if ($migrationBasename === '001_CreateSearchDocumentsTable.php') {
            return $db->getDriverName() !== 'pgsql' || $schema->hasColumn('search_documents', 'tsv');
        }
        return true;
    }
}
