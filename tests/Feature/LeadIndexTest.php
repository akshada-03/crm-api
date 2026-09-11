<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeadIndexTest extends TestCase
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
        $this->getJson('/api/leads')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_each_lead_is_returned_with_its_assigned_rep_but_without_activities(): void
    {
        $lead = Lead::factory()->assignedTo($this->rep)->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '+44 20 7946 0000',
            'company' => 'Analytical Engines Ltd',
            'source' => LeadSource::Referral,
            'status' => LeadStatus::Contacted,
            'expected_value' => '12500.50',
            'created_at' => '2026-09-01 09:00:00',
            'updated_at' => '2026-09-02 10:30:00',
        ]);

        Sanctum::actingAs($this->manager);

        $this->getJson('/api/leads')
            ->assertOk()
            ->assertJsonPath('data.0', [
                'id' => $lead->id,
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
                'phone' => '+44 20 7946 0000',
                'company' => 'Analytical Engines Ltd',
                'source' => 'referral',
                'status' => 'contacted',
                'expected_value' => '12500.50',
                'assigned_rep' => [
                    'id' => $this->rep->id,
                    'name' => $this->rep->name,
                    'email' => $this->rep->email,
                    'role' => 'rep',
                ],
                'created_at' => '2026-09-01T09:00:00+00:00',
                'updated_at' => '2026-09-02T10:30:00+00:00',
            ])
            ->assertJsonMissingPath('data.0.activities');
    }

    public function test_an_unassigned_lead_has_a_null_assigned_rep(): void
    {
        Lead::factory()->create();

        Sanctum::actingAs($this->manager);

        $this->getJson('/api/leads')
            ->assertOk()
            ->assertJsonPath('data.0.assigned_rep', null);
    }

    public function test_a_manager_sees_every_lead(): void
    {
        $leadIds = [
            Lead::factory()->assignedTo($this->rep)->create()->id,
            Lead::factory()->assignedTo($this->otherRep)->create()->id,
            Lead::factory()->create()->id,
        ];

        Sanctum::actingAs($this->manager);

        $this->assertListedIds($leadIds, $this->getJson('/api/leads')->assertOk()->json('data'));
    }

    public function test_a_rep_sees_only_the_leads_assigned_to_them(): void
    {
        $ownLeads = Lead::factory()->count(2)->assignedTo($this->rep)->create();
        Lead::factory()->assignedTo($this->otherRep)->create();
        Lead::factory()->create();

        Sanctum::actingAs($this->rep);

        $response = $this->getJson('/api/leads')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->assertListedIds($ownLeads->modelKeys(), $response->json('data'));
    }

    public function test_a_rep_cannot_widen_their_scope_through_the_assigned_to_filter(): void
    {
        Lead::factory()->assignedTo($this->rep)->create();
        Lead::factory()->assignedTo($this->otherRep)->create();
        Lead::factory()->create();

        Sanctum::actingAs($this->rep);

        $this->getJson("/api/leads?assigned_to={$this->otherRep->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/leads?assigned_to=unassigned')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_leads_can_be_filtered_by_status(): void
    {
        $qualified = Lead::factory()->withStatus(LeadStatus::Qualified)->create();
        Lead::factory()->withStatus(LeadStatus::New)->create();
        Lead::factory()->withStatus(LeadStatus::Contacted)->create();

        Sanctum::actingAs($this->manager);

        $this->assertListedIds([$qualified->id], $this->getJson('/api/leads?status=qualified')->assertOk()->json('data'));
    }

    public function test_leads_can_be_filtered_by_source(): void
    {
        $event = Lead::factory()->create(['source' => LeadSource::Event]);
        Lead::factory()->create(['source' => LeadSource::Web]);
        Lead::factory()->create(['source' => LeadSource::ColdCall]);

        Sanctum::actingAs($this->manager);

        $this->assertListedIds([$event->id], $this->getJson('/api/leads?source=event')->assertOk()->json('data'));
    }

    public function test_leads_can_be_filtered_by_assigned_rep(): void
    {
        $repsLeads = Lead::factory()->count(2)->assignedTo($this->rep)->create();
        Lead::factory()->assignedTo($this->otherRep)->create();
        Lead::factory()->create();

        Sanctum::actingAs($this->manager);

        $this->assertListedIds(
            $repsLeads->modelKeys(),
            $this->getJson("/api/leads?assigned_to={$this->rep->id}")->assertOk()->json('data'),
        );
    }

    public function test_assigned_to_unassigned_returns_only_leads_without_a_rep(): void
    {
        $unassigned = Lead::factory()->count(2)->create();
        Lead::factory()->assignedTo($this->rep)->create();

        Sanctum::actingAs($this->manager);

        $this->assertListedIds(
            $unassigned->modelKeys(),
            $this->getJson('/api/leads?assigned_to=unassigned')->assertOk()->json('data'),
        );
    }

    public function test_filters_combine_with_and(): void
    {
        $match = Lead::factory()->assignedTo($this->rep)->withStatus(LeadStatus::Qualified)->create(['source' => LeadSource::Web]);
        Lead::factory()->assignedTo($this->rep)->withStatus(LeadStatus::Qualified)->create(['source' => LeadSource::Event]);
        Lead::factory()->assignedTo($this->rep)->withStatus(LeadStatus::New)->create(['source' => LeadSource::Web]);
        Lead::factory()->assignedTo($this->otherRep)->withStatus(LeadStatus::Qualified)->create(['source' => LeadSource::Web]);

        Sanctum::actingAs($this->manager);

        $this->assertListedIds(
            [$match->id],
            $this->getJson("/api/leads?status=qualified&source=web&assigned_to={$this->rep->id}")->assertOk()->json('data'),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function searchableColumns(): array
    {
        return [
            'name' => ['name'],
            'email' => ['email'],
            'company' => ['company'],
        ];
    }

    #[DataProvider('searchableColumns')]
    public function test_search_matches_a_substring_of_the_column_case_insensitively(string $column): void
    {
        $match = Lead::factory()->create([$column => $column === 'email' ? 'hello@zephyrcorp.test' : 'The Zephyr Company']);
        Lead::factory()->create([
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
            'company' => 'Navy Systems',
        ]);

        Sanctum::actingAs($this->manager);

        $this->assertListedIds([$match->id], $this->getJson('/api/leads?search=ZEPHYR')->assertOk()->json('data'));
    }

    public function test_search_treats_percent_and_underscore_as_literal_characters(): void
    {
        $percent = Lead::factory()->create(['company' => '100% Growth Ltd']);
        Lead::factory()->create(['company' => '100 Growth Ltd']);
        $underscore = Lead::factory()->create(['email' => 'first_last@example.com']);
        Lead::factory()->create(['email' => 'firstxlast@example.com']);

        Sanctum::actingAs($this->manager);

        $this->assertListedIds([$percent->id], $this->getJson('/api/leads?search='.urlencode('100%'))->assertOk()->json('data'));
        $this->assertListedIds([$underscore->id], $this->getJson('/api/leads?search=first_last')->assertOk()->json('data'));
        $this->getJson('/api/leads?search='.urlencode('%'))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_search_treats_the_escape_character_itself_literally(): void
    {
        $yahoo = Lead::factory()->create(['company' => 'Yahoo! Inc']);
        Lead::factory()->create(['company' => 'Yahoo Inc']);

        Sanctum::actingAs($this->manager);

        $this->assertListedIds([$yahoo->id], $this->getJson('/api/leads?search='.urlencode('Yahoo!'))->assertOk()->json('data'));
    }

    public function test_search_cannot_escape_the_reps_scope(): void
    {
        $own = Lead::factory()->assignedTo($this->rep)->create(['name' => 'Zephyr Own']);
        Lead::factory()->assignedTo($this->otherRep)->create(['name' => 'Zephyr Name']);
        Lead::factory()->assignedTo($this->otherRep)->create(['email' => 'buyer@zephyr.test']);
        Lead::factory()->assignedTo($this->otherRep)->create(['company' => 'Zephyr Holdings']);
        Lead::factory()->create(['company' => 'Zephyr Unassigned']);

        Sanctum::actingAs($this->rep);

        $this->assertListedIds([$own->id], $this->getJson('/api/leads?search=zephyr')->assertOk()->json('data'));
    }

    public function test_leads_are_sorted_newest_first_by_default(): void
    {
        $oldest = Lead::factory()->create(['created_at' => now()->subDays(3)]);
        $newest = Lead::factory()->create(['created_at' => now()->subDay()]);
        $middle = Lead::factory()->create(['created_at' => now()->subDays(2)]);

        Sanctum::actingAs($this->manager);

        $this->getJson('/api/leads')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$newest->id, $middle->id, $oldest->id]);
    }

    /**
     * @return array<string, array{string, string, list<int>}>
     */
    public static function sortOrders(): array
    {
        // Indexes into the leads created by the test below: [0] oldest & 900.00, [1] newest & 100.00, [2] middle & 2500.00.
        return [
            'created_at asc' => ['created_at', 'asc', [0, 2, 1]],
            'created_at desc' => ['created_at', 'desc', [1, 2, 0]],
            'expected_value asc' => ['expected_value', 'asc', [1, 0, 2]],
            'expected_value desc' => ['expected_value', 'desc', [2, 0, 1]],
        ];
    }

    /**
     * @param  list<int>  $expectedOrder
     */
    #[DataProvider('sortOrders')]
    public function test_leads_can_be_sorted_by_a_whitelisted_column_in_either_direction(string $sort, string $direction, array $expectedOrder): void
    {
        // 900 vs 2500 would sort the other way as strings, so this also proves the sort is numeric.
        $leads = [
            Lead::factory()->create(['created_at' => now()->subDays(3), 'expected_value' => '900.00']),
            Lead::factory()->create(['created_at' => now()->subDay(), 'expected_value' => '100.00']),
            Lead::factory()->create(['created_at' => now()->subDays(2), 'expected_value' => '2500.00']),
        ];

        Sanctum::actingAs($this->manager);

        $this->getJson("/api/leads?sort={$sort}&direction={$direction}")
            ->assertOk()
            ->assertJsonPath('data.*.id', array_map(fn (int $index) => $leads[$index]->id, $expectedOrder));
    }

    public function test_leads_with_equal_sort_values_are_ordered_by_id(): void
    {
        $leads = Lead::factory()->count(3)->create(['expected_value' => '500.00']);

        Sanctum::actingAs($this->manager);

        $this->getJson('/api/leads?sort=expected_value&direction=asc')
            ->assertJsonPath('data.*.id', $leads->modelKeys());

        $this->getJson('/api/leads?sort=expected_value&direction=desc')
            ->assertJsonPath('data.*.id', array_reverse($leads->modelKeys()));
    }

    public function test_results_are_paginated_fifteen_per_page_by_default(): void
    {
        Lead::factory()->count(16)->create();

        Sanctum::actingAs($this->manager);

        $this->getJson('/api/leads')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 16)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_pagination_honours_per_page_and_page_and_keeps_the_query_string_in_links(): void
    {
        $leads = Lead::factory()->count(12)->withStatus(LeadStatus::Contacted)->create();
        Lead::factory()->withStatus(LeadStatus::New)->create();

        Sanctum::actingAs($this->manager);

        $response = $this->getJson('/api/leads?status=contacted&sort=expected_value&direction=asc&per_page=5&page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'from', 'last_page', 'path', 'per_page', 'to', 'total']])
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.from', 6)
            ->assertJsonPath('meta.to', 10)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.last_page', 3);

        $expectedIds = $leads->sortBy([['expected_value', 'asc'], ['id', 'asc']])->slice(5, 5)->values()->modelKeys();
        $this->assertSame($expectedIds, $response->json('data.*.id'));

        parse_str(parse_url($response->json('links.next'), PHP_URL_QUERY), $next);
        $this->assertEquals(
            ['status' => 'contacted', 'sort' => 'expected_value', 'direction' => 'asc', 'per_page' => '5', 'page' => '3'],
            $next,
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidQueries(): array
    {
        return [
            'unknown status' => ['status=open', 'status', 'The status must be one of: new, contacted, qualified, won, lost.'],
            'status as an array' => ['status[]=new', 'status', 'The status must be one of: new, contacted, qualified, won, lost.'],
            'unknown source' => ['source=tv', 'source', 'The source must be one of: web, referral, cold_call, event, other.'],
            'non-numeric assigned_to' => ['assigned_to=nobody', 'assigned_to', 'The assigned to filter must be a user id or "unassigned".'],
            'assigned_to of a missing user' => ['assigned_to=999999', 'assigned_to', 'The selected assigned to user does not exist.'],
            'search too long' => ['search='.str_repeat('a', 101), 'search', 'The search field must not be greater than 100 characters.'],
            'search as an array' => ['search[]=zephyr', 'search', 'The search field must be a string.'],
            'unknown sort column' => ['sort=name', 'sort', 'The sort field must be one of: created_at, expected_value.'],
            'sort as an array' => ['sort[]=created_at', 'sort', 'The sort field must be one of: created_at, expected_value.'],
            'unknown direction' => ['direction=up', 'direction', 'The direction must be one of: asc, desc.'],
            'per_page of zero' => ['per_page=0', 'per_page', 'The per page field must be between 1 and 100.'],
            'per_page over 100' => ['per_page=101', 'per_page', 'The per page field must be between 1 and 100.'],
            'non-integer per_page' => ['per_page=ten', 'per_page', 'The per page field must be an integer.'],
        ];
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_query_parameters_are_rejected_with_a_422(string $query, string $field, string $message): void
    {
        Sanctum::actingAs($this->manager);

        $this->getJson("/api/leads?{$query}")
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors([$field => $message]);
    }

    public function test_empty_parameters_fall_back_to_no_filter_and_the_default_sort(): void
    {
        $older = Lead::factory()->create(['created_at' => now()->subDay()]);
        $newer = Lead::factory()->create();

        Sanctum::actingAs($this->manager);

        $this->getJson('/api/leads?status=&source=&assigned_to=&search=&sort=&direction=&per_page=')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$newer->id, $older->id])
            ->assertJsonPath('meta.per_page', 15);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_leads(): void
    {
        Sanctum::actingAs($this->manager);

        Lead::factory()->count(3)->state(['assigned_to' => User::factory()->rep()])->create();
        $queriesForThree = $this->countQueries(fn () => $this->getJson('/api/leads?per_page=100')
            ->assertOk()
            ->assertJsonCount(3, 'data'));

        Lead::factory()->count(17)->state(['assigned_to' => User::factory()->rep()])->create();
        $queriesForTwenty = $this->countQueries(fn () => $this->getJson('/api/leads?per_page=100')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonStructure(['data' => ['*' => ['assigned_rep' => ['id', 'name']]]]));

        $this->assertSame($queriesForThree, $queriesForTwenty);
    }

    /**
     * @param  list<int>  $expectedIds
     * @param  list<array<string, mixed>>  $data
     */
    private function assertListedIds(array $expectedIds, array $data): void
    {
        $this->assertEqualsCanonicalizing($expectedIds, array_column($data, 'id'));
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
