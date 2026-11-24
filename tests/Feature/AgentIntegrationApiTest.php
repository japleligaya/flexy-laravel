<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AgentIntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/integrations/agent';

    private const PASSWORD = 'isolated-test-password';

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('cache.stores.database.connection', 'sqlite');
        $app['config']->set('cache.stores.database.table', 'cache');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        return $app;
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
