<?php

namespace Database\Factories;

use App\Models\Card;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Card>
 */
class CardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'card_number' => 1,
            'title' => fake()->words(2, true),
            'content_payload' => [
                'Setting_and_Scenario' => fake()->sentence(),
                'Action_Task' => fake()->sentence(),
                'Safety_and_Consent_Note' => 'Check in with your partner before you begin.',
            ],
        ];
    }
}
