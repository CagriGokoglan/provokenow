<?php

namespace App\Models;

use App\Enums\FulfillmentStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'order_number', 'user_id', 'customer_email', 'subtotal_cents', 'discount_cents', 'total_cents',
    'tax_cents', 'shipping_cents', 'currency', 'payment_status', 'fulfillment_status',
    'shipping_address', 'billing_address', 'payment_gateway', 'transaction_id', 'paid_at', 'metadata',
])]
class Order extends Model
{
    use HasUuids;

    protected $attributes = [
        'payment_status' => PaymentStatus::Pending->value,
        'fulfillment_status' => FulfillmentStatus::Unfulfilled->value,
        'tax_cents' => 0,
        'shipping_cents' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->order_number ??= static::generateOrderNumber();
        });
    }

    /** e.g. PN-20261015-7KQ2X9MD */
    public static function generateOrderNumber(): string
    {
        return 'PN-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
    }

    protected function casts(): array
    {
        return [
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'tax_cents' => 'integer',
            'shipping_cents' => 'integer',
            'payment_status' => PaymentStatus::class,
            'fulfillment_status' => FulfillmentStatus::class,
            'shipping_address' => 'array',
            'billing_address' => 'array',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(UserEntitlement::class);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === PaymentStatus::Paid;
    }

    public function isGuest(): bool
    {
        return $this->user_id === null;
    }
}
