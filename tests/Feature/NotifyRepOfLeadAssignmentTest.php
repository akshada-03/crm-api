<?php

namespace Tests\Feature;

use App\Jobs\NotifyRepOfLeadAssignment;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class NotifyRepOfLeadAssignmentTest extends TestCase
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

    public function test_assigning_an_unassigned_lead_queues_a_notification_for_that_rep_and_lead(): void
    {
        Queue::fake();
        $lead = Lead::factory()->create();

        Sanctum::actingAs($this->manager);

        $this->postJson("/api/leads/{$lead->id}/assign", ['assigned_to' => $this->rep->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_rep.id', $this->rep->id);

        $this->assertSame($this->rep->id, $lead->fresh()->assigned_to);
        Queue::assertCount(1);
        Queue::assertPushed(
            NotifyRepOfLeadAssignment::class,
            fn (NotifyRepOfLeadAssignment $job) => $job->rep->is($this->rep) && $job->lead->is($lead),
        );
    }

    public function test_reassigning_a_lead_queues_a_notification_for_the_new_rep(): void
    {
        Queue::fake();
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->manager);

        $this->postJson("/api/leads/{$lead->id}/assign", ['assigned_to' => $this->otherRep->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_rep.id', $this->otherRep->id);

        $this->assertSame($this->otherRep->id, $lead->fresh()->assigned_to);
        Queue::assertCount(1);
        Queue::assertPushed(
            NotifyRepOfLeadAssignment::class,
            fn (NotifyRepOfLeadAssignment $job) => $job->rep->is($this->otherRep) && $job->lead->is($lead),
        );
    }

    public function test_assigning_a_lead_to_the_rep_who_already_has_it_queues_nothing(): void
    {
        Queue::fake();
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->manager);

        $this->postJson("/api/leads/{$lead->id}/assign", ['assigned_to' => $this->rep->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_rep.id', $this->rep->id);

        $this->assertSame($this->rep->id, $lead->fresh()->assigned_to);
        Queue::assertNothingPushed();
    }

    public function test_a_forbidden_assignment_queues_nothing(): void
    {
        Queue::fake();
        $lead = Lead::factory()->create();

        Sanctum::actingAs($this->rep);

        $this->postJson("/api/leads/{$lead->id}/assign", ['assigned_to' => $this->rep->id])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only managers can assign leads.');

        $this->assertNull($lead->fresh()->assigned_to);
        Queue::assertNothingPushed();
    }

    public function test_an_invalid_assignment_queues_nothing(): void
    {
        Queue::fake();
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->manager);

        $this->postJson("/api/leads/{$lead->id}/assign", ['assigned_to' => $this->manager->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_to' => 'The selected user is not a sales rep.']);

        $this->assertSame($this->rep->id, $lead->fresh()->assigned_to);
        Queue::assertNothingPushed();
    }

    public function test_an_assignment_is_stored_on_the_database_queue(): void
    {
        config(['queue.default' => 'database']);
        $lead = Lead::factory()->create();

        Sanctum::actingAs($this->manager);

        $this->postJson("/api/leads/{$lead->id}/assign", ['assigned_to' => $this->rep->id])->assertOk();

        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(
            NotifyRepOfLeadAssignment::class,
            json_decode(DB::table('jobs')->value('payload'), true)['displayName'],
        );
    }

    public function test_the_job_is_queued_only_once_the_surrounding_transaction_commits(): void
    {
        config(['queue.default' => 'database']);
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        DB::transaction(function () use ($lead) {
            NotifyRepOfLeadAssignment::dispatch($this->rep, $lead);

            $this->assertDatabaseCount('jobs', 0);
        });

        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_handling_the_job_logs_that_the_rep_was_notified(): void
    {
        Log::spy();
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        NotifyRepOfLeadAssignment::dispatchSync($this->rep, $lead);

        Log::shouldHaveReceived('info')->once()->with("Rep {$this->rep->id} notified about lead {$lead->id}");
    }

    public function test_a_job_whose_lead_was_deleted_before_it_ran_is_dropped_without_failing(): void
    {
        config(['queue.default' => 'database']);
        Log::spy();
        $lead = Lead::factory()->assignedTo($this->rep)->create();

        NotifyRepOfLeadAssignment::dispatch($this->rep, $lead);
        $lead->delete();

        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');
    }

    public function test_a_job_that_finally_fails_logs_an_error_with_the_exception(): void
    {
        Log::spy();
        $lead = Lead::factory()->assignedTo($this->rep)->create();
        $exception = new RuntimeException('Mail server unreachable.');

        (new NotifyRepOfLeadAssignment($this->rep, $lead))->failed($exception);

        Log::shouldHaveReceived('error')
            ->once()
            ->with("Rep {$this->rep->id} could not be notified about lead {$lead->id}", ['exception' => $exception]);
    }
}
