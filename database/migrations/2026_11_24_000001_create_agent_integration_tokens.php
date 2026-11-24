<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_integration_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('access_digest', 64)->unique();
            $table->char('refresh_digest', 64)->unique();
            $table->unsignedBigInteger('access_expires_at');
            $table->unsignedBigInteger('family_expires_at')->index();
            $table->unsignedBigInteger('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_integration_tokens');
    }
};
