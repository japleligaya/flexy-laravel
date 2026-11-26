<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Payroll\RuntimeDatabaseBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AgentIntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/integrations/agent';

    private const PASSWORD = 'isolated-test-password';

    public function test_adjustments_read_filters_kind_and_preserves_employee_boundaries(): void
    {
        $department = DB::table('departments')->insertGetId(['name' => 'Fixture operations']);
        $employee = $this->employee('FIX-101', $department);
        $other = $this->employee('FIX-102', $department);
        $base = ['type' => 'allowance', 'amount_cents' => 15000, 'effective_on' => '2026-11-01',
            'taxable' => false, 'contributory' => false, 'reason' => 'Private fixture reason'];
        DB::table('payroll_adjustments')->insert([
            $base + ['employee_id' => $employee, 'label' => 'Meal allowance'],
            array_replace($base, ['type' => 'deduction', 'employee_id' => null, 'label' => 'Global deduction']),
            $base + ['employee_id' => $other, 'label' => 'Other employee allowance'],
        ]);
        $hr = $this->login($this->account('hr'))->assertOk()->json('tokens');
        $officer = $this->login($this->account('payroll_officer'))->assertOk()->json('tokens');
        $url = self::API.'/payroll-adjustments?employee_id='.$employee.'&type=allowance';
        $this->getJson($url, $this->bearer($hr))->assertForbidden();
        $this->getJson($url, $this->bearer($officer))->assertOk()->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.label', 'Meal allowance')->assertJsonPath('records.0.amount_cents', 15000)
            ->assertJsonMissingPath('records.0.reason')->assertJsonMissingPath('records.0.employee_id');
    }

    public function test_runtime_binding_requires_a_current_admin_and_expires_and_throttles(): void
    {
        $this->freezeTime();
        $path = self::API.'/diagnostics/runtime-binding';
        $admin = $this->bearer($this->login($this->account('super_admin'))->assertOk()->json('tokens'));
        $officer = $this->bearer($this->login($this->account('payroll_officer'))->assertOk()->json('tokens'));
        config([
            'app.agent_runtime_binding.production' => true,
            'app.agent_runtime_binding.deployment' => 'payrollgo-test.vercel.app',
        ]);
        $this->get($path, $admin)->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        config(['app.agent_runtime_binding.expires_at' => now()->timestamp + 1801]);
        $this->get($path, $admin)->assertNotFound();
        config(['app.agent_runtime_binding.expires_at' => now()->timestamp + 120]);
        $this->get($path)->assertUnauthorized();
        $this->get($path, $officer)->assertForbidden();
        $this->get($path.'?extra=1', $admin)->assertUnprocessable();
        config(['app.agent_runtime_binding.production' => false]);
        $this->get($path, $admin)->assertNotFound();
        config(['app.agent_runtime_binding.production' => true]);

        $payload = [
            'runtime_host_sha256' => hash('sha256', 'example.test'),
            'database' => 'neondb',
            'schema_name' => 'public',
            'runtime_deployment' => 'payrollgo-test.vercel.app',
            'api_version' => 'v1',
        ];
        $this->mock(RuntimeDatabaseBinding::class)
            ->shouldReceive('read')->with(DB::connection(), 'payrollgo-test.vercel.app')->times(3)->andReturn($payload);
        for ($i = 0; $i < 3; $i++) {
            $this->get($path, $admin)->assertOk()->assertExactJson($payload)
                ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Vercel-CDN-Cache-Control', 'no-store');
        }
        $this->get($path, $admin)->assertTooManyRequests()
            ->assertHeader('Retry-After')->assertHeader('Cache-Control', 'no-store, private');
        $this->travel(120)->seconds();
        $this->get($path, $admin)->assertNotFound();
    }

    public function test_runtime_binding_reports_only_metadata_from_the_primary_connection(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->sqliteCreateFunction('current_database', fn () => 'reported-db');
        $pdo->sqliteCreateFunction('current_schema', fn () => 'reported-schema');
        $connection = new \Illuminate\Database\Connection($pdo, 'configured-db', '', [
            'driver' => 'pgsql',
            'host' => 'Example.Test',
        ]);

        $this->assertSame([
            'runtime_host_sha256' => hash('sha256', 'example.test'),
            'database' => 'reported-db',
            'schema_name' => 'reported-schema',
            'runtime_deployment' => 'payrollgo-test.vercel.app',
            'api_version' => 'v1',
        ], (new RuntimeDatabaseBinding)->read($connection, 'payrollgo-test.vercel.app'));
    }

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('cache.stores.database.connection', 'sqlite');
        $app['config']->set('cache.stores.database.table', 'cache');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        return $app;
    }

    public function test_synthetic_batch_atomic_lifecycle_and_scope(): void
    {
        $this->freezeTime();
        $token = $this->login($this->account('super_admin'))->assertOk()->json('tokens');
        $headers = $this->bearer($token);
        $root = self::API.'/synthetic/employee-batches/';
        $uuid = fn () => (string) \Illuminate\Support\Str::uuid();
        $this->postJson($root.'create/preview', ['batch_id' => $uuid(), 'count' => 1], $headers)->assertNotFound();
        config(['app.agent_synthetic_batches' => true]);
        $approve = $headers + ['X-ADI-Synthetic-Approval' => self::PASSWORD];
        $preview = fn ($op, $args) => $this->postJson($root.$op.'/preview', $args, $headers)->assertOk()->json();
        $payload = fn ($args, $plan) => $args + ['ticket' => $plan['ticket'], 'approved_digest' => $plan['target']['digest']];
        $groups = [];
        foreach ([1, 20, 35] as $count) {
            $args = ['batch_id' => $uuid(), 'count' => $count];
            $plan = $preview('create', $args);
            $this->assertCount($count, array_unique(array_column($plan['target']['records'], 'employee_number')));
            $this->assertCount($count, array_unique(array_column($plan['target']['records'], 'email')));
            $this->assertTrue(collect($plan['target']['records'])->every(fn ($record) =>
                str_ends_with($record['email'], '@example.test') && $record['payroll_classification'] === 'unverified'
            ));
            $execution = $payload($args, $plan);
            $this->postJson($root.'create/execute', $execution, $headers)->assertForbidden();
            $this->postJson($root.'create/execute', array_replace($execution, ['approved_digest' => str_repeat('0', 64)]), $approve)->assertConflict();
            $receipt = $this->postJson($root.'create/execute', $execution, $approve)->assertOk()->assertJsonPath('verified', $count)->json();
            $this->assertSame($count, DB::table('employees')->whereIn('id', $receipt['ids'])->where('monthly_salary_cents', 0)->where('is_active', false)->count());
            $this->postJson($root.'create/execute', $execution, $approve)->assertConflict();
            $groups[] = ['batch_id' => $args['batch_id'], 'ids' => $receipt['ids']];
        }
        $this->assertDatabaseCount('employees', 56);
        $this->postJson($root.'update/preview', ['batch_id' => $groups[0]['batch_id'],
            'ids' => $groups[1]['ids'], 'position' => 'Synthetic Reviewer'], $headers)->assertNotFound();
        $this->postJson($root.'update/preview', ['batch_id' => $groups[0]['batch_id'],
            'ids' => [$groups[0]['ids'][0], $groups[0]['ids'][0]], 'position' => 'Synthetic Reviewer'], $headers)->assertUnprocessable();

        $single = $groups[0] + ['position' => 'Synthetic Reviewer'];
        $stale = $preview('update', $single);
        $this->travel(6)->minutes();
        $this->postJson($root.'update/execute', $payload($single, $stale), $approve)->assertConflict();
        foreach ([$groups[0], $groups[1]] as $group) {
            $change = $group + ['position' => 'Synthetic Reviewer'];
            $this->postJson($root.'update/execute', $payload($change, $preview('update', $change)), $approve)
                ->assertOk()->assertJsonPath('verified', count($group['ids']));
            $this->assertSame(count($group['ids']), DB::table('employees')->whereIn('id', $group['ids'])->where('position', 'Synthetic Reviewer')->count());
            $this->postJson($root.'delete/execute', $payload($group, $preview('delete', $group)), $approve)
                ->assertOk()->assertJsonPath('verified', count($group['ids']));
        }
        $this->assertDatabaseCount('employees', 35);
        $this->assertSame(35, DB::table('employees')->whereIn('id', $groups[2]['ids'])->where('position', '!=', 'Synthetic Reviewer')->count());
    }

    public function test_synthetic_plan_rolls_back_completed_steps_on_second_step_failure(): void
    {
        config(['app.agent_synthetic_batches' => true]);
        $user = $this->account('super_admin');
        $token = $this->login($user)->assertOk()->json('tokens');
        $headers = $this->bearer($token);
        $body = ['batch_id' => (string) \Illuminate\Support\Str::uuid(), 'tasks' => [
            ['operation' => 'employee_create', 'count' => 2],
            ['operation' => 'period_create', 'periods' => [
                ['label' => 'Synthetic period A', 'starts_on' => '2027-02-01', 'ends_on' => '2027-02-15'],
                ['label' => 'Synthetic period B', 'starts_on' => '2027-02-16', 'ends_on' => '2027-02-28'],
            ]],
        ]];
        $root = self::API.'/synthetic/operation-plans/';
        $receipt = self::API.'/synthetic/batch-receipts/plan/';
        $preview = $this->postJson($root.'preview', $body, $headers)->assertOk()->json();
        $digest = $preview['target']['digest'];
        DB::unprepared("CREATE TEMP TRIGGER reject_fixture_period BEFORE INSERT ON payroll_periods
            WHEN NEW.label = 'Synthetic period B' BEGIN SELECT RAISE(ABORT, 'fixture rollback'); END");
        try {
            $execute = $body + ['ticket' => $preview['ticket'], 'approved_digest' => $digest];
            $this->postJson($root.'execute', $execute, $headers + ['X-ADI-Synthetic-Approval' => self::PASSWORD])->assertServerError();
            $this->assertDatabaseCount('employees', 0);
            $this->assertDatabaseCount('payroll_periods', 0);
            $this->getJson($receipt.$digest, $headers)->assertNotFound();
        } finally {
            DB::unprepared('DROP TRIGGER reject_fixture_period');
        }
        $fresh = $this->postJson($root.'preview', $body, $headers)->assertOk()->json();
        $result = $this->postJson($root.'execute', $body + ['ticket' => $fresh['ticket'], 'approved_digest' => $fresh['target']['digest']],
            $headers + ['X-ADI-Synthetic-Approval' => self::PASSWORD])->assertOk()->json();
        $this->getJson($receipt.$fresh['target']['digest'], $headers)->assertOk()->assertExactJson($result);
        $other = $this->login($this->account('super_admin'))->assertOk()->json('tokens');
        $this->getJson($receipt.$fresh['target']['digest'], $this->bearer($other))->assertNotFound();
    }

    public function test_login_and_identity_use_an_independent_opaque_bearer_token(): void
    {
        $this->freezeTime();
        $user = $this->account();
        $login = $this->login($user)->assertOk()->assertJsonPath('tokens.expires_in', 900);
        $tokens = $login->json('tokens');
        $this->assertSame(['access_token', 'refresh_token', 'expires_in'], array_keys($tokens));
        $this->assertTrue(preg_match('/\A[a-f0-9]{64}\z/', $tokens['access_token']) === 1);
        $this->assertTrue(preg_match('/\A[a-f0-9]{64}\z/', $tokens['refresh_token']) === 1);
        $this->assertArrayNotHasKey('set-cookie', $login->headers->all());
        $this->assertGuest('web');
        $identity = ['id' => (string) $user->id, 'email' => $user->email];
        $this->assertSame($identity, $login->json('user'));

        $stored = DB::table('agent_integration_tokens')->sole();
        $this->assertTrue($stored->access_digest === hash('sha256', $tokens['access_token']));
        $this->assertTrue($stored->refresh_digest === hash('sha256', $tokens['refresh_token']));
        $this->assertSame(now()->timestamp + 900, (int) $stored->access_expires_at);
        $this->assertSame(now()->timestamp + 604800, (int) $stored->family_expires_at);
        $this->assertNoStore($login);

        $me = $this->get(self::API.'/auth/me', $this->bearer($tokens))->assertOk()->assertExactJson($identity);
        $this->assertNoStore($me);
        $this->actingAs($user, 'web')->get(self::API.'/auth/me')
            ->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        $this->get(self::API.'/auth/me', ['Authorization' => 'Bearer '.$tokens['refresh_token']])
            ->assertUnauthorized();
    }

    public function test_credentials_and_live_read_permission_are_required(): void
    {
        $this->login($this->account('employee'))->assertForbidden();
        $user = $this->account('approver');
        $this->postJson(self::API.'/auth/login', ['email' => $user->email, 'password' => 'invalid'])
            ->assertUnauthorized()->assertExactJson([
                'error' => ['code' => 'unauthenticated', 'message' => 'Invalid credentials or token.'],
            ]);
        $this->assertDatabaseCount('agent_integration_tokens', 0);

        $tokens = $this->login($user)->assertOk()->json('tokens');
        $user->update(['role' => 'hr']);
        $this->get(self::API.'/auth/me', $this->bearer($tokens))->assertOk();
        $this->get(self::API.'/payroll-periods', $this->bearer($tokens))->assertForbidden();
        $this->get(self::API.'/departments', $this->bearer($tokens))->assertOk();
        $tokens = $this->postJson(self::API.'/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertOk()->json('tokens');
        $user->update(['role' => 'employee']);
        $this->postJson(self::API.'/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertForbidden();

        $user->delete();
        $this->get(self::API.'/auth/me', $this->bearer($tokens))->assertUnauthorized();
        $this->assertDatabaseCount('agent_integration_tokens', 0);
    }

    public function test_refresh_rotates_both_tokens_rejects_replay_and_keeps_absolute_expiry(): void
    {
        $this->freezeTime();
        $first = $this->login($this->account())->assertOk()->json('tokens');
        $this->travel(900)->seconds();
        $this->get(self::API.'/auth/me', $this->bearer($first))->assertUnauthorized();

        $second = $this->postJson(self::API.'/auth/refresh', ['refresh_token' => $first['refresh_token']])
            ->assertOk()->assertJsonPath('tokens.expires_in', 900)->json('tokens');
        $this->postJson(self::API.'/auth/refresh', ['refresh_token' => $first['refresh_token']])->assertUnauthorized();
        $this->get(self::API.'/auth/me', $this->bearer($second))->assertOk();
        $this->assertDatabaseCount('agent_integration_tokens', 1);

        $this->travel(604800 - 900 - 60)->seconds();
        $third = $this->postJson(self::API.'/auth/refresh', ['refresh_token' => $second['refresh_token']])
            ->assertOk()->assertJsonPath('tokens.expires_in', 60)->json('tokens');
        $this->travel(60)->seconds();
        $this->get(self::API.'/auth/me', $this->bearer($third))->assertUnauthorized();
        $this->postJson(self::API.'/auth/refresh', ['refresh_token' => $third['refresh_token']])->assertUnauthorized();

        $this->login($this->account())->assertOk();
        $this->assertDatabaseCount('agent_integration_tokens', 1);
    }

    public function test_revocation_is_idempotent_and_affects_only_the_identified_family(): void
    {
        $user = $this->account();
        $first = $this->login($user)->assertOk()->json('tokens');
        $independent = $this->login($user)->assertOk()->json('tokens');
        $rotated = $this->postJson(self::API.'/auth/refresh', ['refresh_token' => $first['refresh_token']])
            ->assertOk()->json('tokens');
        $this->get(self::API.'/auth/me', $this->bearer($first))->assertUnauthorized();
        $this->postJson(self::API.'/auth/revoke', ['refresh_token' => $first['refresh_token']])->assertNoContent();
        $this->get(self::API.'/auth/me', $this->bearer($rotated))->assertOk();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $revoke = $this->postJson(self::API.'/auth/revoke', ['refresh_token' => $rotated['refresh_token']])->assertNoContent();
            $this->assertNoStore($revoke);
        }

        $this->get(self::API.'/auth/me', $this->bearer($rotated))->assertUnauthorized();
        $this->postJson(self::API.'/auth/refresh', ['refresh_token' => $rotated['refresh_token']])->assertUnauthorized();
        $this->get(self::API.'/auth/me', $this->bearer($independent))->assertOk();
    }

    public function test_cutoffs_are_bounded_sorted_and_project_only_metadata(): void
    {
        for ($day = 1; $day <= 11; $day++) {
            $date = sprintf('2026-01-%02d', $day);
            $this->cutoff($date, $date, 'draft');
        }
        $last = $this->cutoff('2026-01-11', '2026-01-12', 'finalized');
        $headers = $this->bearer($this->login($this->account('super_admin'))->assertOk()->json('tokens'));
        $first = $this->get(self::API.'/payroll-periods', $headers)->assertOk()
            ->assertJsonCount(10, 'records')->assertJsonPath('page', 1)->assertJsonPath('has_more', true)
            ->assertJsonPath('records.0.id', (string) $last)->assertJsonPath('records.1.id', (string) ($last - 1));
        $this->assertNoStore($first);
        $this->assertSame(['records', 'page', 'has_more'], array_keys($first->json()));
        $expected = ['id' => (string) $last, 'starts_on' => '2026-01-11', 'ends_on' => '2026-01-12', 'status' => 'finalized'];
        $this->assertSame($expected, $first->json('records.0'));
        $this->get(self::API.'/payroll-periods?page=2', $headers)->assertOk()
            ->assertJsonCount(2, 'records')->assertJsonPath('page', 2)->assertJsonPath('has_more', false);
        $this->get(self::API.'/payroll-periods?page=999', $headers)->assertOk()
            ->assertExactJson(['records' => [], 'page' => 999, 'has_more' => false]);
        $this->get(self::API.'/payroll-periods/'.$last, $headers)->assertOk()->assertExactJson(['record' => $expected]);
        $this->get(self::API.'/payroll-periods/999999', $headers)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_next_draft_lists_current_and_future_drafts_earliest_first(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-24 16:00:00', 'UTC'));
        $this->cutoff('2026-11-10', '2026-11-24', 'draft');
        $current = $this->cutoff('2026-11-20', '2026-11-25', 'draft');
        $this->cutoff('2026-11-25', '2026-11-30', 'finalized');
        $next = $this->cutoff('2026-12-01', '2026-12-15', 'draft');
        $headers = $this->bearer($this->login($this->account())->assertOk()->json('tokens'));
        $this->get(self::API.'/payroll-periods?next_draft=1', $headers)->assertOk()
            ->assertJsonCount(2, 'records')->assertJsonPath('records.0.id', (string) $current)
            ->assertJsonPath('records.1.id', (string) $next);
        $this->get(self::API.'/payroll-periods/count?period_status=draft', $headers)->assertOk()->assertExactJson([
            'statistics' => ['cutoff_count' => 3],
        ]);
        $this->get(self::API.'/payroll-periods/count', $headers)->assertOk()->assertJsonPath('statistics.cutoff_count', 4);
        $this->get(self::API.'/payroll-periods/count?period_status=unknown', $headers)->assertUnprocessable();
    }

    public function test_invalid_inputs_and_unknown_routes_return_sanitized_json_without_an_accept_header(): void
    {
        $headers = $this->bearer($this->login($this->account())->assertOk()->json('tokens'));
        foreach (['?page=0', '?page=1000', '?page=1.5', '?page[]=1', '?page=', '?limit=999'] as $query) {
            $response = $this->get(self::API.'/payroll-periods'.$query, $headers)
                ->assertUnprocessable()->assertExactJson([
                    'error' => ['code' => 'invalid_input', 'message' => 'Invalid request parameters.'],
                ]);
            $this->assertNoStore($response);
        }
        foreach (['bad', '0', '-1', '9223372036854775808', '1?page=1'] as $id) {
            $this->get(self::API.'/payroll-periods/'.$id, $headers)->assertUnprocessable();
        }
        $this->postJson(self::API.'/auth/refresh', ['refresh_token' => 'invalid'])->assertUnprocessable();
        $this->postJson(self::API.'/auth/login', ['email' => 'example@example.test', 'password' => self::PASSWORD, 'role' => 'super_admin'])
            ->assertUnprocessable();
        $this->get(self::API.'/unregistered')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->post(self::API.'/payroll-periods')->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    }

    public function test_login_uses_durable_throttling_and_provides_retry_guidance(): void
    {
        $this->freezeTime();
        $user = $this->account();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(self::API.'/auth/login', ['email' => $user->email, 'password' => 'invalid'])
                ->assertUnauthorized();
        }
        $response = $this->login($user)->assertTooManyRequests()
            ->assertJsonPath('error.code', 'rate_limited')->assertHeader('Retry-After', '60');
        $this->assertNoStore($response);
        $this->assertDatabaseHas('cache', ['expiration' => now()->timestamp + 60]);
        $this->travel(61)->seconds();
        $this->login($user)->assertOk();
    }

    public function test_people_attendance_and_payslip_reads_enforce_permissions_and_project_fields(): void
    {
        $department = DB::table('departments')->insertGetId(['name' => 'Operations', 'description' => 'Private']);
        $employee = $this->employee('001', $department);
        $other = $this->employee('002', $department);
        $period = $this->cutoff('2026-08-01', '2026-08-15', 'finalized');
        $ownEntry = DB::table('payroll_entries')->insertGetId(['employee_id' => $employee, 'payroll_period_id' => $period,
            'base_pay_cents' => 12345, 'snapshot' => '{"net_cents":12345}']);
        DB::table('payroll_entries')->insert(['employee_id' => $other, 'payroll_period_id' => $period,
            'base_pay_cents' => 23456, 'snapshot' => '{"net_cents":23456}']);
        DB::table('attendance_records')->insert(['employee_id' => $employee, 'work_date' => '2026-08-01', 'status' => 'present', 'notes' => 'Private']);
        $hr = $this->bearer($this->login($this->account('hr'))->assertOk()->json('tokens'));
        $this->get(self::API.'/capabilities', $hr)->assertOk()->assertExactJson([
            'permissions' => ['dashboard', 'people', 'attendance_read', 'attendance', 'salary'],
        ]);
        $this->get(self::API.'/dashboard', $hr)->assertOk()->assertExactJson(['statistics' => [
            'active_employees' => 2, 'departments' => 1, 'present_today' => 0, 'draft_periods' => 0,
        ]]);
        $this->get(self::API.'/departments?q=operations', $hr)->assertOk()->assertExactJson([
            'records' => [['id' => (string) $department, 'name' => 'Operations', 'employee_count' => 2]], 'page' => 1, 'has_more' => false,
        ]);
        $record = $this->get(self::API."/employees?q=001&active=1&department_id=$department", $hr)->assertOk()->json('records');
        $this->assertSame([['id' => (string) $employee, 'employee_number' => '001', 'name' => 'Fixture Employee',
            'department_id' => (string) $department, 'active' => true]], $record);
        $this->get(self::API.'/employees?count_only=1', $hr)->assertOk()
            ->assertExactJson(['statistics' => ['employee_count' => 2]]);
        $this->get(self::API.'/employees?count_only=1&q=001', $hr)->assertOk()
            ->assertExactJson(['statistics' => ['employee_count' => 1]]);
        $this->get(self::API.'/employees?q=Fixture%20Employee', $hr)->assertOk()->assertJsonCount(2, 'records');
        $this->get(self::API.'/employees?count_only=1&page=2', $hr)->assertUnprocessable();
        $this->get(self::API.'/attendance?date=2026-08-01&status=present', $hr)->assertOk()
            ->assertJsonCount(1, 'records')->assertJsonMissingPath('records.0.notes');
        $this->get(self::API.'/attendance?date=2026-02-30', $hr)->assertUnprocessable();
        $this->get(self::API.'/salary-rates?employee_id=9223372036854775808', $hr)->assertUnprocessable();
        $this->get(self::API.'/reports', $hr)->assertForbidden();
        $linked = $this->account('employee');
        $linked->update(['employee_id' => $employee]);
        $personal = $this->bearer($this->login($linked)->assertOk()->json('tokens'));
        $this->get(self::API.'/payslips', $personal)->assertOk()->assertExactJson([
            'records' => [['id' => (string) $ownEntry, 'period_id' => (string) $period]], 'page' => 1, 'has_more' => false,
        ]);
        $this->get(self::API."/payslips?employee_id=$other", $personal)->assertUnprocessable();
        $this->get(self::API.'/employees', $personal)->assertForbidden();
        $this->get(self::API.'/employees?count_only=1', $personal)->assertForbidden();
        $linked->update(['employee_id' => null]);
        $this->get(self::API.'/payslips', $personal)->assertForbidden();
        $officer = $this->bearer($this->login($this->account())->assertOk()->json('tokens'));
        $this->get(self::API."/payroll-entries?period_id=$period", $officer)->assertOk()
            ->assertJsonCount(2, 'records')->assertJsonMissingPath('records.0.snapshot');
        $this->get(self::API.'/reports', $officer)->assertOk()->assertJsonCount(1, 'records');
        $this->get(self::API."/salary-rates?employee_id=$employee", $officer)->assertOk()->assertJsonCount(0, 'records');
        $this->get(self::API."/corrections?period_id=$period", $officer)->assertForbidden();
        $admin = $this->bearer($this->login($this->account('super_admin'))->assertOk()->json('tokens'));
        $this->get(self::API."/corrections?period_id=$period", $admin)->assertOk()->assertJsonCount(0, 'records');
        $this->get(self::API.'/settings', $admin)->assertOk()->assertExactJson(['configured' => false]);
    }

    public function test_department_preview_binds_actor_arguments_state_and_single_use(): void
    {
        $this->freezeTime();
        $hr = $this->account('hr');
        $headers = $this->bearer($this->login($hr)->assertOk()->json('tokens'));
        $peer = $this->bearer($this->login($this->account('hr'))->assertOk()->json('tokens'));
        $base = self::API.'/departments';
        $create = ['name' => 'Operations'];
        $ticket = $this->postJson("$base/create/preview", $create, $headers)->assertOk()->json('ticket');
        $this->assertDatabaseCount('departments', 0);
        $this->postJson("$base/create/execute", $create + ['ticket' => $ticket], $peer)->assertConflict();
        $this->postJson("$base/create/execute", ['name' => 'Other', 'ticket' => $ticket], $headers)->assertConflict();
        $this->postJson("$base/create/execute", $create, $headers)->assertUnprocessable();
        $id = $this->postJson("$base/create/execute", $create + ['ticket' => $ticket], $headers)->assertOk()->json('record.id');
        $this->get("$base/$id", $headers)->assertOk()->assertExactJson(['record' => ['id' => $id, 'name' => 'Operations']]);
        $this->postJson("$base/create/preview", $create, $headers)->assertUnprocessable();

        $update = ['id' => $id, 'name' => 'Support'];
        $stale = $this->postJson("$base/update/preview", $update, $headers)->assertOk()->json('ticket');
        DB::table('departments')->where('id', $id)->update(['description' => 'Edited on website']);
        $this->postJson("$base/update/execute", $update + ['ticket' => $stale], $headers)->assertConflict();
        $fresh = $this->postJson("$base/update/preview", $update, $headers)->assertOk()->json('ticket');
        $hr->update(['role' => 'approver']);
        $this->postJson("$base/update/execute", $update + ['ticket' => $fresh], $headers)->assertForbidden();
        $hr->update(['role' => 'hr']);
        $this->postJson("$base/update/execute", $update + ['ticket' => $fresh], $headers)->assertOk();
        $this->assertDatabaseHas('departments', ['id' => $id, 'name' => 'Support', 'description' => 'Edited on website']);

        $delete = ['id' => $id];
        $deleteTicket = $this->postJson("$base/delete/preview", $delete, $headers)->assertOk()->json('ticket');
        $employee = $this->employee('003', (int) $id);
        $this->postJson("$base/delete/preview", $delete, $headers)->assertConflict();
        $this->postJson("$base/delete/execute", $delete + ['ticket' => $deleteTicket], $headers)->assertConflict();
        $this->assertDatabaseHas('employees', ['id' => $employee, 'department_id' => $id]);
        DB::table('employees')->where('id', $employee)->update(['department_id' => null]);
        $this->postJson("$base/delete/execute", $delete + ['ticket' => $deleteTicket], $headers)->assertOk()->assertExactJson(['deleted' => $id]);
        $this->postJson("$base/create/execute", $create + ['ticket' => $ticket], $headers)->assertConflict();
        $expiring = $this->postJson("$base/create/preview", $create, $headers)->assertOk()->json('ticket');
        $this->travel(301)->seconds();
        $this->postJson("$base/create/execute", $create + ['ticket' => $expiring], $headers)->assertConflict();
        $this->assertDatabaseCount('departments', 0);
        $this->assertDatabaseHas('audit_events', ['action' => 'agent_department_delete', 'actor_id' => $hr->id,
            'subject_id' => $id, 'before' => '[]', 'after' => '[]']);
    }

    public function test_native_department_form_still_validates_and_preserves_descriptions(): void
    {
        $this->actingAs($this->account('hr'));
        $this->from('/departments')->post('/departments', ['name' => 'Operations', 'description' => 'Native description'])
            ->assertRedirect('/departments')->assertSessionHasNoErrors();
        $id = DB::table('departments')->where('name', 'Operations')->value('id');
        $this->from('/departments')->post('/departments', ['name' => 'Operations'])
            ->assertRedirect('/departments')->assertSessionHasErrors('name');
        $this->from('/departments')->put("/departments/$id", ['name' => 'Operations', 'description' => 'Updated description'])
            ->assertRedirect('/departments')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('departments', ['id' => $id, 'description' => 'Updated description']);
    }

    public function test_routine_agent_operations_preserve_validation_receipts_and_document_ownership(): void
    {
        $hr = $this->bearer($this->login($this->account('hr'))->assertOk()->json('tokens'));
        $officer = $this->bearer($this->login($this->account())->assertOk()->json('tokens'));
        $mutate = function (string $path, array $data, array $headers): TestResponse {
            $preview = $this->postJson(self::API.$path.'/preview', $data, $headers)->assertOk();

            return $this->postJson(self::API.$path.'/execute', $data + ['ticket' => $preview->json('ticket')], $headers);
        };
        $person = ['employee_number' => 'T-008', 'first_name' => 'Fixture', 'last_name' => 'Person',
            'position' => 'Testing', 'is_active' => '1', 'monthly_salary' => '0'];
        $this->postJson(self::API.'/employees/create/preview', ['monthly_salary' => '500'] + $person, $hr)->assertUnprocessable();
        $employee = $mutate('/employees/create', $person, $hr)->assertOk()->json('record.id');
        $this->assertDatabaseCount('salary_rates', 0);
        $this->get(self::API."/employees/$employee", $hr)->assertOk()->assertJsonMissingPath('record.monthly_salary');
        $mutate('/employees/update', ['id' => $employee, 'position' => 'QA'], $hr)->assertOk()->assertJsonPath('record.position', 'QA');
        $this->postJson(self::API.'/employees/update/preview', ['id' => $employee, 'monthly_salary' => '900'], $hr)->assertUnprocessable();
        $other = $mutate('/employees/create', ['employee_number' => 'T-009'] + $person, $hr)->assertOk()->json('record.id');

        $day = ['employee_id' => $employee, 'work_date' => '2026-08-04', 'status' => 'present'];
        $attendance = $mutate('/routine/attendance-create', $day, $hr)->assertOk()->json('record.id');
        $this->postJson(self::API.'/routine/attendance-create/preview', $day, $hr)->assertConflict();
        $this->postJson(self::API.'/employees/delete/preview', ['id' => $employee], $hr)->assertConflict();
        $mutate('/routine/attendance-delete', ['id' => $attendance], $hr)->assertOk()->assertJsonPath('deleted', $attendance);
        $this->get(self::API."/verified-attendance/$attendance", $hr)->assertNotFound();
        $mutate('/employees/delete', ['id' => $other], $hr)->assertOk()->assertJsonPath('deleted', $other);

        $cutoff = ['label' => 'Fixture cutoff', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-15'];
        $this->postJson(self::API.'/routine/period-create/preview', $cutoff, $hr)->assertForbidden();
        $period = $mutate('/routine/period-create', $cutoff, $officer)->assertOk()->assertJsonPath('record.status', 'draft')->json('record.id');
        $this->postJson(self::API.'/routine/period-create/preview', $cutoff, $officer)->assertUnprocessable();
        $mutate('/routine/engine-generate', ['id' => $period], $officer)->assertOk()->assertJsonPath('created', 1);
        $mutate('/routine/engine-generate', ['id' => $period], $officer)->assertOk()->assertJsonPath('created', 0);
        $this->get(self::API."/verified-drafts/$period", $officer)->assertOk()->assertJsonPath('entry_count', 1);
        $mutate('/routine/period-cancel', ['id' => $period, 'reason' => 'Fixture cancelled'], $officer)
            ->assertOk()->assertJsonPath('record.status', 'cancelled');
        $this->get(self::API."/documents/report/$period", $officer)->assertOk()->assertExactJson(['document_id' => $period]);

        $simulation = ['starts_on' => '2026-08-01', 'ends_on' => '2026-08-15', 'frequency' => 'monthly',
            'proration' => 'calendar_days', 'monthly_attendance' => 'not_required', 'hours_per_day' => 8, 'workdays' => [1, 2, 3, 4, 5]];
        $before = DB::table('payroll_entries')->count();
        $response = $this->get(self::API.'/simulation?'.http_build_query($simulation), $officer)->assertOk()->json('records.0');
        $web = app(\App\Services\Payroll\PayrollSimulation::class)->calculate(app(\App\Services\Payroll\PayrollEngine::class), $simulation);
        $this->assertSame($web['rows'][0]['entry']->net_pay_cents, $response['net_cents']);
        $this->assertDatabaseCount('payroll_entries', $before);

        $finalized = $this->cutoff('2026-08-16', '2026-08-31', 'finalized');
        $entry = DB::table('payroll_entries')->insertGetId(['payroll_period_id' => $finalized, 'employee_id' => $employee,
            'base_pay_cents' => 12000, 'snapshot' => '{"net_cents":12000}']);
        $holder = $this->account('employee');
        $holder->update(['employee_id' => $employee]);
        $own = $this->bearer($this->login($holder)->assertOk()->json('tokens'));
        $this->get(self::API.'/documents/payslip', $own)->assertOk()->assertExactJson(['document_id' => (string) $entry]);
        $this->get(self::API.'/documents/payslip', $hr)->assertForbidden();
        $this->get(self::API.'/documents/payslip/999999', $own)->assertNotFound();
        $this->get(self::API."/salary-rate-details?employee_id=$employee", $hr)->assertOk()->assertJsonCount(0, 'records');
        $this->get(self::API.'/audit-events', $hr)->assertForbidden();
        $admin = $this->bearer($this->login($this->account('super_admin'))->assertOk()->json('tokens'));
        $this->get(self::API.'/audit-events', $admin)->assertOk()->assertJsonMissingPath('records.0.before');
    }

    public function test_preparation_and_critical_operations_use_native_rules_and_require_the_bearer_accounts_password(): void
    {
        $hr = $this->account('hr');
        $approver = $this->account('approver');
        $officer = $this->account();
        $hrHeaders = $this->bearer($this->login($hr)->assertOk()->json('tokens'));
        $approverHeaders = $this->bearer($this->login($approver)->assertOk()->json('tokens'));
        $officerHeaders = $this->bearer($this->login($officer)->assertOk()->json('tokens'));
        $department = DB::table('departments')->insertGetId(['name' => 'Fixture']);
        $employee = $this->employee('C-1', $department);
        $period = $this->cutoff('2026-08-01', '2026-08-15', 'draft');
        $this->get(self::API."/preparation/$period", $officerHeaders)->assertOk()
            ->assertJsonPath('record.ready', false)->assertJsonPath('record.checked', 1);
        $this->get(self::API."/preparation/$period", $hrHeaders)->assertForbidden();
        $salary = ['id' => (string) $employee, 'effective_on' => '2026-08-01', 'basis' => 'daily',
            'amount' => '100.00', 'reason' => 'Fixture salary'];
        $preview = self::API.'/pay-input/salary-rate';
        $ticket = $this->postJson($preview.'/preview', $salary, $hrHeaders)->assertOk()->json('ticket');
        $this->postJson($preview.'/execute', $salary + ['ticket' => $ticket], $hrHeaders)->assertUnprocessable();
        $this->postJson($preview.'/execute', $salary + ['ticket' => $ticket, 'password' => 'incorrect'], $hrHeaders)->assertForbidden();
        $this->assertDatabaseCount('salary_rates', 0);
        $this->postJson($preview.'/execute', $salary + ['ticket' => $ticket, 'password' => self::PASSWORD], $hrHeaders)
            ->assertOk()->assertJsonPath('record.amount_cents', 10000);
        $attendance = DB::table('attendance_records')->insertGetId(['employee_id' => $employee, 'work_date' => '2026-08-05',
            'status' => 'present', 'overtime_minutes' => 20]);
        $approval = ['id' => (string) $attendance, 'approved_overtime_minutes' => 10, 'paid_leave' => false, 'reason' => 'Fixture approval'];
        $approve = self::API.'/pay-input/attendance-approve';
        $this->postJson($approve.'/preview', $approval, $hrHeaders)->assertForbidden();
        $this->postJson($approve.'/preview', ['approved_overtime_minutes' => 21] + $approval, $approverHeaders)->assertUnprocessable();
        $ticket = $this->postJson($approve.'/preview', $approval, $approverHeaders)->assertOk()->json('ticket');
        $this->postJson($approve.'/execute', $approval + ['ticket' => $ticket, 'password' => self::PASSWORD], $approverHeaders)
            ->assertOk()->assertJsonPath('record.approved', true);
        $this->get(self::API."/attendance-draft-preview/$period", $officerHeaders)->assertOk()->assertJsonPath('record.eligible', 1);
        $generation = self::API.'/routine/attendance-generate';
        $batch = ['id' => (string) $period];
        $ticket = $this->postJson($generation.'/preview', $batch, $officerHeaders)->assertOk()->json('ticket');
        $this->postJson($generation.'/execute', $batch + ['ticket' => $ticket], $officerHeaders)->assertOk()->assertJsonPath('created', 1);
        DB::table('payroll_periods')->where('id', $period)->update(['status' => 'review']);
        $reject = self::API.'/workflow/reject';
        $payload = $batch + ['reason' => 'Fixture return to draft'];
        $ticket = $this->postJson($reject.'/preview', $payload, $approverHeaders)->assertOk()->json('ticket');
        $this->postJson($reject.'/execute', $payload + ['ticket' => $ticket, 'password' => self::PASSWORD], $approverHeaders)
            ->assertOk()->assertJsonPath('record.status', 'draft');
        $this->assertDatabaseHas('audit_events', ['action' => 'reject', 'actor_id' => $approver->id]);
        $this->assertDatabaseCount('payroll_payments', 0);

        $entry = (string) DB::table('payroll_entries')->where('payroll_period_id', $period)->value('id');
        DB::table('payroll_entries')->where('id', $entry)->update(['source' => 'manual']);
        $this->get(self::API."/recalculation-preview/$entry", $officerHeaders)->assertOk()
            ->assertJsonPath('record.id', $entry);
        $recalculate = self::API.'/entry-workflow/recalculate';
        $ticket = $this->postJson($recalculate.'/preview', ['id' => $entry], $officerHeaders)->assertOk()->json('ticket');
        $this->postJson($recalculate.'/execute', ['id' => $entry, 'ticket' => $ticket, 'password' => self::PASSWORD], $officerHeaders)
            ->assertOk()->assertJsonPath('record.id', $entry);

        $finalized = $this->cutoff('2026-08-16', '2026-08-31', 'finalized');
        DB::table('payroll_periods')->where('id', $finalized)->update(['snapshot' => '{"policy":{"compliance":"manual"},"net_cents":10000}']);
        DB::table('payroll_entries')->insert(['employee_id' => $employee, 'payroll_period_id' => $finalized,
            'base_pay_cents' => 10000, 'snapshot' => '{"totals":{"net_cents":10000}}']);
        $payment = ['id' => (string) $finalized, 'paid_on' => '2026-08-31', 'method' => 'bank',
            'reference' => 'FIXTURE-PAID', 'amount' => '100.00', 'reason' => 'Verified fixture payment'];
        $path = self::API.'/workflow/payment';
        $ticket = $this->postJson($path.'/preview', $payment, $approverHeaders)->assertOk()->json('ticket');
        $this->postJson($path.'/execute', $payment + ['ticket' => $ticket, 'password' => self::PASSWORD], $approverHeaders)
            ->assertOk()->assertJsonPath('payment_verified', true)->assertJsonPath('record.status', 'paid');
        $this->assertDatabaseHas('payroll_payments', ['reference' => 'FIXTURE-PAID', 'confirmed_by' => $approver->id, 'amount_cents' => 10000]);
        $report = $this->get(self::API."/document-files/report/$finalized", $officerHeaders)->assertOk()->assertDownload("payroll-period-$finalized.csv");
        $this->assertStringContainsString('Employee #', $report->streamedContent());
        $this->get(self::API."/document-files/report/$finalized", $hrHeaders)->assertForbidden();

        $admin = $this->bearer($this->login($this->account('super_admin'))->assertOk()->json('tokens'));
        $policy = ['effective_on' => '2026-08-01', 'company_name' => 'Fixture', 'frequency' => 'monthly',
            'proration' => 'calendar_days', 'workdays' => [1, 2, 3, 4, 5], 'hours_per_day' => 8, 'monthly_attendance' => 'not_required',
            'paid_leave' => 'approved_only', 'ot_approval' => 'required', 'contribution_timing' => 'monthly',
            'compliance' => 'manual', 'labor_review' => 'ordinary_only', 'separate_approver' => true, 'reason' => 'Fixture policy change'];
        $path = self::API.'/policy-operations/settings';
        $this->postJson($path.'/preview', ['separate_approver' => false] + $policy, $admin)->assertUnprocessable();
        $ticket = $this->postJson($path.'/preview', $policy, $admin)->assertOk()->json('ticket');
        $this->postJson($path.'/execute', $policy + ['ticket' => $ticket, 'password' => self::PASSWORD], $admin)
            ->assertOk()->assertJsonPath('record.frequency', 'monthly');
        $adjustment = ['type' => 'allowance', 'label' => 'Fixture allowance', 'amount' => '2.00', 'effective_on' => '2026-08-01',
            'recurring' => false, 'taxable' => false, 'contributory' => false, 'reason' => 'Fixture allowance reason'];
        $path = self::API.'/policy-operations/adjustment';
        $ticket = $this->postJson($path.'/preview', $adjustment, $officerHeaders)->assertOk()->json('ticket');
        $this->postJson($path.'/execute', $adjustment + ['ticket' => $ticket, 'password' => self::PASSWORD], $officerHeaders)
            ->assertOk()->assertJsonPath('record.amount_cents', 200);
    }

    public function test_agent_entry_lifecycle_uses_native_approval_and_delivers_official_pdf(): void
    {
        $officer = $this->account();
        $approver = $this->account('approver');
        $hr = $this->account('hr');
        $administrator = $this->account('super_admin');
        $officerHeaders = $this->bearer($this->login($officer)->assertOk()->json('tokens'));
        $approverHeaders = $this->bearer($this->login($approver)->assertOk()->json('tokens'));
        $hrHeaders = $this->bearer($this->login($hr)->assertOk()->json('tokens'));
        $adminHeaders = $this->bearer($this->login($administrator)->assertOk()->json('tokens'));
        \App\Models\PayrollSetting::create(['policy' => PayrollEngineV9Test::policy(['separate_approver' => true]),
            'created_by' => $administrator->id]);
        $department = DB::table('departments')->insertGetId(['name' => 'Synthetic Payroll']);
        $employee = $this->employee('DEMO-1', $department);
        $second = $this->employee('DEMO-2', $department);
        foreach ([$employee, $second] as $id) {
            DB::table('employees')->where('id', $id)->update(['hired_on' => '2026-11-01', 'is_active' => true]);
            \App\Models\Employee::findOrFail($id)->salaryRates()->create(['effective_on' => '2026-11-01', 'basis' => 'monthly',
                'amount_cents' => 2000000, 'reason' => 'Synthetic salary rate', 'created_by' => $administrator->id]);
        }
        $period = $this->cutoff('2026-11-01', '2026-11-30', 'draft');
        $periodArgs = ['id' => (string) $period];
        $entryArgs = ['period_id' => (string) $period, 'employee_id' => (string) $employee];
        $this->getJson(self::API."/entry-employees?period_id=$period&q=DEMO-1", $officerHeaders)->assertOk()
            ->assertJsonPath('records.0.id', (string) $employee)->assertJsonMissingPath('records.0.email');
        $this->getJson(self::API."/entry-employees?period_id=$period", $hrHeaders)->assertForbidden();
        $this->postJson(self::API.'/entry-changes/generate/preview', $entryArgs, $hrHeaders)->assertForbidden();

        $preview = function (string $operation, array $args, array $headers): TestResponse {
            return $this->postJson(self::API."/$operation/preview", $args, $headers)->assertOk();
        };
        $execute = function (string $operation, array $args, array $headers, string $ticket, string $password = self::PASSWORD): TestResponse {
            return $this->postJson(self::API."/$operation/execute", $args + ['ticket' => $ticket, 'password' => $password], $headers);
        };
        $generated = $preview('entry-changes/generate', $entryArgs, $officerHeaders)
            ->assertJsonPath('target.candidate_net_cents', 2000000)->assertJsonPath('target.exceptions', 0);
        $this->assertDatabaseCount('payroll_entries', 0);
        $entry = $execute('entry-changes/generate', $entryArgs, $officerHeaders, $generated->json('ticket'))
            ->assertOk()->assertJsonPath('record.source', 'engine')->json('record.id');
        $execute('entry-changes/generate', $entryArgs, $officerHeaders, $generated->json('ticket'))->assertConflict();
        $this->getJson(self::API."/documents/payslip/$entry", $officerHeaders)->assertNotFound();
        $manual = ['period_id' => (string) $period, 'employee_id' => (string) $second, 'base_pay' => '20000',
            'overtime_pay' => '0', 'allowances' => '10.00', 'deductions' => '0', 'notes' => 'Synthetic manual test amounts'];
        $manualTicket = $preview('entry-changes/create', $manual, $adminHeaders)->json('ticket');
        $manualId = $execute('entry-changes/create', $manual, $adminHeaders, $manualTicket)->assertOk()
            ->assertJsonPath('record.source', 'manual')->json('record.id');
        $edit = ['id' => $manualId, 'allowances' => '20.00', 'notes' => 'Synthetic authorized edit'];
        $ticket = $preview('entry-changes/update', $edit, $adminHeaders)->assertJsonPath('target.candidate_net_cents', 2002000)->json('ticket');
        $execute('entry-changes/update', ['allowances' => '40'] + $edit, $adminHeaders, $ticket)->assertConflict();
        $execute('entry-changes/update', $edit, $adminHeaders, $ticket)->assertOk()->assertJsonPath('record.net_cents', 2002000);
        foreach ([$entry, $manualId] as $id) {
            $review = ['id' => $id];
            $ticket = $preview('entry-workflow/review', $review, $officerHeaders)->json('ticket');
            $execute('entry-workflow/review', $review, $officerHeaders, $ticket)->assertOk()->assertJsonPath('record.reviewed', true);
        }
        $this->postJson(self::API.'/entry-changes/update/preview', $edit, $adminHeaders)->assertUnprocessable();
        $net = $this->getJson(self::API."/workflow-status/$period", $officerHeaders)->assertOk()->json('record.batch_net_cents');
        $batch = $periodArgs + ['confirmed_net' => $net];
        $ticket = $preview('workflow/submit', $batch, $adminHeaders)->json('ticket');
        $execute('workflow/submit', $batch, $adminHeaders, $ticket)->assertOk()->assertJsonPath('record.status', 'review');
        $ticket = $preview('workflow/approve', $periodArgs, $adminHeaders)->json('ticket');
        $execute('workflow/approve', $periodArgs, $adminHeaders, $ticket)->assertUnprocessable();
        $ticket = $preview('workflow/approve', $periodArgs, $approverHeaders)->json('ticket');
        $execute('workflow/approve', $periodArgs, $approverHeaders, $ticket)->assertOk()->assertJsonPath('record.status', 'approved');
        $ticket = $preview('workflow/finalize', $batch, $approverHeaders)->json('ticket');
        $execute('workflow/finalize', $batch, $approverHeaders, $ticket, 'wrong-password')->assertForbidden();
        $execute('workflow/finalize', $batch, $approverHeaders, $ticket)->assertOk()->assertJsonPath('record.status', 'finalized');
        $this->getJson(self::API."/documents/payslip/$entry", $approverHeaders)->assertOk()->assertExactJson(['document_id' => $entry]);
        $response = $this->get(self::API."/document-files/payslip/$entry", $approverHeaders)
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertDownload("payslip-$entry.pdf");
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertNoStore($response);
        $this->get(self::API."/document-files/payslip/$entry", $hrHeaders)->assertForbidden();
        $employeeAccount = $this->account('employee');
        $employeeAccount->update(['employee_id' => $employee]);
        $employeeHeaders = $this->bearer($this->login($employeeAccount)->assertOk()->json('tokens'));
        $this->get(self::API."/document-files/payslip/$manualId", $employeeHeaders)->assertNotFound();
        $this->get(self::API."/document-files/payslip/$entry", $employeeHeaders)->assertOk()->assertDownload("payslip-$entry.pdf");
    }

    private function employee(string $number, int $department): int
    {
        return DB::table('employees')->insertGetId(['employee_number' => $number, 'department_id' => $department,
            'first_name' => 'Fixture', 'last_name' => 'Employee', 'position' => 'Fixture', 'monthly_salary_cents' => 12345]);
    }

    private function account(string $role = 'payroll_officer'): User
    {
        return User::factory()->create(['role' => $role, 'password' => self::PASSWORD]);
    }

    private function login(User $user): TestResponse
    {
        return $this->postJson(self::API.'/auth/login', ['email' => $user->email, 'password' => self::PASSWORD]);
    }

    private function bearer(array $tokens): array
    {
        return ['Authorization' => 'Bearer '.$tokens['access_token']];
    }

    private function cutoff(string $start, string $end, string $status): int
    {
        return DB::table('payroll_periods')->insertGetId([
            'label' => 'Confidential cutoff label',
            'starts_on' => $start,
            'ends_on' => $end,
            'status' => $status,
            'snapshot' => json_encode(['net_cents' => 12500]),
        ]);
    }

    private function assertNoStore(TestResponse $response): void
    {
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }
}
