<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RepPerformanceReportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $alice;

    private User $bob;

    private User $carol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->manager()->create(['name' => 'Mia Manager']);
    }

    public function test_a_guest_gets_a_401(): void
    {
        $this->getJson('/api/reports/rep-performance')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_a_manager_sees_exact_totals_for_every_rep(): void
    {
        $this->seedKnownDataset();

        Sanctum::actingAs($this->manager);

        $response = $this->getJson('/api/reports/rep-performance')->assertOk();

        $this->assertSame(['data' => [$this->aliceRow(), $this->bobRow(), $this->carolRow()]], $response->json());
    }

    public function test_a_rep_sees_only_their_own_row(): void
    {
        $this->seedKnownDataset();

        Sanctum::actingAs($this->bob);

        $response = $this->getJson('/api/reports/rep-performance')->assertOk();

        $this->assertSame(['data' => [$this->bobRow()]], $response->json());
    }

    public function test_a_rep_without_leads_or_activities_sees_their_row_with_every_status_at_zero(): void
    {
        $this->seedKnownDataset();

        Sanctum::actingAs($this->carol);

        $response = $this->getJson('/api/reports/rep-performance')->assertOk();

        $this->assertSame(['data' => [$this->carolRow()]], $response->json());
    }

    public function test_the_report_is_one_query_however_many_reps_there_are(): void
    {
        $this->seedRepsWithWork(2);

        Sanctum::actingAs($this->manager);

        $queriesForTwo = $this->countQueries(fn () => $this->getJson('/api/reports/rep-performance')
            ->assertOk()
            ->assertJsonCount(2, 'data'));

        $this->seedRepsWithWork(8);

        $queriesForTen = $this->countQueries(fn () => $this->getJson('/api/reports/rep-performance')
            ->assertOk()
            ->assertJsonCount(10, 'data'));

        $this->assertSame(1, $queriesForTwo);
        $this->assertSame($queriesForTwo, $queriesForTen);
    }

    /**
     * Alice's won lead carries four activities: a join of leads and activities would count
     * and sum that lead four times. The manager's activities count for nobody, and Alice's
     * activity on Bob's lead counts for Alice, who logged it.
     */
    private function seedKnownDataset(): void
    {
        $this->alice = User::factory()->rep()->create(['name' => 'Alice Adams']);
        $this->bob = User::factory()->rep()->create(['name' => 'Bob Brown']);
        $this->carol = User::factory()->rep()->create(['name' => 'Carol Clark']);

        $this->lead($this->alice, LeadStatus::New, '1000.10');
        $this->lead($this->alice, LeadStatus::Contacted, '2000.20');
        $this->lead($this->alice, LeadStatus::Qualified, '3000.30');
        $aliceWon = $this->lead($this->alice, LeadStatus::Won, '4000.40');
        $this->lead($this->alice, LeadStatus::Won, '5000.50');
        $this->lead($this->alice, LeadStatus::Lost, '600.60');

        $bobNew = $this->lead($this->bob, LeadStatus::New, '250.00');
        $this->lead($this->bob, LeadStatus::Lost, '0.01');
        $this->lead($this->bob, LeadStatus::Won, '99999.99');

        $unassignedWon = $this->lead(null, LeadStatus::Won, '1000000.00');
        $this->lead(null, LeadStatus::New, '5.00');

        Activity::factory()->count(2)->for($aliceWon)->for($this->alice)->create();
        Activity::factory()->for($bobNew)->for($this->alice)->create();
        Activity::factory()->count(2)->for($aliceWon)->for($this->manager)->create();
        Activity::factory()->for($bobNew)->for($this->bob)->create();
        Activity::factory()->for($unassignedWon)->for($this->bob)->create();
    }

    private function lead(?User $rep, LeadStatus $status, string $expectedValue): Lead
    {
        return Lead::factory()->withStatus($status)->create([
            'assigned_to' => $rep?->id,
            'expected_value' => $expectedValue,
        ]);
    }

    private function seedRepsWithWork(int $count): void
    {
        User::factory()->rep()->count($count)->create()->each(function (User $rep) {
            $lead = Lead::factory()->assignedTo($rep)->withStatus(LeadStatus::Won)->create();
            Activity::factory()->count(2)->for($lead)->for($rep)->create();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function aliceRow(): array
    {
        return $this->row($this->alice, 6, [1, 1, 1, 2, 1], '15602.10', '9000.90', 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function bobRow(): array
    {
        return $this->row($this->bob, 3, [1, 0, 0, 1, 1], '100250.00', '99999.99', 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function carolRow(): array
    {
        return $this->row($this->carol, 0, [0, 0, 0, 0, 0], '0.00', '0.00', 0);
    }

    /**
     * @param  array{int, int, int, int, int}  $statusCounts  new, contacted, qualified, won, lost
     * @return array<string, mixed>
     */
    private function row(User $rep, int $totalLeads, array $statusCounts, string $totalValue, string $wonValue, int $activities): array
    {
        return [
            'rep' => ['id' => $rep->id, 'name' => $rep->name],
            'total_leads' => $totalLeads,
            'status_counts' => array_combine(['new', 'contacted', 'qualified', 'won', 'lost'], $statusCounts),
            'total_expected_value' => $totalValue,
            'won_expected_value' => $wonValue,
            'activity_count' => $activities,
        ];
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
