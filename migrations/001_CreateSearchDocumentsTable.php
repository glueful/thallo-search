<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * The Postgres full-text engine's index (Engine\PostgresFtsBackend): one row per published
 * entry+locale, holding what the search API returns and a `tsvector` of what it matches.
 *
 * The table is created on every driver — the search pack's schema is the same everywhere — but
 * the generated `tsv` column and its GIN index are Postgres's, added only there; on another driver the
 * Postgres engine is not offered and the table stays empty.
 */
final class CreateSearchDocumentsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('search_documents')) {
            return;
        }
        $schema->createTable('search_documents', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            // "{entryUuid}_{locale}" (DocumentBuilder::documentId) — what an upsert replaces.
            $table->string('doc_id', 64);
            $table->string('entry_uuid', 32);
            $table->string('locale', 12);
            $table->string('content_type_uuid', 32);
            $table->string('content_type_slug', 191);
            $table->string('href', 1024);
            $table->text('title');
            $table->text('body');
            // The text-search configuration the row was indexed with (english, french, simple…):
            // a query is parsed with the SAME one, or stems would not meet.
            $table->string('ts_config', 32);
            $table->timestamp('updated_at')->nullable();
            $table->unique(['doc_id'], 'uniq_search_documents_doc');
            $table->index(['entry_uuid'], 'idx_search_documents_entry');
            $table->index(['locale', 'content_type_uuid'], 'idx_search_documents_scope');
        });

        if ($schema->getConnection()->getDriverName() === 'pgsql') {
            // `ts_config` becomes a real regconfig so that the vector can be a GENERATED column:
            // to_tsvector(regconfig, text) is immutable, a text→regconfig cast is not. Postgres
            // then keeps `tsv` itself — title weighted above body — and every write is a plain
            // row write through the query builder, with nothing to compute and nothing raw.
            $schema->addPendingOperation(
                'ALTER TABLE search_documents ALTER COLUMN ts_config TYPE regconfig USING ts_config::regconfig',
            );
            $schema->addPendingOperation(
                // Each text twice: in the row's language, where words meet by their STEM, and in
                // `simple`, where they are kept as written — what a prefix typed into a search box
                // ("them" for "theming", which English stems to "theme" and whose "them" is a
                // stop word) can be matched against.
                "ALTER TABLE search_documents ADD COLUMN tsv tsvector GENERATED ALWAYS AS ("
                . "setweight(to_tsvector(ts_config, coalesce(title, '')), 'A') || "
                . "setweight(to_tsvector('simple', coalesce(title, '')), 'A') || "
                . "setweight(to_tsvector(ts_config, coalesce(body, '')), 'B') || "
                . "setweight(to_tsvector('simple', coalesce(body, '')), 'B')) STORED",
            );
            $schema->addPendingOperation(
                'CREATE INDEX idx_search_documents_tsv ON search_documents USING GIN (tsv)',
            );
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('search_documents');
    }

    public function getDescription(): string
    {
        return 'Create search_documents (the Postgres full-text search index).';
    }
}
