<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $ip, ?string $forwardedFor = null)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders($forwardedFor ? ['X-Forwarded-For' => $forwardedFor] : [])
            ->postJson('/api/v1/auth/login', ['email' => fake()->unique()->safeEmail(), 'password' => 'wrong-password']);
    }

    public function test_sign_in_limits_are_per_visitor_behind_the_web_app(): void
    {
        // Through the web app's server (loopback): each visitor has their own bucket, so one
        // attacker cannot lock everyone else out.
        foreach (range(1, 10) as $i) {
            $this->login('127.0.0.1', '198.51.100.7')->assertUnauthorized();
        }
        $this->login('127.0.0.1', '198.51.100.7')->assertTooManyRequests();
        $this->login('127.0.0.1', '198.51.100.8')->assertUnauthorized();
    }

    public function test_a_forged_forwarded_for_header_from_the_internet_is_ignored(): void
    {
        foreach (range(1, 10) as $i) {
            $this->login('203.0.113.9', "192.0.2.{$i}")->assertUnauthorized();
        }
        // Rotating the forged header doesn't buy a fresh bucket.
        $this->login('203.0.113.9', '192.0.2.200')->assertTooManyRequests();
    }

    public function test_sign_in_tokens_expire(): void
    {
        $token = User::factory()->create()->createToken('web')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->travel(31)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_api_sends_security_headers_and_refuses_foreign_origins(): void
    {
        $this->getJson('/api/v1/config/public')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

        $preflight = fn (string $origin) => $this->call('OPTIONS', '/api/v1/cart', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $this->assertNull($preflight('https://evil.example')->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame(config('provoke.frontend.url'), $preflight(config('provoke.frontend.url'))->headers->get('Access-Control-Allow-Origin'));
    }
}
