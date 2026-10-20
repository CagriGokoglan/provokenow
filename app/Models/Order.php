<?php

namespace App\Models;

use App\Enums\FulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Observers\OrderObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'order_number', 'user_id', 'customer_email', 'subtotal_cents', 'discount_cents', 'total_cents',
    'tax_cents', 'shipping_cents', 'currency', 'payment_status', 'fulfillment_status',
    'courier_name', 'tracking_number', 'tracking_url', 'shipped_at', 'estimated_delivery_date', 'delivered_at',
    'shipping_address', 'billing_address', 'payment_gateway', 'transaction_id', 'paid_at', 'metadata',
])]
#[ObservedBy(OrderObserver::class)]
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
            'shipped_at' => 'datetime',
            'estimated_delivery_date' => 'date',
            'delivered_at' => 'datetime',
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

    /** chaperone(): each item knows its order without another query (admin infolist, emails). */
    /** Set when the order was bought as a gift for someone else. */
    public function gift(): HasOne
    {
        return $this->hasOne(Gift::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->chaperone('order');
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
