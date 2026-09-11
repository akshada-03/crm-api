<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeadStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $rep;

    private User $otherRep;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->manager()->create();
        $this->rep = User::factory()->rep()->create();
        $this->otherRep = User::factory()->rep()->create();
    }

    public function test_a_guest_gets_a_401(): void
    {
        $this->postJson('/api/leads', $this->validPayload())
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_a_manager_creates_a_lead_assigned_to_a_rep(): void
    {
        $this->travelTo('2026-09-10 12:00:00');

        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/leads', $this->validPayload(['assigned_to' => $this->rep->id]))
            ->assertCreated();

        $lead = Lead::sole();

        $response->assertExactJson([
            'data' => [
                'id' => $lead->id,
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
                'phone' => '+44 20 7946 0000',
                'company' => 'Analytical Engines Ltd',
                'source' => 'referral',
                'status' => 'new',
                'expected_value' => '12500.50',
                'assigned_rep' => [
                    'id' => $this->rep->id,
                    'name' => $this->rep->name,
                    'email' => $this->rep->email,
                    'role' => 'rep',
                ],
                'created_at' => '2026-09-10T12:00:00+00:00',
                'updated_at' => '2026-09-10T12:00:00+00:00',
            ],
        ]);

        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '+44 20 7946 0000',
            'company' => 'Analytical Engines Ltd',
            'source' => 'referral',
            'status' => 'new',
            'assigned_to' => $this->rep->id,
        ]);
    }

    public function test_a_manager_who_sends_no_assignee_creates_an_unassigned_lead(): void
    {
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/leads', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.assigned_rep', null);

        $this->assertNull(Lead::sole()->assigned_to);
    }

    public function test_a_lead_can_start_in_an_open_status_and_without_a_company(): void
    {
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/leads', $this->validPayload(['status' => 'qualified', 'company' => null]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'qualified')
            ->assertJsonPath('data.company', null);

        $this->assertDatabaseHas('leads', ['status' => 'qualified', 'company' => null]);
    }

    public function test_a_rep_creates_a_lead_that_is_assigned_to_themselves(): void
    {
        Sanctum::actingAs($this->rep);

        $this->postJson('/api/leads', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.assigned_rep.id', $this->rep->id);

        $this->assertSame($this->rep->id, Lead::sole()->assigned_to);
    }

    public function test_a_rep_who_sends_a_null_assignee_still_gets_the_lead(): void
    {
        Sanctum::actingAs($this->rep);

        $this->postJson('/api/leads', $this->validPayload(['assigned_to' => null]))
            ->assertCreated()
            ->assertJsonPath('data.assigned_rep.id', $this->rep->id);

        $this->assertSame($this->rep->id, Lead::sole()->assigned_to);
    }

    public function test_a_rep_cannot_choose_an_assignee_not_even_themselves(): void
    {
        Sanctum::actingAs($this->rep);

        foreach ([$this->otherRep->id, $this->rep->id, 'not-an-id'] as $assignee) {
            $this->postJson('/api/leads', $this->validPayload(['assigned_to' => $assignee]))
                ->assertUnprocessable()
                ->assertOnlyJsonValidationErrors(['assigned_to' => 'Only managers can assign leads.']);
        }

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_a_lead_cannot_be_assigned_to_a_manager(): void
    {
        $otherManager = User::factory()->manager()->create();

        Sanctum::actingAs($this->manager);

        foreach ([$this->manager->id, $otherManager->id] as $assignee) {
            $this->postJson('/api/leads', $this->validPayload(['assigned_to' => $assignee]))
                ->assertUnprocessable()
                ->assertOnlyJsonValidationErrors(['assigned_to' => 'The assigned to user must be an existing rep.']);
        }

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_a_lead_cannot_be_assigned_to_a_user_that_does_not_exist(): void
    {
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/leads', $this->validPayload(['assigned_to' => 999999]))
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['assigned_to' => 'The assigned to user must be an existing rep.']);

        $this->assertDatabaseCount('leads', 0);
    }

    #[DataProvider('closedStatuses')]
    public function test_a_lead_cannot_start_as_won_or_lost(string $status): void
    {
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/leads', $this->validPayload(['status' => $status]))
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'A new lead cannot start as won or lost.',
                'errors' => ['status' => ['A new lead cannot start as won or lost.']],
            ]);

        $this->assertDatabaseCount('leads', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function closedStatuses(): array
    {
        return ['won' => ['won'], 'lost' => ['lost']];
    }

    public function test_required_fields_are_reported_together(): void
    {
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/leads', [])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors([
                'name' => 'The name field is required.',
                'email' => 'The email field is required.',
                'phone' => 'The phone field is required.',
                'source' => 'The source field is required.',
                'expected_value' => 'The expected value field is required.',
            ]);

        $this->assertDatabaseCount('leads', 0);
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidFields')]
    public function test_an_invalid_field_is_rejected_with_a_clear_message(array $override, string $field, string $message): void
    {
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/leads', $this->validPayload($override))
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors([$field => $message]);

        $this->assertDatabaseCount('leads', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidFields(): array
    {
        return [
            'malformed email' => [['email' => 'not-an-email'], 'email', 'The email field must be a valid email address.'],
            'phone too long' => [['phone' => str_repeat('1', 31)], 'phone', 'The phone field must not be greater than 30 characters.'],
            'unknown source' => [['source' => 'billboard'], 'source', 'The source must be one of: web, referral, cold_call, event, other.'],
            'unknown status' => [['status' => 'archived'], 'status', 'The status must be one of: new, contacted, qualified, won, lost.'],
            'value not a number' => [['expected_value' => 'lots'], 'expected_value', 'The expected value field must be a number.'],
            'value with 3 decimals' => [['expected_value' => '10.555'], 'expected_value', 'The expected value must have at most 2 decimal places.'],
            'negative value' => [['expected_value' => '-0.01'], 'expected_value', 'The expected value field must be between 0 and 9999999999.99.'],
            'value above the column maximum' => [['expected_value' => '10000000000.00'], 'expected_value', 'The expected value field must be between 0 and 9999999999.99.'],
        ];
    }

    #[DataProvider('exactValues')]
    public function test_expected_value_is_stored_and_returned_exactly(string|int $sent, string $stored): void
    {
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/leads', $this->validPayload(['expected_value' => $sent]))
            ->assertCreated()
            ->assertJsonPath('data.expected_value', $stored);

        $this->assertSame($stored, Lead::sole()->expected_value);
    }

    /**
     * @return array<string, array{string|int, string}>
     */
    public static function exactValues(): array
    {
        return [
            'zero' => ['0', '0.00'],
            'one cent' => ['0.01', '0.01'],
            'one decimal' => ['12500.5', '12500.50'],
            'integer' => [750, '750.00'],
            'column maximum' => ['9999999999.99', '9999999999.99'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function validPayload(array $override = []): array
    {
        return [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '+44 20 7946 0000',
            'company' => 'Analytical Engines Ltd',
            'source' => 'referral',
            'expected_value' => '12500.50',
            ...$override,
        ];
    }
}
