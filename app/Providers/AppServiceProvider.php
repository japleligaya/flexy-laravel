<?php

namespace App\Providers;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollCorrection;
use App\Models\PayrollEntry;
use App\Models\PayrollPayment;
use App\Models\PayrollPeriod;
use App\Models\PayrollSetting;
use App\Models\SalaryRate;
use App\Observers\PayrollAuditObserver;
use App\Services\Payroll\Access;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([Employee::class, SalaryRate::class, AttendanceRecord::class,
            PayrollEntry::class, PayrollPeriod::class, PayrollAdjustment::class,
            PayrollPayment::class, PayrollSetting::class, PayrollCorrection::class] as $model) {
            $model::observe(PayrollAuditObserver::class);
        }
        foreach (['dashboard', 'people', 'salary', 'attendance_read', 'attendance', 'payroll', 'read', 'approve', 'export', 'pay', 'settings'] as $ability) {
            Gate::define($ability, fn ($user) => Access::allows($user, $ability));
        }
    }
}
