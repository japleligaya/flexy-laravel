<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('salary_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('effective_on');
            $table->string('basis', 12); // daily or monthly
            $table->unsignedBigInteger('amount_cents');
            $table->string('reason', 300);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'effective_on']);
        });
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
        });
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->string('source', 32)->default('manual');
        });
        // A cancelled period must not permanently reserve its dates. Application
        // validation prevents overlapping *active* periods, including final ones.
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropUnique('payroll_periods_starts_on_ends_on_unique');
        });
    }
    public function down(): void
    {
        // If users created a replacement after cancellation, clear those duplicate
        // ranges before rolling this feature back; the old unique key cannot hold both.
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->unique(['starts_on', 'ends_on']);
        });
        Schema::table('payroll_entries', function (Blueprint $table) {
            $table->dropColumn('source');
        });
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['cancelled_at','cancelled_by','cancellation_reason']);
        });
        Schema::dropIfExists('salary_rates');
    }
};
