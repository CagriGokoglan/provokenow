<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Card;
use App\Models\Deck;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
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
        $this->assertSame(50, Deck::count());
        $this->assertSame(2500, Card::count());
        $this->assertTrue(Deck::where('deck_code', 'DECK-050')->exists());
        $this->assertSame(4, Deck::firstWhere('deck_code', 'DECK-050')->intensity_level);
    }
}
