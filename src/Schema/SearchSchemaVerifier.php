<?php

declare(strict_types=1);

namespace Thallo\Search\Schema;

use Glueful\Database\Connection;
use Glueful\Extensions\Schema\StructuralVerifierInterface;

/**
 * Structural verifier for glueful/thallo-search (schema policy spec B7): the create migration
 * proves the table it creates — and, on Postgres, the `tsv` column the engine cannot work
 * without, so a half-applied migration is never adopted as done.
 */
final class SearchSchemaVerifier implements StructuralVerifierInterface
{
    private const MIGRATION = '001_CreateSearchDocumentsTable.php';

    public function source(): string
    {
        return 'glueful/thallo-search';
    }

    /** @return list<string> */
    public function migrationBasenames(): array
    {
        return [self::MIGRATION];
    }

    public function verify(Connection $db, string $migrationBasename): bool
    {
        if ($migrationBasename !== self::MIGRATION) {
            return false;
        }
        $schema = $db->getSchemaBuilder();
        if (!$schema->hasTable('search_documents')) {
            return false;
        }
        return $db->getDriverName() !== 'pgsql' || $schema->hasColumn('search_documents', 'tsv');
    }
}
