<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->json('permissions');
            $table->timestamps();
        });
        Schema::table('synthetic_invitations', function (Blueprint $table): void {
            $table->string('role')->default('organization_admin');
        });
        Schema::create('organization_role_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('organization_role_id')->constrained('organization_roles')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_role_changes');
        Schema::table('synthetic_invitations', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
        Schema::dropIfExists('organization_roles');
    }
};
