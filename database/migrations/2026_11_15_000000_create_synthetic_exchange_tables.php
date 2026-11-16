<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('synthetic_exchange_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('facility_id');
            $table->uuid('local_subject_reference');
            $table->uuid('recipient_subject_reference');
            $table->string('review_status', 20)->default('pending');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign(['facility_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('facilities')
                ->restrictOnDelete();
            $table->unique(['facility_id', 'local_subject_reference']);
            $table->unique(['id', 'organization_id', 'facility_id']);
            $table->index(['organization_id', 'review_status', 'created_at']);
        });

        Schema::create('synthetic_sharing_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('synthetic_exchange_link_id');
            $table->uuid('organization_id');
            $table->uuid('facility_id');
            $table->string('purpose', 80);
            $table->json('scope');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign(['synthetic_exchange_link_id', 'organization_id', 'facility_id'])
                ->references(['id', 'organization_id', 'facility_id'])
                ->on('synthetic_exchange_links')
                ->restrictOnDelete();
            $table->index(['organization_id', 'facility_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synthetic_sharing_grants');
        Schema::dropIfExists('synthetic_exchange_links');
    }
};
