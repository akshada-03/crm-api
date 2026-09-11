<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadShowTest extends TestCase
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
        $lead = Lead::factory()->create();

        $this->getJson("/api/leads/{$lead->id}")
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_a_manager_sees_the_lead_with_its_rep_and_activities_newest_first(): void
    {
        $this->travelTo('2026-09-10 12:00:00');

        $lead = Lead::factory()->assignedTo($this->rep)->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '+44 20 7946 0000',
            'company' => null,
            'source' => LeadSource::Event,
            'status' => LeadStatus::Qualified,
            'expected_value' => '9999999999.99',
        ]);
        $call = Activity::factory()->for($lead)->for($this->rep)->create([
            'type' => ActivityType::Call,
            'body' => 'Intro call.',
            'occurred_at' => '2026-09-01 09:00:00',
        ]);
        $meeting = Activity::factory()->for($lead)->for($this->manager)->create([
            'type' => ActivityType::Meeting,
            'body' => 'Product demo.',
            'occurred_at' => '2026-09-05 15:30:00',
        ]);
        $email = Activity::factory()->for($lead)->for($this->rep)->create([
            'type' => ActivityType::Email,
            'body' => 'Sent pricing.',
            'occurred_at' => '2026-09-03 11:00:00',
        ]);
        Activity::factory()->create();

        Sanctum::actingAs($this->manager);

        $this->getJson("/api/leads/{$lead->id}")
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $lead->id,
                    'name' => 'Ada Lovelace',
                    'email' => 'ada@example.com',
                    'phone' => '+44 20 7946 0000',
                    'company' => null,
                    'source' => 'event',
                    'status' => 'qualified',
                    'expected_value' => '9999999999.99',
                    'assigned_rep' => $this->userJson($this->rep),
                    'activities' => [
                        [
                            'id' => $meeting->id,
                            'type' => 'meeting',
                            'body' => 'Product demo.',
                            'occurred_at' => '2026-09-05T15:30:00+00:00',
                            'logged_by' => $this->userJson($this->manager),
                            'created_at' => '2026-09-10T12:00:00+00:00',
                        ],
                        [
                            'id' => $email->id,
                            'type' => 'email',
                            'body' => 'Sent pricing.',
                            'occurred_at' => '2026-09-03T11:00:00+00:00',
                            'logged_by' => $this->userJson($this->rep),
                            'created_at' => '2026-09-10T12:00:00+00:00',
                        ],
                        [
                            'id' => $call->id,
                            'type' => 'call',
                            'body' => 'Intro call.',
                            'occurred_at' => '2026-09-01T09:00:00+00:00',
                            'logged_by' => $this->userJson($this->rep),
                            'created_at' => '2026-09-10T12:00:00+00:00',
                        ],
                    ],
                    'created_at' => '2026-09-10T12:00:00+00:00',
                    'updated_at' => '2026-09-10T12:00:00+00:00',
                ],
            ]);
    }

    public function test_activities_logged_at_the_same_moment_are_listed_latest_logged_first(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();
        $activities = Activity::factory()->count(3)->for($lead)->for($this->rep)->create(['occurred_at' => '2026-09-01 09:00:00']);

        Sanctum::actingAs($this->rep);

        $this->getJson("/api/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('data.activities.*.id', array_reverse($activities->modelKeys()));
    }

    public function test_an_unassigned_lead_without_activities_has_a_null_rep_and_an_empty_timeline(): void
    {
        $lead = Lead::factory()->create();

        Sanctum::actingAs($this->manager);

        $this->getJson("/api/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $lead->id)
            ->assertJsonPath('data.assigned_rep', null)
            ->assertJsonPath('data.activities', []);
    }

    public function test_a_rep_can_view_a_lead_assigned_to_them(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();
        Activity::factory()->for($lead)->for($this->rep)->create();

        Sanctum::actingAs($this->rep);

        $this->getJson("/api/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $lead->id)
            ->assertJsonPath('data.assigned_rep.id', $this->rep->id)
            ->assertJsonCount(1, 'data.activities');
    }

    public function test_a_rep_gets_a_403_for_another_reps_lead(): void
    {
        $lead = Lead::factory()->assignedTo($this->otherRep)->create();

        Sanctum::actingAs($this->rep);

        $this->getJson("/api/leads/{$lead->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'This lead is not assigned to you.');
    }

    public function test_a_rep_gets_a_403_for_an_unassigned_lead(): void
    {
        $lead = Lead::factory()->create();

        Sanctum::actingAs($this->rep);

        $this->getJson("/api/leads/{$lead->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'This lead is not assigned to you.');
    }

    public function test_a_missing_lead_gets_a_404(): void
    {
        Sanctum::actingAs($this->manager);

        $this->getJson('/api/leads/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function userJson(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
        ];
    }
}
