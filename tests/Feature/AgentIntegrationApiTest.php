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
        $this->login($this->account('hr'))->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->login($this->account('employee'))->assertForbidden();
        $user = $this->account('approver');
        $this->postJson(self::API.'/auth/login', ['email' => $user->email, 'password' => 'invalid'])
            ->assertUnauthorized()->assertExactJson([
                'error' => ['code' => 'unauthenticated', 'message' => 'Invalid credentials or token.'],
            ]);
        $this->assertDatabaseCount('agent_integration_tokens', 0);

        $tokens = $this->login($user)->assertOk()->json('tokens');
        $user->update(['role' => 'hr']);
        $this->get(self::API.'/auth/me', $this->bearer($tokens))->assertForbidden();
        $this->get(self::API.'/payroll-periods', $this->bearer($tokens))->assertForbidden();
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
