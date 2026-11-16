<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('management_audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id');
            $table->uuid('organization_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->string('action', 100);
            $table->unsignedSmallInteger('http_status');
            $table->uuid('correlation_id');
            $table->timestamp('created_at');
            $table->index(['organization_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('management_audit_events');
    }
};
