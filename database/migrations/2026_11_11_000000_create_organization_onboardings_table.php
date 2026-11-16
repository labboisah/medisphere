<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_onboardings', function (Blueprint $table): void {
            $table->foreignUuid('organization_id')->primary()->constrained()->restrictOnDelete();
            $table->string('legal_name');
            $table->string('organization_type');
            $table->string('ownership_type');
            $table->string('owner_name');
            $table->string('registration_authority');
            $table->string('registration_number');
            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('city');
            $table->string('state_region');
            $table->string('postal_code')->nullable();
            $table->string('country');
            $table->string('notice_version');
            $table->boolean('authority_confirmed');
            $table->boolean('processing_consent');
            $table->foreignId('accepted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('accepted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_onboardings');
    }
};
