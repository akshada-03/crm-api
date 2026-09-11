<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const NOT_ASSIGNED = 'This lead is not assigned to you.';

    private const MANAGERS_ONLY = 'Only managers can assign leads.';

    /**
     * The abilities a rep has on their own lead and nowhere else.
     */
    private const OWNER_ABILITIES = ['view', 'update', 'logActivity'];

    private User $manager;

    private User $rep;

    private User $otherRep;

    private Lead $ownLead;

    private Lead $otherRepsLead;

    private Lead $unassignedLead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->manager()->create();
        $this->rep = User::factory()->rep()->create();
        $this->otherRep = User::factory()->rep()->create();

        $this->ownLead = Lead::factory()->assignedTo($this->rep)->create();
        $this->otherRepsLead = Lead::factory()->assignedTo($this->otherRep)->create();
        $this->unassignedLead = Lead::factory()->create();
    }

    public function test_managers_and_reps_can_list_and_create_leads(): void
    {
        foreach ([$this->manager, $this->rep] as $user) {
            $this->assertTrue(Gate::forUser($user)->allows('viewAny', Lead::class));
            $this->assertTrue(Gate::forUser($user)->allows('create', Lead::class));
        }
    }

    public function test_a_manager_can_view_update_assign_and_log_activity_on_every_lead(): void
    {
        foreach ([$this->ownLead, $this->otherRepsLead, $this->unassignedLead] as $lead) {
            foreach ([...self::OWNER_ABILITIES, 'assign'] as $ability) {
                $this->assertAllowed($this->manager, $ability, $lead);
            }
        }
    }

    public function test_a_rep_can_view_update_and_log_activity_on_their_own_lead(): void
    {
        foreach (self::OWNER_ABILITIES as $ability) {
            $this->assertAllowed($this->rep, $ability, $this->ownLead);
        }
    }

    public function test_a_rep_cannot_view_update_or_log_activity_on_another_reps_lead(): void
    {
        foreach (self::OWNER_ABILITIES as $ability) {
            $this->assertDenied($this->rep, $ability, $this->otherRepsLead, self::NOT_ASSIGNED);
        }
    }

    public function test_a_rep_cannot_view_update_or_log_activity_on_an_unassigned_lead(): void
    {
        foreach (self::OWNER_ABILITIES as $ability) {
            $this->assertDenied($this->rep, $ability, $this->unassignedLead, self::NOT_ASSIGNED);
        }
    }

    public function test_a_rep_cannot_assign_any_lead_not_even_their_own(): void
    {
        foreach ([$this->ownLead, $this->otherRepsLead, $this->unassignedLead] as $lead) {
            $this->assertDenied($this->rep, 'assign', $lead, self::MANAGERS_ONLY);
        }
    }

    public function test_access_follows_the_current_assignee(): void
    {
        $this->ownLead->update(['assigned_to' => $this->otherRep->id]);

        $this->assertDenied($this->rep, 'view', $this->ownLead, self::NOT_ASSIGNED);
        $this->assertAllowed($this->otherRep, 'view', $this->ownLead);
    }

    public function test_a_denial_renders_as_a_403_json_response_carrying_the_policy_message(): void
    {
        Route::middleware(['api', 'auth:sanctum'])->group(function () {
            Route::get('api/test/leads/{lead}', function (Lead $lead) {
                Gate::authorize('view', $lead);

                return response()->noContent();
            });
            Route::post('api/test/leads/{lead}/assign', function (Lead $lead) {
                Gate::authorize('assign', $lead);

                return response()->noContent();
            });
        });

        Sanctum::actingAs($this->rep);

        $this->getJson("/api/test/leads/{$this->ownLead->id}")->assertNoContent();

        $this->getJson("/api/test/leads/{$this->otherRepsLead->id}")
            ->assertForbidden()
            ->assertJsonPath('message', self::NOT_ASSIGNED);

        $this->postJson("/api/test/leads/{$this->ownLead->id}/assign")
            ->assertForbidden()
            ->assertJsonPath('message', self::MANAGERS_ONLY);
    }

    public function test_lead_visible_to_a_manager_returns_every_lead(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->ownLead->id, $this->otherRepsLead->id, $this->unassignedLead->id],
            Lead::visibleTo($this->manager)->pluck('id')->all(),
        );
    }

    public function test_lead_visible_to_a_rep_returns_only_the_leads_assigned_to_them(): void
    {
        $secondOwnLead = Lead::factory()->assignedTo($this->rep)->create();

        $this->assertEqualsCanonicalizing(
            [$this->ownLead->id, $secondOwnLead->id],
            Lead::visibleTo($this->rep)->pluck('id')->all(),
        );
    }

    public function test_lead_visible_to_a_rep_with_no_leads_returns_nothing(): void
    {
        $newRep = User::factory()->rep()->create();

        $this->assertSame(0, Lead::visibleTo($newRep)->count());
    }

    public function test_user_visible_to_a_manager_returns_every_rep_and_no_managers(): void
    {
        User::factory()->manager()->create();

        $this->assertEqualsCanonicalizing(
            [$this->rep->id, $this->otherRep->id],
            User::visibleTo($this->manager)->pluck('id')->all(),
        );
    }

    public function test_user_visible_to_a_rep_returns_only_themselves(): void
    {
        $this->assertSame([$this->rep->id], User::visibleTo($this->rep)->pluck('id')->all());
    }

    private function assertAllowed(User $user, string $ability, Lead $lead): void
    {
        $this->assertTrue(
            Gate::forUser($user)->inspect($ability, $lead)->allowed(),
            "Expected {$user->role->value} #{$user->id} to be allowed to {$ability} lead #{$lead->id}.",
        );
    }

    private function assertDenied(User $user, string $ability, Lead $lead, string $message): void
    {
        $response = Gate::forUser($user)->inspect($ability, $lead);

        $this->assertTrue(
            $response->denied(),
            "Expected {$user->role->value} #{$user->id} to be denied {$ability} on lead #{$lead->id}.",
        );
        $this->assertSame($message, $response->message());
    }
}
