<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeadAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const NOT_A_REP = 'The selected user is not a sales rep.';

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
        $lead = Lead::factory()->create();

        $this->postJson("/api/leads/{$lead->id}/assign", ['assigned_to' => $this->rep->id])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->assertNull($lead->fresh()->assigned_to);
    }

    public function test_a_manager_assigns_an_unassigned_lead(): void
    {
        $this->travelTo('2026-09-01 09:00:00');
        $lead = Lead::factory()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '+44 20 7946 0000',
            'company' => 'Analytical Engines Ltd',
            'source' => 'referral',
            'expected_value' => '12500.50',
        ]);

        $this->travelTo('2026-09-10 12:00:00');
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/leads/{$lead->id}/assign", ['assigned_to' => $this->rep->id])
            ->assertOk()
            ->assertExactJson([
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
                    'created_at' => '2026-09-01T09:00:00+00:00',
                    'updated_at' => '2026-09-10T12:00:00+00:00',
                ],
            ]);

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'assigned_to' => $this->rep->id, 'status' => 'new']);
    }

    public function test_a_manager_reassigns_a_lead_to_another_rep(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();
        $untouched = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->manager);

        $this->postJson("/api/leads/{$lead->id}/assign", ['assigned_to' => $this->otherRep->id])
            ->assertOk()
            ->assertJsonPath('data.id', $lead->id)
            ->assertJsonPath('data.assigned_rep.id', $this->otherRep->id);

        $this->assertSame($this->otherRep->id, $lead->fresh()->assigned_to);
        $this->assertSame($this->rep->id, $untouched->fresh()->assigned_to);
    }

    public function test_access_moves_to_the_new_rep_after_reassignment(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/leads/{$lead->id}/assign", ['assigned_to' => $this->otherRep->id])->assertOk();

        Sanctum::actingAs($this->rep);
        $this->getJson("/api/leads/{$lead->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'This lead is not assigned to you.');

        Sanctum::actingAs($this->otherRep);
        $this->getJson("/api/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $lead->id)
            ->assertJsonPath('data.assigned_rep.id', $this->otherRep->id);
    }

    public function test_a_rep_gets_a_403_even_for_their_own_lead_and_nothing_changes(): void
    {
        $ownLead = Lead::factory()->assignedTo($this->rep)->create();
        $otherRepsLead = Lead::factory()->assignedTo($this->otherRep)->create();
        $unassignedLead = Lead::factory()->create();

        Sanctum::actingAs($this->rep);

        foreach ([$ownLead, $otherRepsLead, $unassignedLead] as $lead) {
            $this->postJson("/api/leads/{$lead->id}/assign", ['assigned_to' => $this->rep->id])
                ->assertForbidden()
                ->assertJsonPath('message', 'Only managers can assign leads.');
        }

        $this->assertSame($this->rep->id, $ownLead->fresh()->assigned_to);
        $this->assertSame($this->otherRep->id, $otherRepsLead->fresh()->assigned_to);
        $this->assertNull($unassignedLead->fresh()->assigned_to);
    }

    public function test_a_rep_is_denied_before_their_input_is_validated(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->rep);

        $this->postJson("/api/leads/{$lead->id}/assign", [])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only managers can assign leads.');
    }

    public function test_a_lead_cannot_be_assigned_to_a_manager(): void
    {
        $otherManager = User::factory()->manager()->create();
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->manager);

        foreach ([$this->manager->id, $otherManager->id] as $assignee) {
            $this->postJson("/api/leads/{$lead->id}/assign", ['assigned_to' => $assignee])
                ->assertUnprocessable()
                ->assertExactJson([
                    'message' => self::NOT_A_REP,
                    'errors' => ['assigned_to' => [self::NOT_A_REP]],
                ]);
        }

        $this->assertSame($this->rep->id, $lead->fresh()->assigned_to);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidAssignees')]
    public function test_an_invalid_assignee_is_rejected_and_nothing_changes(array $payload, string $message): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->manager);

        $this->postJson("/api/leads/{$lead->id}/assign", $payload)
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['assigned_to' => $message]);

        $this->assertSame($this->rep->id, $lead->fresh()->assigned_to);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidAssignees(): array
    {
        return [
            'nonexistent user' => [['assigned_to' => 999999], self::NOT_A_REP],
            'missing' => [[], 'The assigned to field is required.'],
            'null' => [['assigned_to' => null], 'The assigned to field is required.'],
            'not an id' => [['assigned_to' => 'someone'], 'The assigned to field must be an integer.'],
        ];
    }

    public function test_a_missing_lead_gets_a_404(): void
    {
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/leads/999999/assign', ['assigned_to' => $this->rep->id])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.']);
    }
}
