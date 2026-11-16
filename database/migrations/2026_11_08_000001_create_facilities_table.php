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
        Schema::create('facilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('name');
            $table->string('status')->default('inactive');
            $table->string('locale', 35)->nullable();
            $table->string('time_zone', 64)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
            $table->unique(['id', 'organization_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facilities');
    }
};
