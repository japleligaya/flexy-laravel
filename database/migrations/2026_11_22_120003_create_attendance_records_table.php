<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->string('status', 20);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
            $table->index('work_date');
        });
    }
    public function down(): void { Schema::dropIfExists('attendance_records'); }
};
