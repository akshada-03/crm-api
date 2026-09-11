<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNTS = [
        'manager@example.com' => UserRole::Manager,
        'rep1@example.com' => UserRole::Rep,
        'rep2@example.com' => UserRole::Rep,
        'rep3@example.com' => UserRole::Rep,
    ];

    public function test_it_seeds_one_manager_and_three_reps(): void
    {
        $this->seed();

        $this->assertSame(self::ACCOUNTS, User::query()->orderBy('email')->pluck('role', 'email')->all());
    }

    public function test_every_seeded_user_can_log_in_with_the_password_password(): void
    {
        $this->seed();

        foreach (self::ACCOUNTS as $email => $role) {
            $this->postJson('/api/login', ['email' => $email, 'password' => 'password'])
                ->assertOk()
                ->assertJsonPath('data.user.email', $email)
                ->assertJsonPath('data.user.role', $role->value);
        }

        $this->assertDatabaseCount('personal_access_tokens', 4);
    }

    public function test_it_seeds_forty_leads_split_evenly_across_the_reps_with_a_few_unassigned(): void
    {
        $this->seed();

        $this->assertSame(40, Lead::count());
        $this->assertSame(
            ['rep1@example.com' => 12, 'rep2@example.com' => 12, 'rep3@example.com' => 12],
            User::query()
                ->where('role', UserRole::Rep)
                ->withCount('assignedLeads')
                ->orderBy('email')
                ->pluck('assigned_leads_count', 'email')
                ->all(),
        );
        $this->assertSame(4, Lead::query()->unassigned()->count());
    }

    public function test_every_source_and_status_is_represented(): void
    {
        $this->seed();

        $this->assertEqualsCanonicalizing(
            array_column(LeadSource::cases(), 'value'),
            Lead::query()->distinct()->pluck('source')->map->value->all(),
        );
        $this->assertEqualsCanonicalizing(
            array_column(LeadStatus::cases(), 'value'),
            Lead::query()->distinct()->pluck('status')->map->value->all(),
        );
    }

    public function test_expected_values_are_positive_whole_amounts(): void
    {
        $this->seed();

        $this->assertFalse(Lead::query()->where('expected_value', '<=', 0)->exists());
        $this->assertTrue(Lead::query()->pluck('expected_value')->every(fn (string $value) => str_ends_with($value, '.00')));
    }

    public function test_no_won_or_lost_lead_lacks_an_activity(): void
    {
        $this->seed();

        $closedLeads = Lead::query()->whereIn('status', [LeadStatus::Won, LeadStatus::Lost]);

        $this->assertTrue($closedLeads->clone()->exists());
        $this->assertFalse($closedLeads->clone()->doesntHave('activities')->exists());
    }

    public function test_leads_have_at_most_five_activities_and_unassigned_leads_are_new_and_untouched(): void
    {
        $this->seed();

        $this->assertTrue(Activity::query()->exists());
        $this->assertFalse(Lead::query()->has('activities', '>', 5)->exists());
        $this->assertFalse(Lead::query()->unassigned()->has('activities')->exists());
        $this->assertFalse(Lead::query()->unassigned()->where('status', '!=', LeadStatus::New)->exists());
    }

    public function test_every_activity_is_logged_by_the_leads_rep_between_the_leads_creation_and_now(): void
    {
        $this->seed();

        $this->assertFalse(Activity::query()
            ->whereDoesntHave('lead', fn (Builder $lead) => $lead
                ->whereColumn('leads.assigned_to', 'activities.user_id')
                ->whereColumn('leads.created_at', '<=', 'activities.occurred_at'))
            ->exists());
        $this->assertFalse(Activity::query()->where('occurred_at', '>', now())->exists());
    }

    public function test_it_prints_the_login_credentials(): void
    {
        $this->artisan('db:seed')
            ->expectsOutputToContain('Log in with any of these accounts:')
            ->expectsTable(['Role', 'Name', 'Email', 'Password'], [
                ['manager', 'Morgan Manager', 'manager@example.com', 'password'],
                ['rep', 'Rep 1', 'rep1@example.com', 'password'],
                ['rep', 'Rep 2', 'rep2@example.com', 'password'],
                ['rep', 'Rep 3', 'rep3@example.com', 'password'],
            ])
            ->assertSuccessful();
    }
}
