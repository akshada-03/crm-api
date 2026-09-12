<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const INVALID_CREDENTIALS = 'The provided credentials are incorrect.';

    public function test_a_user_can_log_in_and_receives_a_bearer_token(): void
    {
        $manager = User::factory()->manager()->create([
            'name' => 'Maya Manager',
            'email' => 'maya@example.com',
            'password' => 'secret-password',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'maya@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->has('data', fn (AssertableJson $data) => $data
                    ->whereType('token', 'string')
                    ->where('token_type', 'Bearer')
                    ->where('user', [
                        'id' => $manager->id,
                        'name' => 'Maya Manager',
                        'email' => 'maya@example.com',
                        'role' => 'manager',
                    ])
                )
            );

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $manager->id,
        ]);

        $token = PersonalAccessToken::findToken($response->json('data.token'));
        $this->assertNotNull($token);
        $this->assertTrue($token->tokenable->is($manager));
    }

    public function test_a_wrong_password_is_rejected_without_issuing_a_token(): void
    {
        User::factory()->create(['email' => 'rep@example.com', 'password' => 'secret-password']);

        $this->postJson('/api/login', ['email' => 'rep@example.com', 'password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => self::INVALID_CREDENTIALS,
                'errors' => ['email' => [self::INVALID_CREDENTIALS]],
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_unknown_email_gets_the_same_response_as_a_wrong_password(): void
    {
        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'secret-password'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => self::INVALID_CREDENTIALS,
                'errors' => ['email' => [self::INVALID_CREDENTIALS]],
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * Without the hash on the unknown-email path, that failure would return measurably faster
     * than a wrong password, and response times would reveal which emails have an account.
     */
    public function test_an_unknown_email_costs_one_password_hash_just_like_a_wrong_password(): void
    {
        User::factory()->create(['email' => 'rep@example.com', 'password' => 'secret-password']);
        Hash::spy();

        $this->postJson('/api/login', ['email' => 'rep@example.com', 'password' => 'wrong-password'])
            ->assertUnprocessable();
        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
            ->assertUnprocessable();

        Hash::shouldHaveReceived('check')->once();
        Hash::shouldHaveReceived('make')->once()->with('wrong-password');
    }

    public function test_missing_fields_return_json_validation_errors_even_without_an_accept_header(): void
    {
        $this->post('/api/login')
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'message' => 'The email field is required. (and 1 more error)',
                'errors' => [
                    'email' => ['The email field is required.'],
                    'password' => ['The password field is required.'],
                ],
            ]);
    }

    public function test_an_invalid_email_format_is_rejected(): void
    {
        $this->postJson('/api/login', ['email' => 'not-an-email', 'password' => 'secret-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'The email field must be a valid email address.'])
            ->assertJsonMissingValidationErrors('password');
    }

    public function test_a_non_string_email_is_a_validation_error_not_a_server_error(): void
    {
        $this->postJson('/api/login', ['email' => ['rep@example.com'], 'password' => 'secret-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'The email field must be a valid email address.']);
    }

    public function test_login_is_throttled_after_five_attempts_per_email_and_ip(): void
    {
        $credentials = ['email' => 'rep@example.com', 'password' => 'wrong-password'];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/login', $credentials)->assertUnprocessable();
        }

        $this->postJson('/api/login', $credentials)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too Many Attempts.');

        $this->postJson('/api/login', ['email' => 'REP@EXAMPLE.COM', 'password' => 'wrong-password'])
            ->assertTooManyRequests();
    }

    public function test_the_throttle_is_scoped_to_the_email_and_ip_pair(): void
    {
        $credentials = ['email' => 'rep@example.com', 'password' => 'wrong-password'];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/login', $credentials)->assertUnprocessable();
        }

        $this->postJson('/api/login', $credentials)->assertTooManyRequests();

        $this->postJson('/api/login', ['email' => 'other@example.com', 'password' => 'wrong-password'])
            ->assertUnprocessable();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson('/api/login', $credentials)
            ->assertUnprocessable();
    }
}
