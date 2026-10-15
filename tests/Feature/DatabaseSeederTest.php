<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Card;
use App\Models\Deck;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DeckSampleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_are_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::where('role', UserRole::SuperAdmin)->count());
        $this->assertSame(DeckSampleSeeder::DECK_COUNT, Deck::count());
        $this->assertSame(DeckSampleSeeder::DECK_COUNT * DeckSampleSeeder::CARDS_PER_DECK, Card::count());
        $last = sprintf('DECK-%03d', DeckSampleSeeder::DECK_COUNT);
        $this->assertSame(4, Deck::firstWhere('deck_code', $last)->intensity_level);
        $this->assertSame(1, Deck::firstWhere('deck_code', 'DECK-001')->intensity_level);
    }
}
