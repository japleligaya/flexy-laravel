<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payroll_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('base_pay_cents');
            $table->unsignedBigInteger('overtime_pay_cents')->default(0);
            $table->unsignedBigInteger('allowances_cents')->default(0);
            $table->unsignedBigInteger('deductions_cents')->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->unique(['payroll_period_id', 'employee_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('payroll_entries'); }
};
