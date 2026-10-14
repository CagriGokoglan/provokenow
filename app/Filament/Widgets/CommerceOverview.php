<?php

namespace App\Filament\Widgets;

use App\Enums\EntitlementStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use App\Models\UserEntitlement;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CommerceOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $since = now()->subDays(30);
        $paid = Order::where('payment_status', PaymentStatus::Paid->value)->where('paid_at', '>=', $since);
        $revenue = (clone $paid)->sum('total_cents');
        $currency = config('provoke.commerce.currency');

        // Daily revenue sparkline for the last 14 days.
        $daily = Order::where('payment_status', PaymentStatus::Paid->value)
            ->where('paid_at', '>=', now()->subDays(13)->startOfDay())
            ->get(['paid_at', 'total_cents'])
            ->groupBy(fn (Order $o) => $o->paid_at->toDateString())
            ->map->sum('total_cents');
        $spark = collect(range(13, 0))->map(fn ($d) => (int) ($daily[now()->subDays($d)->toDateString()] ?? 0) / 100)->all();

        $awaiting = Order::where('payment_status', PaymentStatus::Paid->value)
            ->whereIn('fulfillment_status', [FulfillmentStatus::Unfulfilled->value, FulfillmentStatus::Processing->value])
            ->where('metadata->requires_shipping', true)
            ->count();

        return [
            Stat::make('Revenue · 30 days', number_format($revenue / 100, 2).' '.$currency)
                ->description((clone $paid)->count().' paid orders')
                ->chart($spark)
                ->color('primary'),
            Stat::make('Awaiting shipment', (string) $awaiting)
                ->description('Paid physical orders')
                ->color($awaiting > 0 ? 'warning' : 'success'),
            Stat::make('Pending checkouts', (string) Order::where('payment_status', PaymentStatus::Pending->value)->where('created_at', '>=', $since)->count())
                ->description('Last 30 days, unpaid'),
            Stat::make('Active deck licences', (string) UserEntitlement::where('status', EntitlementStatus::Active->value)->count())
                ->description(User::where('role', UserRole::Customer->value)->count().' customers'),
        ];
    }
}
