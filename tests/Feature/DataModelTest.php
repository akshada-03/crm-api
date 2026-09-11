<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_lead_belongs_to_its_assigned_rep_who_has_many_assigned_leads(): void
    {
        $rep = User::factory()->rep()->create();
        $leads = Lead::factory()->count(2)->assignedTo($rep)->create();
        Lead::factory()->create();

        $this->assertTrue($leads->first()->assignedRep->is($rep));
        $this->assertEqualsCanonicalizing($leads->modelKeys(), $rep->assignedLeads->modelKeys());
    }

    public function test_an_activity_belongs_to_its_lead_and_the_user_who_logged_it(): void
    {
        $rep = User::factory()->rep()->create();
        $lead = Lead::factory()->assignedTo($rep)->create();
        $activity = Activity::factory()->for($lead)->for($rep)->create();

        $this->assertTrue($activity->lead->is($lead));
        $this->assertTrue($activity->user->is($rep));
        $this->assertSame([$activity->id], $lead->activities->modelKeys());
        $this->assertSame([$activity->id], $rep->activities->modelKeys());
    }

    public function test_enum_columns_are_stored_as_strings_and_cast_to_enums(): void
    {
        $manager = User::factory()->manager()->create();
        $lead = Lead::factory()->create([
            'source' => LeadSource::ColdCall,
            'status' => LeadStatus::Qualified,
        ]);
        $activity = Activity::factory()->for($lead)->for($manager)->create(['type' => ActivityType::Meeting]);

        $this->assertDatabaseHas('users', ['id' => $manager->id, 'role' => 'manager']);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'source' => 'cold_call', 'status' => 'qualified']);
        $this->assertDatabaseHas('activities', ['id' => $activity->id, 'type' => 'meeting']);

        $this->assertSame(UserRole::Manager, $manager->fresh()->role);
        $this->assertSame(LeadSource::ColdCall, $lead->fresh()->source);
        $this->assertSame(LeadStatus::Qualified, $lead->fresh()->status);
        $this->assertSame(ActivityType::Meeting, $activity->fresh()->type);
    }

    public function test_is_manager_follows_the_role_column(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->rep()->create();

        $this->assertTrue($manager->isManager());
        $this->assertFalse($rep->isManager());
    }

    public function test_only_won_and_lost_are_closed_statuses(): void
    {
        $closed = array_filter(LeadStatus::cases(), fn (LeadStatus $status) => $status->isClosed());

        $this->assertEqualsCanonicalizing([LeadStatus::Won, LeadStatus::Lost], array_values($closed));
    }

    public function test_new_users_and_leads_get_the_default_role_and_status_in_memory_and_in_the_database(): void
    {
        $user = User::create(['name' => 'Rep', 'email' => 'rep@example.com', 'password' => 'secret']);
        $lead = Lead::create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '+44 20 7946 0000',
            'source' => LeadSource::Web,
            'expected_value' => '100.00',
        ]);

        $this->assertSame(UserRole::Rep, $user->role);
        $this->assertSame(LeadStatus::New, $lead->status);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'rep']);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'status' => 'new']);
    }

    public function test_role_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        User::create([
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'secret',
            'role' => UserRole::Manager,
        ]);
    }

    public function test_expected_value_keeps_exact_decimals(): void
    {
        $lead = Lead::factory()->create(['expected_value' => '1234.56']);
        $max = Lead::factory()->create(['expected_value' => '9999999999.99']);

        $this->assertSame('1234.56', $lead->fresh()->expected_value);
        $this->assertSame('9999999999.99', $max->fresh()->expected_value);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'expected_value' => '1234.56']);
    }

    public function test_updating_an_activity_keeps_its_occurred_at(): void
    {
        $activity = Activity::factory()->create(['occurred_at' => '2026-01-15 09:30:00']);

        $activity->update(['body' => 'Edited notes']);

        $this->assertSame('2026-01-15 09:30:00', $activity->fresh()->occurred_at->toDateTimeString());
    }

    public function test_deleting_a_rep_unassigns_their_leads(): void
    {
        $rep = User::factory()->rep()->create();
        $lead = Lead::factory()->assignedTo($rep)->create();

        $rep->delete();

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'assigned_to' => null]);
    }

    public function test_a_user_who_logged_activities_cannot_be_deleted(): void
    {
        $rep = User::factory()->rep()->create();
        Activity::factory()->for($rep)->create();

        try {
            $rep->delete();
            $this->fail('Deleting a user with logged activities should be blocked by the foreign key.');
        } catch (QueryException) {
            $this->assertDatabaseHas('users', ['id' => $rep->id]);
            $this->assertDatabaseCount('activities', 1);
        }
    }

    public function test_deleting_a_lead_deletes_its_activities(): void
    {
        $lead = Lead::factory()->create();
        Activity::factory()->count(3)->for($lead)->create();
        $otherActivity = Activity::factory()->create();

        $lead->delete();

        $this->assertDatabaseMissing('activities', ['lead_id' => $lead->id]);
        $this->assertDatabaseHas('activities', ['id' => $otherActivity->id]);
    }
}
