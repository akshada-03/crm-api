<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeadActivityTest extends TestCase
{
    use RefreshDatabase;

    private const IN_THE_FUTURE = 'The occurred at date cannot be in the future.';

    private User $manager;

    private User $rep;

    private User $otherRep;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->manager()->create();
        $this->rep = User::factory()->rep()->create();
        $this->otherRep = User::factory()->rep()->create();

        $this->travelTo('2026-09-10 12:00:00');
    }

    public function test_a_guest_gets_a_401(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        $this->postJson("/api/leads/{$lead->id}/activities", $this->validPayload())
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->assertDatabaseCount('activities', 0);
    }

    public function test_a_rep_logs_an_activity_on_their_own_lead(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->rep);

        $response = $this->postJson("/api/leads/{$lead->id}/activities", $this->validPayload())
            ->assertCreated();

        $activity = Activity::sole();

        $response->assertExactJson([
            'data' => [
                'id' => $activity->id,
                'type' => 'call',
                'body' => 'Intro call, interested in the annual plan.',
                'occurred_at' => '2026-09-09T15:30:00+00:00',
                'logged_by' => [
                    'id' => $this->rep->id,
                    'name' => $this->rep->name,
                    'email' => $this->rep->email,
                    'role' => 'rep',
                ],
                'created_at' => '2026-09-10T12:00:00+00:00',
            ],
        ]);

        $this->assertDatabaseHas('activities', [
            'id' => $activity->id,
            'lead_id' => $lead->id,
            'user_id' => $this->rep->id,
            'type' => 'call',
            'body' => 'Intro call, interested in the annual plan.',
            'occurred_at' => '2026-09-09 15:30:00',
        ]);
    }

    public function test_the_author_comes_from_the_token_and_never_from_the_input(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->rep);

        $this->postJson("/api/leads/{$lead->id}/activities", $this->validPayload(['user_id' => $this->otherRep->id]))
            ->assertCreated()
            ->assertJsonPath('data.logged_by.id', $this->rep->id);

        $this->assertSame($this->rep->id, Activity::sole()->user_id);
    }

    public function test_a_manager_logs_an_activity_on_any_lead_as_themselves(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->manager);

        $this->postJson("/api/leads/{$lead->id}/activities", $this->validPayload(['type' => 'meeting']))
            ->assertCreated()
            ->assertJsonPath('data.type', 'meeting')
            ->assertJsonPath('data.logged_by.id', $this->manager->id);

        $activity = Activity::sole();
        $this->assertSame($lead->id, $activity->lead_id);
        $this->assertSame($this->manager->id, $activity->user_id);
        $this->assertSame(ActivityType::Meeting, $activity->type);
    }

    public function test_a_rep_gets_a_403_for_another_reps_lead_or_an_unassigned_one(): void
    {
        $otherRepsLead = Lead::factory()->assignedTo($this->otherRep)->create();
        $unassignedLead = Lead::factory()->create();

        Sanctum::actingAs($this->rep);

        foreach ([$otherRepsLead, $unassignedLead] as $lead) {
            $this->postJson("/api/leads/{$lead->id}/activities", $this->validPayload())
                ->assertForbidden()
                ->assertJsonPath('message', 'This lead is not assigned to you.');
        }

        $this->assertDatabaseCount('activities', 0);
    }

    public function test_a_missing_lead_gets_a_404(): void
    {
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/leads/999999/activities', $this->validPayload())
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('omittedOccurredAt')]
    public function test_occurred_at_defaults_to_now(array $payload): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->rep);

        $this->postJson("/api/leads/{$lead->id}/activities", $payload)
            ->assertCreated()
            ->assertJsonPath('data.occurred_at', '2026-09-10T12:00:00+00:00');

        $this->assertDatabaseHas('activities', ['lead_id' => $lead->id, 'occurred_at' => '2026-09-10 12:00:00']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function omittedOccurredAt(): array
    {
        return [
            'missing' => [['type' => 'note', 'body' => 'Left a voicemail.']],
            'null' => [['type' => 'note', 'body' => 'Left a voicemail.', 'occurred_at' => null]],
        ];
    }

    public function test_the_current_moment_is_not_in_the_future(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->rep);

        $this->postJson("/api/leads/{$lead->id}/activities", $this->validPayload(['occurred_at' => '2026-09-10T12:00:00Z']))
            ->assertCreated()
            ->assertJsonPath('data.occurred_at', '2026-09-10T12:00:00+00:00');
    }

    public function test_an_occurred_at_with_an_offset_is_stored_in_utc(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->rep);

        $this->postJson("/api/leads/{$lead->id}/activities", $this->validPayload(['occurred_at' => '2026-09-10T10:00:00+02:00']))
            ->assertCreated()
            ->assertJsonPath('data.occurred_at', '2026-09-10T08:00:00+00:00');

        $this->assertDatabaseHas('activities', ['lead_id' => $lead->id, 'occurred_at' => '2026-09-10 08:00:00']);
    }

    public function test_the_future_check_compares_instants_not_wall_clock_times(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->rep);

        // 12:30 on the wall clock but 10:30 UTC, so in the past.
        $this->postJson("/api/leads/{$lead->id}/activities", $this->validPayload(['occurred_at' => '2026-09-10T12:30:00+02:00']))
            ->assertCreated();

        // 11:30 on the wall clock but 13:30 UTC, so in the future.
        $this->postJson("/api/leads/{$lead->id}/activities", $this->validPayload(['occurred_at' => '2026-09-10T11:30:00-02:00']))
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['occurred_at' => self::IN_THE_FUTURE]);

        $this->assertDatabaseCount('activities', 1);
    }

    public function test_required_fields_are_reported_together(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->rep);

        $this->postJson("/api/leads/{$lead->id}/activities", [])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors([
                'type' => 'The type field is required.',
                'body' => 'The body field is required.',
            ]);

        $this->assertDatabaseCount('activities', 0);
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidFields')]
    public function test_an_invalid_field_is_rejected_with_a_clear_message(array $override, string $field, string $message): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->rep);

        $this->postJson("/api/leads/{$lead->id}/activities", $this->validPayload($override))
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors([$field => $message]);

        $this->assertDatabaseCount('activities', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidFields(): array
    {
        return [
            'unknown type' => [['type' => 'sms'], 'type', 'The type must be one of: call, email, meeting, note.'],
            'body not a string' => [['body' => ['text']], 'body', 'The body field must be a string.'],
            'body too long' => [['body' => str_repeat('a', 5001)], 'body', 'The body field must not be greater than 5000 characters.'],
            'occurred_at one second in the future' => [['occurred_at' => '2026-09-10 12:00:01'], 'occurred_at', self::IN_THE_FUTURE],
            'occurred_at not a date' => [['occurred_at' => 'sometime last week'], 'occurred_at', 'The occurred at field must be a valid date.'],
        ];
    }

    public function test_a_body_of_exactly_5000_characters_is_accepted(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->rep);

        $this->postJson("/api/leads/{$lead->id}/activities", $this->validPayload(['body' => str_repeat('a', 5000)]))
            ->assertCreated();

        $this->assertSame(5000, strlen(Activity::sole()->body));
    }

    public function test_a_lead_can_be_marked_won_once_an_activity_is_logged(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->withStatus(LeadStatus::Qualified)->create();

        Sanctum::actingAs($this->rep);

        $this->patchJson("/api/leads/{$lead->id}", ['status' => 'won'])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors([
                'status' => 'A lead can only be marked won or lost after at least one activity has been logged.',
            ]);
        $this->assertSame(LeadStatus::Qualified, $lead->fresh()->status);

        $this->postJson("/api/leads/{$lead->id}/activities", $this->validPayload(['type' => 'meeting', 'body' => 'Contract signed.']))
            ->assertCreated();

        $this->patchJson("/api/leads/{$lead->id}", ['status' => 'won'])
            ->assertOk()
            ->assertJsonPath('data.status', 'won');
        $this->assertSame(LeadStatus::Won, $lead->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function validPayload(array $override = []): array
    {
        return [
            'type' => 'call',
            'body' => 'Intro call, interested in the annual plan.',
            'occurred_at' => '2026-09-09T15:30:00+00:00',
            ...$override,
        ];
    }
}
