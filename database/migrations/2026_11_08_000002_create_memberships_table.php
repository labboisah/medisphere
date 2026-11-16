<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('facility_id')->nullable();
            $table->string('role');
            $table->string('status')->default('invited');
            $table->timestamps();

            $table->foreign(['facility_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('facilities')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
