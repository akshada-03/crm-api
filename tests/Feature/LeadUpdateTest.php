<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeadUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const NEEDS_ACTIVITY = 'A lead can only be marked won or lost after at least one activity has been logged.';

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
        $lead = Lead::factory()->create(['name' => 'Ada Lovelace']);

        $this->patchJson("/api/leads/{$lead->id}", ['name' => 'Grace Hopper'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->assertSame('Ada Lovelace', $lead->fresh()->name);
    }

    public function test_a_manager_updates_every_field(): void
    {
        $this->travelTo('2026-09-01 09:00:00');
        $lead = Lead::factory()->assignedTo($this->rep)->create(['company' => 'Old Co']);

        $this->travelTo('2026-09-10 12:00:00');
        Sanctum::actingAs($this->manager);

        $this->patchJson("/api/leads/{$lead->id}", [
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
            'phone' => '+1 202-555-0100',
            'company' => null,
            'source' => 'event',
            'status' => 'contacted',
            'expected_value' => '9999999999.99',
        ])
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $lead->id,
                    'name' => 'Grace Hopper',
                    'email' => 'grace@example.com',
                    'phone' => '+1 202-555-0100',
                    'company' => null,
                    'source' => 'event',
                    'status' => 'contacted',
                    'expected_value' => '9999999999.99',
                    'assigned_rep' => [
                        'id' => $this->rep->id,
                        'name' => $this->rep->name,
                        'email' => $this->rep->email,
                        'role' => 'rep',
                    ],
                    'created_at' => '2026-09-01T09:00:00+00:00',
                    'updated_at' => '2026-09-10T12:00:00+00:00',
                ],
            ]);

        $lead->refresh();
        $this->assertSame('Grace Hopper', $lead->name);
        $this->assertSame('grace@example.com', $lead->email);
        $this->assertSame('+1 202-555-0100', $lead->phone);
        $this->assertNull($lead->company);
        $this->assertSame(LeadSource::Event, $lead->source);
        $this->assertSame(LeadStatus::Contacted, $lead->status);
        $this->assertSame('9999999999.99', $lead->expected_value);
        $this->assertSame($this->rep->id, $lead->assigned_to);
    }

    public function test_only_the_fields_sent_are_changed(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create([
            'name' => 'Ada Lovelace',
            'company' => 'Analytical Engines Ltd',
            'expected_value' => '12500.50',
        ]);

        Sanctum::actingAs($this->rep);

        $this->patchJson("/api/leads/{$lead->id}", ['phone' => '+44 20 7946 0999'])
            ->assertOk()
            ->assertJsonPath('data.phone', '+44 20 7946 0999')
            ->assertJsonPath('data.name', 'Ada Lovelace');

        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'name' => 'Ada Lovelace',
            'phone' => '+44 20 7946 0999',
            'company' => 'Analytical Engines Ltd',
            'status' => 'new',
        ]);
        $this->assertSame('12500.50', $lead->fresh()->expected_value);
    }

    public function test_a_rep_gets_a_403_for_another_reps_lead_and_nothing_changes(): void
    {
        $lead = Lead::factory()->assignedTo($this->otherRep)->create(['name' => 'Ada Lovelace']);

        Sanctum::actingAs($this->rep);

        $this->patchJson("/api/leads/{$lead->id}", ['name' => 'Grace Hopper'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This lead is not assigned to you.');

        $this->assertSame('Ada Lovelace', $lead->fresh()->name);
    }

    public function test_a_rep_gets_a_403_for_an_unassigned_lead(): void
    {
        $lead = Lead::factory()->create(['name' => 'Ada Lovelace']);

        Sanctum::actingAs($this->rep);

        $this->patchJson("/api/leads/{$lead->id}", ['name' => 'Grace Hopper'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This lead is not assigned to you.');

        $this->assertSame('Ada Lovelace', $lead->fresh()->name);
    }

    public function test_a_missing_lead_gets_a_404(): void
    {
        Sanctum::actingAs($this->manager);

        $this->patchJson('/api/leads/999999', ['name' => 'Grace Hopper'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    #[DataProvider('closedStatuses')]
    public function test_a_lead_without_activities_cannot_be_marked_won_or_lost(string $status): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->withStatus(LeadStatus::Qualified)->create();

        Sanctum::actingAs($this->rep);

        $this->patchJson("/api/leads/{$lead->id}", ['status' => $status])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => self::NEEDS_ACTIVITY,
                'errors' => ['status' => [self::NEEDS_ACTIVITY]],
            ]);

        $this->assertSame(LeadStatus::Qualified, $lead->fresh()->status);
    }

    #[DataProvider('closedStatuses')]
    public function test_activities_on_other_leads_do_not_count(string $status): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();
        Activity::factory()->for(Lead::factory()->assignedTo($this->rep))->for($this->rep)->create();

        Sanctum::actingAs($this->rep);

        $this->patchJson("/api/leads/{$lead->id}", ['status' => $status])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['status' => self::NEEDS_ACTIVITY]);

        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
    }

    #[DataProvider('closedStatuses')]
    public function test_a_lead_with_an_activity_can_be_marked_won_or_lost(string $status): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->withStatus(LeadStatus::Qualified)->create();
        Activity::factory()->for($lead)->for($this->rep)->create();

        Sanctum::actingAs($this->rep);

        $this->patchJson("/api/leads/{$lead->id}", ['status' => $status])
            ->assertOk()
            ->assertJsonPath('data.status', $status);

        $this->assertSame(LeadStatus::from($status), $lead->fresh()->status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function closedStatuses(): array
    {
        return ['won' => ['won'], 'lost' => ['lost']];
    }

    #[DataProvider('openStatuses')]
    public function test_moving_to_an_open_status_needs_no_activity(string $status): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->withStatus(LeadStatus::Contacted)->create();

        Sanctum::actingAs($this->rep);

        $this->patchJson("/api/leads/{$lead->id}", ['status' => $status])
            ->assertOk()
            ->assertJsonPath('data.status', $status);

        $this->assertSame(LeadStatus::from($status), $lead->fresh()->status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function openStatuses(): array
    {
        return ['new' => ['new'], 'contacted' => ['contacted'], 'qualified' => ['qualified']];
    }

    #[DataProvider('invalidStatuses')]
    public function test_an_invalid_status_gets_only_its_own_error_not_the_won_lost_one(mixed $status): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->rep);

        $this->patchJson("/api/leads/{$lead->id}", ['status' => $status])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The status must be one of: new, contacted, qualified, won, lost.',
                'errors' => ['status' => ['The status must be one of: new, contacted, qualified, won, lost.']],
            ]);

        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidStatuses(): array
    {
        return [
            'unknown value' => ['archived'],
            'null' => [null],
            'array holding a closed status' => [['won']],
        ];
    }

    public function test_the_won_lost_rule_is_reported_alongside_other_field_errors(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create(['name' => 'Ada Lovelace']);

        Sanctum::actingAs($this->rep);

        $this->patchJson("/api/leads/{$lead->id}", ['name' => '', 'status' => 'won'])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors([
                'name' => 'The name field is required.',
                'status' => self::NEEDS_ACTIVITY,
            ]);

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'name' => 'Ada Lovelace', 'status' => 'new']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidFields')]
    public function test_an_invalid_field_is_rejected_and_nothing_changes(array $payload, string $field, string $message): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create(['name' => 'Ada Lovelace', 'expected_value' => '100.00']);

        Sanctum::actingAs($this->manager);

        $this->patchJson("/api/leads/{$lead->id}", $payload)
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors([$field => $message]);

        $lead->refresh();
        $this->assertSame('Ada Lovelace', $lead->name);
        $this->assertSame('100.00', $lead->expected_value);
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidFields(): array
    {
        return [
            'blank name' => [['name' => ''], 'name', 'The name field is required.'],
            'null expected value' => [['expected_value' => null], 'expected_value', 'The expected value field is required.'],
            'value with 3 decimals' => [['expected_value' => '10.555'], 'expected_value', 'The expected value must have at most 2 decimal places.'],
            'unknown source' => [['source' => 'billboard'], 'source', 'The source must be one of: web, referral, cold_call, event, other.'],
        ];
    }

    public function test_assigned_to_is_rejected_because_assignment_has_its_own_endpoint(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->manager);

        foreach ([$this->otherRep->id, null] as $assignee) {
            $this->patchJson("/api/leads/{$lead->id}", ['assigned_to' => $assignee])
                ->assertUnprocessable()
                ->assertOnlyJsonValidationErrors(['assigned_to' => 'Leads are assigned through POST /api/leads/{lead}/assign.']);
        }

        $this->assertSame($this->rep->id, $lead->fresh()->assigned_to);
    }

    public function test_put_is_not_supported(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->manager);

        $this->putJson("/api/leads/{$lead->id}", ['name' => 'Grace Hopper'])
            ->assertMethodNotAllowed();
    }
}
