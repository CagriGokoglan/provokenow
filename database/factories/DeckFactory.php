<?php

namespace Database\Factories;

use App\Enums\DeckSegment;
use App\Models\Card;
use App\Models\Deck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deck>
 */
class DeckFactory extends Factory
{
    public function definition(): array
    {
        return [
            'deck_code' => sprintf('DECK-%03d', fake()->unique()->numberBetween(1, 999)),
            'title' => fake()->words(3, true),
            'segment' => DeckSegment::LightIgnition,
            'intensity_level' => 1,
            'core_theme_keywords' => 'connection, playfulness, trust',
            'ai_system_role_prompt' => 'Internal prompt.',
            'pod_card_box_theme' => 'noir-gold',
            'digital_delivery_file_name' => 'deck.pdf',
            'is_active' => true,
        ];
    }

    public function segment(DeckSegment $segment, int $intensity = 1): static
    {
        return $this->state(['segment' => $segment, 'intensity_level' => $intensity]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function withCards(int $count = 50): static
    {
        return $this->afterCreating(function (Deck $deck) use ($count) {
            for ($n = 1; $n <= $count; $n++) {
                Card::factory()->for($deck)->create(['card_number' => $n, 'title' => "Card {$n}"]);
            }
        });
    }
}
