<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_installations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('facility_id');
            $table->uuid('installation_uuid')->unique();
            $table->string('name', 255);
            $table->string('status', 32)->default('registered');
            $table->timestamp('activation_code_expires_at')->nullable();
            $table->foreignId('registered_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign(['facility_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('facilities')
                ->restrictOnDelete();
            $table->index(['organization_id', 'facility_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_installations');
    }
};
