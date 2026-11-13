<?php

namespace Database\Factories;

use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<City>
 *
 * Names are obviously synthetic so a test city can never be mistaken for a
 * real launch city. The timezone is UTC for the same reason.
 */
class CityFactory extends Factory
{
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 999_999);

        return [
            'slug' => "test-city-{$number}",
            'name' => "Test City {$number}",
            'country_code' => 'ZZ',
            'currency_code' => 'PHP',
            'timezone' => 'UTC',
        ];
    }

    public function active(): static
    {
        return $this->afterCreating(function (City $city): void {
            $city->forceFill(['status' => 'active'])->save();
        });
    }
}
