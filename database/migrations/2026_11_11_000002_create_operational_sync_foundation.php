<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_sync_installations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('installation_uuid');
            $table->uuid('link_uuid')->nullable();
            $table->string('status', 20);
            $table->char('credential_hash', 64)->nullable()->unique('op_sync_credential_hash_unique');
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'installation_uuid'], 'op_sync_org_installation_unique');
        });

        Schema::create('operational_sync_enrollment_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('link_uuid');
            $table->uuid('installation_uuid');
            $table->char('code_hash', 64)->unique('op_sync_code_hash_unique');
            $table->timestamp('expires_at')->index();
            $table->timestamp('redeemed_at')->nullable();
            $table->char('proof_hash', 64)->nullable();
            $table->text('encrypted_credential')->nullable();
            $table->timestamps();
        });

        Schema::create('operational_sync_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sync_installation_id')->constrained('operational_sync_installations')->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->char('envelope_hash', 64);
            $table->text('payload_json');
            $table->timestamp('occurred_at');
            $table->timestamp('accepted_at');
            $table->unique(['sync_installation_id', 'sequence'], 'op_sync_installation_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_sync_events');
        Schema::dropIfExists('operational_sync_enrollment_codes');
        Schema::dropIfExists('operational_sync_installations');
    }
};
