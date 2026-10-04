<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * The search index's lifecycle (search block spec §3.3, §3.5.1). Documents gain their result kind,
 * source id, subtype, display meta and the generation that wrote them. Four tables, each owned by
 * a workspace, hold the per-kind state (generations, the build claim and lease, the drainer's
 * lease), the change journal, the acknowledgement of each journal entry by
 * each physical target, and the rebuild demand.
 *
 * Forward-only for `search_documents`: down() drops the four tables and leaves the document
 * columns, which existing rows may already use.
 */
final class SearchIndexLifecycle implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('search_documents') && !$schema->hasColumn('search_documents', 'kind')) {
            // The documents written before kinds existed are not carried over: every kind is
            // rebuilt from its source once the lifecycle runs.
            $schema->addPendingOperation('DELETE FROM search_documents');
            $schema->alterTable('search_documents', function ($table): void {
                $table->string('kind', 16)->nullable();
                $table->string('source_id', 64)->nullable();
                $table->string('subtype', 64)->nullable();
                $table->text('meta')->nullable();
                $table->bigInteger('generation')->default(0);
                $table->index(['kind', 'generation'], 'idx_search_documents_kind_gen');
            });
            if ($schema->getConnection()->getDriverName() === 'pgsql') {
                // A v2 id is up to 95 bytes, and a product row has no entry or content type.
                $schema->addPendingOperation('ALTER TABLE search_documents ALTER COLUMN doc_id TYPE varchar(128)');
                foreach (['entry_uuid', 'content_type_uuid', 'content_type_slug'] as $column) {
                    $schema->addPendingOperation("ALTER TABLE search_documents ALTER COLUMN {$column} DROP NOT NULL");
                }
            }
        }

        if (!$schema->hasTable('search_index_state')) {
            $schema->createTable('search_index_state', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('kind', 16);
                $table->bigInteger('generation')->default(0);
                $table->bigInteger('generation_counter')->default(0);
                $table->string('active_target', 191)->nullable();
                $table->bigInteger('building_generation')->nullable();
                $table->string('building_target', 191)->nullable();
                $table->string('owner_token', 32)->nullable();
                $table->string('lease_until', 32)->nullable();
                $table->string('cursor', 64)->nullable();
                $table->bigInteger('journal_start_seq')->nullable();
                $table->bigInteger('demand_seq_at_start')->nullable();
                $table->bigInteger('satisfied_seq')->default(0);
                $table->bigInteger('reconciled_version')->default(0);
                $table->integer('schema_version')->default(0);
                $table->string('status', 16)->default('pending');
                $table->integer('processed')->default(0);
                $table->integer('documents')->default(0);
                $table->string('last_success_at', 32)->nullable();
                $table->text('last_error')->nullable();
                $table->string('drainer_token', 32)->nullable();
                $table->string('drainer_lease_until', 32)->nullable();
                $table->bigInteger('journal_head')->default(0);
                $table->text('retired_targets')->nullable();
                $table->string('locked_at', 32)->nullable();
                $table->string('updated_at', 32)->nullable();
                $table->unique(['kind'], 'uniq_search_index_state_kind');
            });
        }

        if (!$schema->hasTable('search_index_changes')) {
            $schema->createTable('search_index_changes', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('kind', 16);
                $table->string('source_id', 64);
                $table->bigInteger('seq');
                $table->integer('resolved')->default(0);
                $table->string('failed_at', 32)->nullable();
                $table->text('error')->nullable();
                $table->string('created_at', 32);
                $table->unique(['kind', 'seq'], 'uniq_search_index_changes_seq');
                $table->index(['kind', 'resolved'], 'idx_search_index_changes_open');
            });
        }

        if (!$schema->hasTable('search_index_acks')) {
            $schema->createTable('search_index_acks', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('kind', 16);
                $table->bigInteger('entry_seq');
                $table->string('target', 191);
                $table->string('task_uid', 191)->nullable();
                $table->string('status', 12);
                $table->string('writer_token', 32);
                $table->string('updated_at', 32);
                $table->unique(['kind', 'entry_seq', 'target'], 'uniq_search_index_acks_target');
            });
        }

        if (!$schema->hasTable('search_index_demand')) {
            $schema->createTable('search_index_demand', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('kind', 16);
                $table->bigInteger('seq');
                $table->string('reason', 24);
                $table->string('created_at', 32);
                $table->unique(['kind', 'seq'], 'uniq_search_index_demand_seq');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        foreach (['search_index_demand', 'search_index_acks', 'search_index_changes', 'search_index_state'] as $table) {
            $schema->dropTableIfExists($table);
        }
    }

    public function getDescription(): string
    {
        return 'Add the search index lifecycle: document kind/source/generation, state, journal, acks, demand.';
    }
}
