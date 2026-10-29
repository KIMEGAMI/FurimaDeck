<?php

namespace Tests\Feature;

use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccountingEntrySyncMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        DB::purge('furimadeck');

        Schema::connection('furimadeck')->create('accounting_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('sync_status');
            $table->string('external_transaction_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('furimadeck')->create('accounting_sync_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
        });
        Schema::connection('furimadeck')->create('accounting_sync_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('accounting_entry_id');
            $table->string('status');
        });
    }

    protected function tearDown(): void
    {
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_migration_preserves_ambiguous_legacy_sync_and_recognizes_known_money_forward_batch(): void
    {
        $connection = DB::connection('furimadeck');
        $now = now();
        $knownMoneyForwardId = $connection->table('accounting_entries')->insertGetId([
            'sync_status' => 'synced',
            'external_transaction_id' => 'legacy-mf-17',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $unknownProviderId = $connection->table('accounting_entries')->insertGetId([
            'sync_status' => 'synced',
            'external_transaction_id' => 'legacy-unknown-18',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $unsentId = $connection->table('accounting_entries')->insertGetId([
            'sync_status' => 'not_synced',
            'external_transaction_id' => null,
            'synced_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $batchId = $connection->table('accounting_sync_batches')->insertGetId(['provider' => 'money_forward']);
        $connection->table('accounting_sync_batch_items')->insert([
            'batch_id' => $batchId,
            'accounting_entry_id' => $knownMoneyForwardId,
            'status' => 'succeeded',
        ]);

        $migration = require base_path('database/migrations/furimadeck/2026_10_09_000300_create_accounting_entry_syncs.php');
        $migration->up();

        $this->assertDatabaseHas('accounting_entry_syncs', [
            'accounting_entry_id' => $knownMoneyForwardId,
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'legacy-mf-17',
        ], 'furimadeck');
        $this->assertDatabaseHas('accounting_entry_syncs', [
            'accounting_entry_id' => $knownMoneyForwardId,
            'provider' => 'freee',
            'status' => 'legacy_unknown',
        ], 'furimadeck');
        foreach (['freee', 'money_forward'] as $provider) {
            $this->assertDatabaseHas('accounting_entry_syncs', [
                'accounting_entry_id' => $unknownProviderId,
                'provider' => $provider,
                'status' => 'legacy_unknown',
            ], 'furimadeck');
            $this->assertDatabaseHas('accounting_entry_syncs', [
                'accounting_entry_id' => $unsentId,
                'provider' => $provider,
                'status' => 'not_synced',
            ], 'furimadeck');
        }
        $this->assertSame('legacy-unknown-18', $connection->table('accounting_entries')->where('id', $unknownProviderId)->value('external_transaction_id'));
    }

    public function test_external_import_id_migration_preserves_existing_provider_sync_records(): void
    {
        Schema::connection('furimadeck')->create('accounting_entry_syncs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('accounting_entry_id');
            $table->string('provider', 32);
            $table->string('status', 32);
            $table->string('external_transaction_id')->nullable();
            $table->string('last_error', 100)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->json('request_payload')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        $now = now();
        $syncId = DB::connection('furimadeck')->table('accounting_entry_syncs')->insertGetId([
            'accounting_entry_id' => 88,
            'provider' => 'freee',
            'status' => 'csv_pending',
            'external_transaction_id' => null,
            'attempt_count' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migration = require base_path('database/migrations/furimadeck/2026_10_11_000200_add_external_import_id_to_accounting_entry_syncs.php');
        $migration->up();

        $this->assertTrue(Schema::connection('furimadeck')->hasColumn('accounting_entry_syncs', 'external_import_id'));
        $this->assertDatabaseHas('accounting_entry_syncs', [
            'id' => $syncId,
            'accounting_entry_id' => 88,
            'provider' => 'freee',
            'status' => 'csv_pending',
            'external_import_id' => null,
        ], 'furimadeck');
    }

    public function test_provider_account_scope_migration_preserves_legacy_rows_and_keeps_evidence_on_rollback(): void
    {
        Schema::connection('furimadeck')->create('accounting_entry_syncs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('accounting_entry_id');
            $table->string('provider', 32);
            $table->string('status', 32);
            $table->string('external_transaction_id')->nullable();
            $table->timestamps();
        });
        $syncId = DB::connection('furimadeck')->table('accounting_entry_syncs')->insertGetId([
            'accounting_entry_id' => 88,
            'provider' => 'money_forward',
            'status' => 'legacy_unknown',
            'external_transaction_id' => 'existing-evidence-id',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $migration = require base_path('database/migrations/furimadeck/2026_10_19_000300_add_provider_account_scope_hash_to_accounting_entry_syncs.php');

        $migration->up();

        $this->assertTrue(Schema::connection('furimadeck')->hasColumn('accounting_entry_syncs', 'provider_account_scope_hash'));
        $this->assertDatabaseHas('accounting_entry_syncs', [
            'id' => $syncId,
            'status' => 'legacy_unknown',
            'external_transaction_id' => 'existing-evidence-id',
            'provider_account_scope_hash' => null,
        ], 'furimadeck');

        $migration->down();

        $this->assertTrue(Schema::connection('furimadeck')->hasColumn('accounting_entry_syncs', 'provider_account_scope_hash'));
        $this->assertDatabaseHas('accounting_entry_syncs', [
            'id' => $syncId,
            'external_transaction_id' => 'existing-evidence-id',
            'provider_account_scope_hash' => null,
        ], 'furimadeck');

        $migration->up();

        $this->assertTrue(Schema::connection('furimadeck')->hasColumn('accounting_entry_syncs', 'provider_account_scope_hash'));
        $this->assertDatabaseHas('accounting_entry_syncs', [
            'id' => $syncId,
            'external_transaction_id' => 'existing-evidence-id',
            'provider_account_scope_hash' => null,
        ], 'furimadeck');
    }

    public function test_multi_product_purchase_allocation_migration_preserves_existing_evidence_and_blocks_destructive_rollback(): void
    {
        $connection = DB::connection('furimadeck');
        Schema::connection('furimadeck')->create('manual_product_purchase_reconciliation_refs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('product_id');
            $table->string('provider', 32);
            $table->char('external_transaction_id_hash', 64);
            $table->timestamp('created_at');
            $table->unique(
                ['user_id', 'provider', 'external_transaction_id_hash'],
                'manual_purchase_external_ref_unique',
            );
        });
        $existingId = $connection->table('manual_product_purchase_reconciliation_refs')->insertGetId([
            'user_id' => 169,
            'product_id' => 1172,
            'provider' => 'freee',
            'external_transaction_id_hash' => hash('sha256', 'existing-journal-id'),
            'created_at' => now(),
        ]);
        $migration = require base_path('database/migrations/furimadeck/2026_10_19_000100_allow_multi_product_purchase_reconciliation_allocations.php');

        $migration->up();

        $this->assertTrue(Schema::connection('furimadeck')->hasColumn('manual_product_purchase_reconciliation_refs', 'transaction_total'));
        $this->assertTrue(Schema::connection('furimadeck')->hasColumn('manual_product_purchase_reconciliation_refs', 'allocated_amount'));
        $this->assertDatabaseHas('manual_product_purchase_reconciliation_refs', [
            'id' => $existingId,
            'user_id' => 169,
            'product_id' => 1172,
            'provider' => 'freee',
            'transaction_total' => null,
            'allocated_amount' => null,
        ], 'furimadeck');

        $connection->table('manual_product_purchase_reconciliation_refs')->insert([
            'user_id' => 169,
            'product_id' => 1173,
            'provider' => 'freee',
            'external_transaction_id_hash' => hash('sha256', 'existing-journal-id'),
            'created_at' => now(),
        ]);
        $this->assertSame(2, $connection->table('manual_product_purchase_reconciliation_refs')
            ->where('user_id', 169)
            ->where('provider', 'freee')
            ->count());

        try {
            $migration->down();
            $this->fail('Rollback must refuse to remove purchase allocation evidence.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Manual purchase allocation evidence exists and must be preserved.', $exception->getMessage());
        }

        $this->assertTrue(Schema::connection('furimadeck')->hasColumn('manual_product_purchase_reconciliation_refs', 'transaction_total'));
        $this->assertSame(2, $connection->table('manual_product_purchase_reconciliation_refs')->count());
    }

    public function test_ambiguous_legacy_failed_entries_are_not_marked_resendable(): void
    {
        $connection = DB::connection('furimadeck');
        $createdAt = now()->subYears(2);
        $failedWithoutId = $connection->table('accounting_entries')->insertGetId([
            'sync_status' => 'failed',
            'external_transaction_id' => null,
            'synced_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $failedWithId = $connection->table('accounting_entries')->insertGetId([
            'sync_status' => 'failed',
            'external_transaction_id' => 'legacy-failed-transaction',
            'synced_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $initialMigration = require base_path('database/migrations/furimadeck/2026_10_09_000300_create_accounting_entry_syncs.php');
        $initialMigration->up();

        $correctionMigration = require base_path('database/migrations/furimadeck/2026_10_16_000100_mark_ambiguous_legacy_accounting_failures.php');
        $correctionMigration->up();

        foreach ([$failedWithoutId, $failedWithId] as $entryId) {
            $entry = new AccountingEntry(['sync_status' => 'failed']);
            $entry->setRelation('providerSyncs', AccountingEntrySync::on('furimadeck')
                ->where('accounting_entry_id', $entryId)
                ->get());
            foreach (['freee', 'money_forward'] as $provider) {
                $this->assertDatabaseHas('accounting_entry_syncs', [
                    'accounting_entry_id' => $entryId,
                    'provider' => $provider,
                    'status' => 'legacy_unknown',
                ], 'furimadeck');
                $this->assertSame('legacy_unknown', $entry->providerSyncStatus($provider));
            }
        }
        $this->assertSame(
            'legacy-failed-transaction',
            $connection->table('accounting_entries')->where('id', $failedWithId)->value('external_transaction_id'),
        );
    }

    public function test_money_forward_invoice_kind_audit_migration_refuses_rollback(): void
    {
        $migration = require base_path('database/migrations/furimadeck/2026_10_19_000400_add_money_forward_invoice_kind_confirmation_audit.php');

        try {
            $migration->down();
            $this->fail('Rollback must refuse to discard invoice-kind audit history.');
        } catch (\LogicException $exception) {
            $this->assertSame(
                'Money Forward invoice-kind audit history is retained; this migration cannot be rolled back.',
                $exception->getMessage(),
            );
        }
    }
}
