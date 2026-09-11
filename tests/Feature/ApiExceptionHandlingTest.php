<?php

namespace Tests\Feature;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiExceptionHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum'])
            ->get('api/test/users/{user}', fn (User $user) => new UserResource($user));
    }

    public function test_an_unauthenticated_api_request_gets_a_401_json_response_without_an_accept_header(): void
    {
        $user = User::factory()->create();

        $this->get("/api/test/users/{$user->id}")
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_an_invalid_token_is_treated_as_unauthenticated(): void
    {
        $user = User::factory()->create();

        $this->withToken('1|not-a-real-token')
            ->getJson("/api/test/users/{$user->id}")
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_a_missing_model_returns_a_generic_404_that_does_not_leak_the_model_class(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->get('/api/test/users/999')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    public function test_an_unknown_api_route_renders_json_without_an_accept_header(): void
    {
        $this->get('/api/does-not-exist')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }
}
