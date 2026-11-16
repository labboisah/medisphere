<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_enrollment_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('link_uuid');
            $table->text('encrypted_code');
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(['organization_id', 'link_uuid', 'expires_at'], 'server_enrollment_org_link_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_enrollment_codes');
    }
};
