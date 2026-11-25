<?php

namespace App\Services\Payroll;

use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ManualDraftEntry
{
    public const AMOUNTS = ['base_pay', 'overtime_pay', 'allowances', 'deductions'];

    public function __construct(private ManualCalculation $calculation) {}

    public function rules(bool $creating): array
    {
        $rules = array_fill_keys(self::AMOUNTS, ['required', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/D']);
        $rules['notes'] = ['required', 'string', 'min:10', 'max:500'];
        if ($creating) {
            $rules['employee_id'] = ['required', 'integer', Rule::exists('employees', 'id')->where('is_active', true)];
        }

        return $rules;
    }

    public function prepare(PayrollPeriod $period, ?PayrollEntry $entry, array $input): PayrollEntry
    {
        $data = Validator::make($input, $this->rules($entry === null))->validate();
        foreach (self::AMOUNTS as $field) {
            $data[$field.'_cents'] = Money::toCents((string) $data[$field]);
            unset($data[$field]);
        }
        $gross = $data['base_pay_cents'] + $data['overtime_pay_cents'] + $data['allowances_cents'];
        if ($data['deductions_cents'] > $gross) {
            throw ValidationException::withMessages(['deductions' => 'Deductions cannot exceed gross pay.']);
        }
        if ($entry) {
            if ($entry->reviewed_at) {
                throw ValidationException::withMessages(['period' => 'Reopen the review explicitly before changing this entry.']);
            }
            // Preview must leave the original model and its revision untouched.
            $draft = clone $entry;
        } else {
            if ($period->entries()->where('employee_id', $data['employee_id'])->exists()) {
                throw ValidationException::withMessages(['employee_id' => 'Employee already has an entry in this period.']);
            }
            $draft = $period->entries()->make();
        }
        $draft->fill($data + ['source' => 'manual', 'reviewed_at' => null, 'reviewed_by' => null]);
        $draft->setRelation('payrollPeriod', $period);
        $draft->calculation = $this->calculation->calculate($draft);

        return $draft;
    }

    public function persist(PayrollEntry $draft): PayrollEntry
    {
        $creating = ! $draft->exists;
        $draft->save();
        if ($creating) {
            // The native manual calculation includes the persisted entry ID in its line breakdown.
            $draft->update(['calculation' => $this->calculation->calculate($draft)]);
        }

        return $draft->refresh();
    }

    public function save(PayrollPeriod $period, ?PayrollEntry $entry, array $input): PayrollEntry
    {
        return DB::transaction(function () use ($period, $entry, $input) {
            $period = $this->draft($period->id, true);
            $entry = $entry ? $period->entries()->whereKey($entry->id)->lockForUpdate()->firstOrFail() : null;

            return $this->persist($this->prepare($period, $entry, $input));
        });
    }

    public function draft(int|string $id, bool $lock): PayrollPeriod
    {
        $query = PayrollPeriod::query();
        $period = ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
        if (! $period->isDraft()) {
            throw ValidationException::withMessages(['period' => 'Only draft payroll entries can be changed.']);
        }

        return $period;
    }
}
