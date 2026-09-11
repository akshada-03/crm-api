<?php

namespace Database\Seeders;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Database\Factories\LeadFactory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    private const PASSWORD = 'password';

    private const REP_COUNT = 3;

    private const ASSIGNED_LEADS = 36;

    private const UNASSIGNED_LEADS = 4;

    private const MAX_ACTIVITIES_PER_LEAD = 5;

    public function run(): void
    {
        $manager = User::factory()->manager()->create([
            'name' => 'Morgan Manager',
            'email' => 'manager@example.com',
            'password' => self::PASSWORD,
        ]);

        $reps = Collection::times(self::REP_COUNT, fn (int $n) => User::factory()->rep()->create([
            'name' => "Rep {$n}",
            'email' => "rep{$n}@example.com",
            'password' => self::PASSWORD,
        ]));

        $statuses = $this->spread(LeadStatus::cases(), self::ASSIGNED_LEADS);
        $sources = $this->spread(LeadSource::cases(), self::ASSIGNED_LEADS);

        foreach ($statuses as $i => $status) {
            $rep = $reps[$i % self::REP_COUNT];

            $lead = $this->lead()
                ->assignedTo($rep)
                ->withStatus($status)
                ->create(['source' => $sources[$i]]);

            $this->logActivities($lead, $rep);
        }

        // Nobody has picked these up yet, so they stay new and have no activities.
        $this->lead()->count(self::UNASSIGNED_LEADS)->create();

        $this->printCredentials(collect([$manager])->concat($reps));
    }

    /**
     * Cycles through the cases before shuffling, so every case appears at least once
     * and each rep still ends up with a different mix.
     *
     * @template TCase
     *
     * @param  list<TCase>  $cases
     * @return Collection<int, TCase>
     */
    private function spread(array $cases, int $count): Collection
    {
        return Collection::times($count, fn (int $n) => $cases[($n - 1) % count($cases)])->shuffle();
    }

    /**
     * Round deal sizes and creation dates spread over the last quarter.
     */
    private function lead(): LeadFactory
    {
        return Lead::factory()->state(fn () => [
            'expected_value' => fake()->numberBetween(2, 100) * 500,
            'created_at' => fake()->dateTimeBetween('-90 days'),
        ]);
    }

    /**
     * The same rule the API enforces: a won or lost lead has at least one activity.
     */
    private function logActivities(Lead $lead, User $rep): void
    {
        Activity::factory()
            ->count(fake()->numberBetween($lead->status->isClosed() ? 1 : 0, self::MAX_ACTIVITIES_PER_LEAD))
            ->for($lead)
            ->for($rep, 'user')
            ->state(fn () => ['occurred_at' => fake()->dateTimeBetween($lead->created_at)])
            ->create();
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function printCredentials(Collection $users): void
    {
        $this->command->newLine();
        $this->command->info('Log in with any of these accounts:');
        $this->command->table(
            ['Role', 'Name', 'Email', 'Password'],
            $users->map(fn (User $user) => [$user->role->value, $user->name, $user->email, self::PASSWORD]),
        );
    }
}
