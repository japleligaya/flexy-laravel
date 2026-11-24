<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('draft');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->unique(['starts_on', 'ends_on']);
        });
    }
    public function down(): void { Schema::dropIfExists('payroll_periods'); }
};
