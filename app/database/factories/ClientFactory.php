<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::query()->value('id') ?? fake()->uuid(),
            'created_by_user_id' => User::factory(),
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->e164PhoneNumber(),
            'identity_type' => 'nin',
            'identity_number' => fake()->numerify('###########'),
            'kyc_status' => 'pending',
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => ['organization_id' => $organization->id]);
    }
}
