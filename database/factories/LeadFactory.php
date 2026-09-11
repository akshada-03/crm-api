<?php

namespace Database\Factories;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * New and unassigned by default, so a factory lead never breaks the won/lost rule
     * and never creates a rep the test didn't ask for.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->numerify('+1 ###-###-####'),
            'company' => fake()->optional()->company(),
            'source' => fake()->randomElement(LeadSource::cases()),
            'status' => LeadStatus::New,
            'expected_value' => fake()->randomFloat(2, 500, 50000),
            'assigned_to' => null,
        ];
    }

    public function assignedTo(User $rep): static
    {
        return $this->state(['assigned_to' => $rep->id]);
    }

    public function withStatus(LeadStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
