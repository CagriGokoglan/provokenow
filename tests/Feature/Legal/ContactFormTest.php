<?php

namespace Tests\Feature\Legal;

use App\Mail\ContactMessageMailable;
use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['provoke.contact.to' => 'support@provokenow.com']);
    }

    private function token(): string
    {
        return $this->getJson('/api/v1/contact/token')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data.token');
    }

    private function form(array $overrides = []): array
    {
        return [
            'name' => 'Alex',
            'email' => 'Alex@Example.com',
            'subject' => 'Order question',
            'message' => 'Where is my order #1234? Thank you!',
            'website' => '',
            ...$overrides,
        ];
    }

    public function test_a_real_message_is_stored_and_emailed_to_support(): void
    {
        $token = $this->token();
        $this->travel(5)->seconds();

        $this->postJson('/api/v1/contact', $this->form(['form_token' => $token]))->assertCreated()->assertJsonPath('success', true);

        $message = ContactMessage::sole();
        $this->assertSame('alex@example.com', $message->email);
        Mail::assertQueued(ContactMessageMailable::class, fn ($mail) => $mail->hasTo('support@provokenow.com')
            && $mail->hasReplyTo('alex@example.com')
            && $mail->contact->is($message));

        $html = (new ContactMessageMailable($message))->render();
        $this->assertStringContainsString('Where is my order #1234?', $html);
    }

    public function test_bots_are_stopped(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        // Honeypot filled: looks like success, nothing stored or sent.
        $token = $this->token();
        $this->travel(5)->seconds();
        $this->postJson('/api/v1/contact', $this->form(['form_token' => $token, 'website' => 'http://spam.example']))->assertCreated();

        // Posted instantly after the form loaded.
        $this->postJson('/api/v1/contact', $this->form(['form_token' => $this->token()]))->assertUnprocessable()->assertJsonValidationErrors('form_token');

        // Forged, missing or replayed tokens.
        $this->postJson('/api/v1/contact', $this->form(['form_token' => 'forged']))->assertUnprocessable()->assertJsonValidationErrors('form_token');
        $this->postJson('/api/v1/contact', $this->form())->assertUnprocessable()->assertJsonValidationErrors('form_token');

        $this->travel(1)->minutes();
        $replay = $this->token();
        $this->travel(5)->seconds();
        $this->postJson('/api/v1/contact', $this->form(['form_token' => $replay, 'email' => 'one@example.com']))->assertCreated();
        $this->postJson('/api/v1/contact', $this->form(['form_token' => $replay, 'email' => 'two@example.com']))->assertUnprocessable()->assertJsonValidationErrors('form_token');

        // Expired token.
        $old = $this->token();
        $this->travel(3)->hours();
        $this->postJson('/api/v1/contact', $this->form(['form_token' => $old, 'email' => 'three@example.com']))->assertUnprocessable()->assertJsonValidationErrors('form_token');

        // Link spam.
        $spam = $this->token();
        $this->travel(5)->seconds();
        $this->postJson('/api/v1/contact', $this->form(['form_token' => $spam, 'email' => 'four@example.com', 'message' => 'http://a.co http://b.co http://c.co http://d.co']))
            ->assertUnprocessable()->assertJsonValidationErrors('message');

        $this->assertSame(['one@example.com'], ContactMessage::pluck('email')->all());
        Mail::assertQueuedCount(1);
    }

    public function test_turnstile_is_required_when_configured(): void
    {
        config(['provoke.contact.turnstile' => ['site_key' => 'site-key', 'secret_key' => 'secret']]);
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false])->push(['success' => true])]);

        $this->getJson('/api/v1/contact/token')->assertJsonPath('data.turnstile_site_key', 'site-key');

        $first = $this->token();
        $second = $this->token();
        $this->travel(5)->seconds();

        $this->postJson('/api/v1/contact', $this->form(['form_token' => $first]))->assertUnprocessable()->assertJsonValidationErrors('turnstile_token');
        $this->postJson('/api/v1/contact', $this->form(['form_token' => $first, 'turnstile_token' => 'bad']))->assertUnprocessable()->assertJsonValidationErrors('turnstile_token');
        $this->postJson('/api/v1/contact', $this->form(['form_token' => $second, 'turnstile_token' => 'good']))->assertCreated();

        Http::assertSent(fn ($request) => $request['secret'] === 'secret' && $request['response'] === 'good');
        $this->assertSame(1, ContactMessage::count());
    }

    public function test_validation_and_rate_limit(): void
    {
        $this->postJson('/api/v1/contact', ['email' => 'not-an-email'])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'subject', 'message', 'form_token']);

        // Three tries a minute per visitor.
        foreach (range(1, 2) as $i) {
            $this->postJson('/api/v1/contact', [])->assertUnprocessable();
        }
        $this->postJson('/api/v1/contact', [])->assertTooManyRequests();
    }
}
