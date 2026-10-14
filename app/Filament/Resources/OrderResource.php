<?php

namespace App\Filament\Resources;

use App\Enums\FulfillmentStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Services\Commerce\OrderFulfillmentService;
use Filament\Actions\Action as PageAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Read-only order book plus the two operational actions: confirm payment, update fulfilment. */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Commerce';

    protected static ?string $recordTitleAttribute = 'order_number';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Order::where('payment_status', PaymentStatus::Paid->value)
            ->whereIn('fulfillment_status', [FulfillmentStatus::Unfulfilled->value, FulfillmentStatus::Processing->value])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Paid orders awaiting shipment';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('order_number')->searchable()->copyable()->weight('medium'),
                Tables\Columns\TextColumn::make('customer_email')->label('Customer')->searchable()
                    ->description(fn (Order $record) => $record->isGuest() ? 'Guest' : null),
                Tables\Columns\TextColumn::make('total_cents')->label('Total')->sortable()
                    ->money(fn (Order $record) => $record->currency, divideBy: 100),
                Tables\Columns\TextColumn::make('payment_status')->label('Payment')->badge()->sortable(),
                Tables\Columns\TextColumn::make('fulfillment_status')->label('Fulfillment')->badge()->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime('M j, Y H:i')->sortable()
                    ->description(fn (Order $record) => $record->created_at?->diffForHumans()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('payment_status')->options(PaymentStatus::class),
                Tables\Filters\SelectFilter::make('fulfillment_status')->options(FulfillmentStatus::class),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                static::markPaidAction(TableAction::make('markPaid')),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $money = fn (Order $o, ?int $cents) => $cents === null ? '—' : number_format($cents / 100, 2).' '.$o->currency;

        return $infolist->schema([
            Grid::make(3)->schema([
                Section::make('Order')->columnSpan(2)->columns(3)->schema([
                    TextEntry::make('order_number')->copyable()->weight('bold'),
                    TextEntry::make('payment_status')->badge(),
                    TextEntry::make('fulfillment_status')->badge(),
                    TextEntry::make('created_at')->dateTime(),
                    TextEntry::make('paid_at')->dateTime()->placeholder('Not paid'),
                    TextEntry::make('customer_email')->copyable(),
                    TextEntry::make('user.name')->label('Account')->placeholder('Guest checkout'),
                ]),
                Section::make('Totals')->columnSpan(1)->schema([
                    TextEntry::make('subtotal_cents')->label('Subtotal')->state(fn (Order $o) => $money($o, $o->subtotal_cents))->inlineLabel(),
                    TextEntry::make('discount_cents')->label('Bundle discounts')->state(fn (Order $o) => '−'.$money($o, $o->discount_cents))->inlineLabel(),
                    TextEntry::make('shipping_cents')->label('Shipping')->state(fn (Order $o) => $money($o, $o->shipping_cents))->inlineLabel(),
                    TextEntry::make('tax_cents')->label('Tax')->state(fn (Order $o) => $money($o, $o->tax_cents))->inlineLabel(),
                    TextEntry::make('total_cents')->label('Total')->state(fn (Order $o) => $money($o, $o->total_cents))->inlineLabel()->weight('bold'),
                ]),
            ]),
            Section::make('Items')->schema([
                RepeatableEntry::make('items')->hiddenLabel()->columns(6)->schema([
                    TextEntry::make('metadata.name')->label('Product')->columnSpan(2),
                    TextEntry::make('metadata.sku')->label('SKU'),
                    TextEntry::make('quantity')->label('Qty'),
                    TextEntry::make('unit_price_cents')->label('Unit')->formatStateUsing(fn ($state, $record) => $money($record->order, $state)),
                    TextEntry::make('subtotal_cents')->label('Line total')
                        ->formatStateUsing(fn ($state, $record) => $money($record->order, $state))
                        ->helperText(fn ($record) => ($record->metadata['applied_discount_cents'] ?? 0) > 0
                            ? 'incl. '.$money($record->order, $record->metadata['applied_discount_cents']).' bundle discount'
                            : null),
                ]),
            ]),
            Grid::make(3)->schema([
                Section::make('Shipping address')->columnSpan(1)->schema([
                    KeyValueEntry::make('shipping_address')->hiddenLabel()->placeholder('No shipping required'),
                ]),
                Section::make('Billing address')->columnSpan(1)->schema([
                    KeyValueEntry::make('billing_address')->hiddenLabel()->placeholder('Not provided'),
                ]),
                Section::make('Payment')->columnSpan(1)->schema([
                    TextEntry::make('payment_gateway')->placeholder('—'),
                    TextEntry::make('transaction_id')->label('Transaction ID')->copyable()->placeholder('—'),
                    TextEntry::make('metadata.tracking_number')->label('Tracking number')->placeholder('—')->copyable(),
                ]),
            ]),
            Section::make('Deck access granted')->collapsible()->schema([
                RepeatableEntry::make('entitlements')->hiddenLabel()->columns(3)->schema([
                    TextEntry::make('deck.deck_code')->label('Deck'),
                    TextEntry::make('deck.title')->label('Title'),
                    TextEntry::make('status')->badge(),
                ])->placeholder('No digital access on this order.'),
            ]),
        ]);
    }

    /**
     * Confirms payment manually (bank transfer, gateway outage, ...). Runs the same fulfilment
     * as the payment webhook: entitlements, stock, confirmation email, Meta CAPI Purchase.
     *
     * @template T of TableAction|PageAction
     *
     * @param  T  $action
     * @return T
     */
    public static function markPaidAction(TableAction|PageAction $action): TableAction|PageAction
    {
        return $action
            ->label('Mark as Paid & Fulfill')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (Order $record) => in_array($record->payment_status, [PaymentStatus::Pending, PaymentStatus::Failed], true))
            ->requiresConfirmation()
            ->modalDescription('Confirms payment, unlocks the purchased decks for the customer, sends the confirmation email and reports the purchase to Meta. This cannot be undone from here.')
            ->form([
                TextInput::make('transaction_id')
                    ->label('Payment reference')
                    ->placeholder('e.g. bank transfer reference')
                    ->maxLength(255),
            ])
            ->action(function (Order $record, array $data, OrderFulfillmentService $fulfillment) {
                try {
                    $order = $fulfillment->markPaid($record, 'manual', $data['transaction_id'] ?: 'manual_'.Str::lower(Str::random(16)));
                } catch (BusinessRuleException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()
                    ->title("Order {$order->order_number} marked as paid")
                    ->body($order->entitlements->count().' deck(s) unlocked. Confirmation email queued.')
                    ->send();
            });
    }

    /** Moves a paid physical order through processing → shipped → delivered (emails the customer). */
    public static function updateFulfillmentAction(): PageAction
    {
        return PageAction::make('updateFulfillment')
            ->label('Update fulfilment')
            ->icon('heroicon-o-truck')
            ->color('info')
            ->visible(fn (Order $record) => $record->isPaid() && ($record->metadata['requires_shipping'] ?? false))
            ->fillForm(fn (Order $record) => [
                'fulfillment_status' => $record->fulfillment_status,
                'tracking_number' => $record->metadata['tracking_number'] ?? null,
                'tracking_url' => $record->metadata['tracking_url'] ?? null,
            ])
            ->form([
                Select::make('fulfillment_status')->options(FulfillmentStatus::class)->required()->native(false),
                TextInput::make('tracking_number')->maxLength(100),
                TextInput::make('tracking_url')->url()->maxLength(500),
            ])
            ->action(function (Order $record, array $data) {
                $record->update([
                    'fulfillment_status' => $data['fulfillment_status'],
                    'metadata' => [
                        ...($record->metadata ?? []),
                        'tracking_number' => $data['tracking_number'],
                        'tracking_url' => $data['tracking_url'],
                    ],
                ]);

                Notification::make()->success()->title('Fulfilment updated')->body(
                    in_array($record->fulfillment_status, [FulfillmentStatus::Shipped, FulfillmentStatus::Delivered], true)
                        ? 'The customer will be emailed.'
                        : null
                )->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['order_number', 'customer_email', 'transaction_id'];
    }
}
