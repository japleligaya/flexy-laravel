<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number', 32)->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable()->unique();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('position');
            $table->unsignedBigInteger('monthly_salary_cents')->default(0);
            $table->date('hired_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'last_name']);
        });
    }
    public function down(): void { Schema::dropIfExists('employees'); }
};
