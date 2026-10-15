<?php

namespace Tests\Feature\Account;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Api\V1\Account\AddressController;
use App\Models\Order;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountAreaTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = ['name' => 'Ava Doe', 'line1' => '1 Main St', 'city' => 'Austin', 'postal_code' => '73301', 'country' => 'us'];

    public function test_account_endpoints_require_sign_in(): void
    {
        foreach ([['get', '/api/v1/user/orders'], ['get', '/api/v1/user/addresses'], ['patch', '/api/v1/user/profile'], ['put', '/api/v1/user/password']] as [$method, $url]) {
            $this->{$method.'Json'}($url)->assertUnauthorized();
        }
    }

    public function test_purchases_list_only_my_completed_orders(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        Order::create(['user_id' => $me->id, 'total_cents' => 1900, 'payment_status' => PaymentStatus::Paid, 'order_number' => 'PN-MINE-PAID']);
        Order::create(['user_id' => $me->id, 'total_cents' => 1900, 'payment_status' => PaymentStatus::Pending, 'order_number' => 'PN-MINE-PEND']);
        Order::create(['user_id' => $other->id, 'total_cents' => 1900, 'payment_status' => PaymentStatus::Paid, 'order_number' => 'PN-OTHER']);

        Sanctum::actingAs($me);
        $this->getJson('/api/v1/user/orders')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.order_number', 'PN-MINE-PAID');
    }

    public function test_profile_and_password_updates(): void
    {
        $user = User::factory()->create(['password' => 'Old-Password-1']);
        $user->profile()->create();
        $other = $user->createToken('other-device');
        $current = $user->createToken('this-device');

        $this->withToken($current->plainTextToken)
            ->patchJson('/api/v1/user/profile', ['name' => 'Ava Renamed', 'marketing_opt_in' => true])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Ava Renamed')
            ->assertJsonPath('data.user.profile.marketing_opt_in', true);

        $this->withToken($current->plainTextToken)
            ->putJson('/api/v1/user/password', ['current_password' => 'wrong', 'password' => 'New-Password-2', 'password_confirmation' => 'New-Password-2'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->withToken($current->plainTextToken)
            ->putJson('/api/v1/user/password', ['current_password' => 'Old-Password-1', 'password' => 'New-Password-2', 'password_confirmation' => 'New-Password-2'])
            ->assertOk();

        $this->assertTrue(Hash::check('New-Password-2', $user->fresh()->password));
        $this->assertNull($user->tokens()->find($other->accessToken->id), 'Other devices are signed out.');
        $this->assertNotNull($user->tokens()->find($current->accessToken->id), 'This device stays signed in.');
    }

    public function test_address_book_crud_keeps_one_default_and_stays_private(): void
    {
        $me = User::factory()->create();
        $stranger = User::factory()->create();
        $theirs = $stranger->addresses()->create(self::ADDRESS + ['country' => 'US']);
        Sanctum::actingAs($me);

        $first = $this->postJson('/api/v1/user/addresses', self::ADDRESS + ['label' => 'Home'])->assertCreated()->json('data.address');
        $this->assertTrue($first['is_default'], 'The first address becomes the default.');
        $this->assertSame('US', $first['country']);

        $second = $this->postJson('/api/v1/user/addresses', self::ADDRESS + ['label' => 'Studio', 'is_default' => true])->assertCreated()->json('data.address');
        $this->assertFalse(UserAddress::find($first['id'])->is_default);

        $this->patchJson("/api/v1/user/addresses/{$second['id']}", ['city' => 'Dallas'])->assertOk()->assertJsonPath('data.address.city', 'Dallas');
        $this->postJson('/api/v1/user/addresses', ['name' => 'x'])->assertUnprocessable();

        // Removing the default promotes the remaining address.
        $this->deleteJson("/api/v1/user/addresses/{$second['id']}")->assertOk();
        $this->assertTrue(UserAddress::find($first['id'])->is_default);

        // Someone else's address is invisible and untouchable.
        $this->patchJson("/api/v1/user/addresses/{$theirs->id}", ['city' => 'Hacked'])->assertNotFound();
        $this->deleteJson("/api/v1/user/addresses/{$theirs->id}")->assertNotFound();
        $this->assertSame('Austin', $theirs->fresh()->city);
        $this->getJson('/api/v1/user/addresses')->assertJsonCount(1, 'data.addresses');
    }

    public function test_address_book_is_capped(): void
    {
        $me = User::factory()->create();
        foreach (range(1, AddressController::MAX_ADDRESSES) as $i) {
            $me->addresses()->create(self::ADDRESS + ['country' => 'US']);
        }
        Sanctum::actingAs($me);

        $this->postJson('/api/v1/user/addresses', self::ADDRESS)->assertUnprocessable()->assertJsonPath('success', false);
    }
}
