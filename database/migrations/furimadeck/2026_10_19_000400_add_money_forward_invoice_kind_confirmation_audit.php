<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('furimadeck')->table('accounting_entry_syncs', function (Blueprint $table): void {
            $table->timestamp('manual_invoice_kind_confirmed_at')->nullable();
            $table->unsignedBigInteger('manual_invoice_kind_confirmed_by_user_id')->nullable();
            $table->char('manual_invoice_kind_evidence_hash', 64)->nullable();
        });

        Schema::connection('furimadeck')->create('accounting_entry_sync_invoice_kind_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('accounting_entry_sync_id');
            $table->unsignedBigInteger('accounting_entry_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->string('provider', 32);
            $table->string('event_type', 48);
            $table->char('provider_account_scope_hash', 64);
            $table->string('external_transaction_id', 255);
            $table->char('request_payload_hash', 64);
            $table->char('remote_evidence_hash', 64)->nullable();
            $table->json('observed_fields')->nullable();
            $table->json('observed_values')->nullable();
            $table->string('reason', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['accounting_entry_sync_id', 'id'], 'entry_sync_invoice_audit_order_index');
            $table->index(['user_id', 'provider', 'created_at'], 'invoice_kind_audit_user_provider_index');
            $table->foreign('accounting_entry_sync_id', 'mf_inv_audit_sync_fk')->references('id')->on('accounting_entry_syncs')->cascadeOnDelete();
            $table->foreign('accounting_entry_id', 'mf_inv_audit_entry_fk')->references('id')->on('accounting_entries')->cascadeOnDelete();
            $table->foreign('user_id', 'mf_inv_audit_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'mf_inv_audit_actor_fk')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        throw new LogicException('Money Forward invoice-kind audit history is retained; this migration cannot be rolled back.');
    }
};
