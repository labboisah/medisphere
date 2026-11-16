<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kernel_bridge_account_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('link_uuid')->unique();
            $table->uuid('license_uuid');
            $table->uuid('subscription_uuid')->nullable();
            $table->string('product_code', 64);
            $table->string('status', 32);
            $table->string('license_status', 32)->nullable();
            $table->string('subscription_status', 32)->nullable();
            $table->string('plan_name')->nullable();
            $table->unsignedBigInteger('entitlement_revision')->nullable();
            $table->json('entitlements')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('grace_expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'license_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kernel_bridge_account_links');
    }
};
