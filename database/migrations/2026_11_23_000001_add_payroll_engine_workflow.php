<?php

use App\Services\Payroll\LegacySalaryBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role', 24)->default('employee');
            $t->foreignId('employee_id')->nullable()->unique()->constrained()->nullOnDelete();
        });
        // All pre-existing logins were administrators in V8. New logins default to Employee.
        DB::table('users')->update(['role' => 'super_admin']);
        Schema::table('employees', function (Blueprint $t) {
            $t->date('ended_on')->nullable();
            $t->string('payroll_classification', 32)->default('unverified');
        });
        Schema::table('attendance_records', function (Blueprint $t) {
            $t->dateTime('time_in')->nullable();
            $t->dateTime('time_out')->nullable();
            $t->unsignedInteger('overtime_minutes')->default(0);
            $t->unsignedInteger('approved_overtime_minutes')->default(0);
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->boolean('paid_leave')->default(false);
            $t->string('leave_type', 60)->nullable();
        });
        Schema::create('payroll_settings', function (Blueprint $t) {
            $t->id();
            $t->json('policy');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('payroll_locks', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
        });
        DB::table('payroll_locks')->insert(['id' => 1]);
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 80);
            $t->string('subject_type', 100);
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->timestamp('created_at');
            $t->index(['subject_type', 'subject_id']);
        });
        Schema::create('payroll_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('type', 24);
            $t->string('label', 120);
            $t->unsignedBigInteger('amount_cents');
            $t->date('effective_on');
            $t->date('ends_on')->nullable();
            $t->boolean('recurring')->default(false);
            $t->boolean('taxable');
            $t->boolean('contributory');
            $t->string('reason', 500);
            $t->string('source_url', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::table('payroll_entries', function (Blueprint $t) {
            $t->json('calculation')->nullable();
            $t->json('snapshot')->nullable();
            $t->json('statutory_overrides')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
        });
        Schema::table('payroll_periods', function (Blueprint $t) {
            $t->json('workflow')->nullable();
            $t->json('snapshot')->nullable();
            $t->foreignId('correction_of')->nullable()->constrained('payroll_periods')->restrictOnDelete();
        });
        Schema::create('payroll_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_period_id')->constrained()->restrictOnDelete();
            $t->date('paid_on');
            $t->string('method', 40);
            $t->string('reference', 120)->unique();
            $t->unsignedBigInteger('amount_cents');
            $t->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $t->string('reason', 500);
            $t->timestamps();
        });
        Schema::create('payroll_corrections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_period_id')->constrained()->restrictOnDelete();
            $t->string('status', 20)->default('pending');
            $t->string('type', 24)->default('full_reversal');
            $t->bigInteger('net_delta_cents');
            $t->json('snapshot');
            $t->string('reason', 500);
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_reason', 500)->nullable();
            $t->timestamps();
        });
        // History always takes precedence; missing dates remain explicitly unconfigured.
        app(LegacySalaryBackfill::class)->run();
        foreach (DB::table('users')->select('id')->get() as $user) {
            DB::table('audit_events')->insert(['actor_id' => null, 'action' => 'legacy_admin_role_backfilled', 'subject_type' => 'User', 'subject_id' => $user->id, 'before' => '{}', 'after' => json_encode(['role' => 'super_admin', 'reason' => 'Preserve pre-existing V8 administrator access']), 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        // Deliberately forward-only: deleting financial history is not a safe rollback.
        throw new RuntimeException('Restore the pre-release backup into a separate database; this migration retains financial history.');
    }
};
