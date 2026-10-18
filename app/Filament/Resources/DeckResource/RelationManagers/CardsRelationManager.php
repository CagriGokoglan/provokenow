<?php

namespace App\Filament\Resources\DeckResource\RelationManagers;

use App\Models\Card;
use App\Models\Deck;
use App\Services\Catalog\CardImporter;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

/**
 * The 50 cards of a deck, edited with the 5-column content schema. Bulk import accepts JSON
 * or rows pasted from a spreadsheet; export produces JSON in the same shape for round trips.
 */
class CardsRelationManager extends RelationManager
{
    protected static string $relationship = 'cards';

    protected static ?string $recordTitleAttribute = 'title';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Cards ('.$ownerRecord->cards()->count().' / '.CardImporter::MAX_CARDS.')';
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Grid::make(3)->schema([
                TextInput::make('card_number')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->maxValue(CardImporter::MAX_CARDS)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('deck_id', $this->getOwnerRecord()->getKey())),
                TextInput::make('content_payload.Card_ID')->label('Card_ID')->placeholder('Generated when empty'),
                TextInput::make('title')->required()->maxLength(255),
            ]),
            Textarea::make('content_payload.Setting_and_Scenario')->label('Setting & Scenario')->rows(3)->columnSpanFull(),
            Textarea::make('content_payload.Action_Task')->label('Action / Task')->rows(4)->columnSpanFull(),
            Textarea::make('content_payload.Safety_and_Consent_Note')->label('Safety & Consent Note')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('card_number')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(50)
            ->columns([
                Tables\Columns\TextColumn::make('card_number')->label('#')->sortable()->width('4rem'),
                Tables\Columns\TextColumn::make('title')->searchable()->weight('medium'),
                Tables\Columns\TextColumn::make('content_payload.Action_Task')->label('Action / Task')->limit(70)->wrap(),
                Tables\Columns\IconColumn::make('has_safety_note')
                    ->label('Consent note')
                    ->state(fn (Card $record) => filled($record->content_payload['Safety_and_Consent_Note'] ?? null))
                    ->boolean(),
            ])
            ->headerActions([
                $this->importAction(),
                $this->exportAction(),
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(fn (array $data) => $this->withCardId($data)),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    // Merge so payload keys not shown in the form (custom columns) are preserved.
                    ->using(function (Card $record, array $data) {
                        $data = $this->withCardId($data);
                        $data['content_payload'] = array_merge($record->content_payload ?? [], $data['content_payload'] ?? []);
                        $record->update($data);

                        return $record;
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    private function importAction(): Action
    {
        return Action::make('import')
            ->label('Bulk import')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('primary')
            ->modalDescription('Paste a JSON array, or copy rows straight from Google Sheets / Excel including the header row. Columns: Card_ID, Title, Setting_and_Scenario, Action_Task, Safety_and_Consent_Note. Existing cards with the same number are updated.')
            ->form([
                Textarea::make('payload')
                    ->hiddenLabel()
                    ->required()
                    ->rows(16)
                    ->extraInputAttributes(['class' => 'font-mono text-xs'])
                    ->placeholder("Card_ID\tTitle\tSetting_and_Scenario\tAction_Task\tSafety_and_Consent_Note\nDECK-001-C01\tFirst Spark\t…\t…\t…"),
            ])
            ->action(function (array $data, Action $action, CardImporter $importer) {
                /** @var Deck $deck */
                $deck = $this->getOwnerRecord();

                try {
                    $result = $importer->import($deck, $importer->parse($data['payload'], $deck->deck_code));
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Import failed')->body(implode("\n", $e->errors()['payload'] ?? []))->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()
                    ->title('Cards imported')
                    ->body("{$result['created']} created, {$result['updated']} updated.")
                    ->send();
            });
    }

    private function exportAction(): Action
    {
        return Action::make('export')
            ->label('Export JSON')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(function () {
                /** @var Deck $deck */
                $deck = $this->getOwnerRecord();
                $cards = $deck->cards()->get()->map(fn (Card $card) => [
                    'card_number' => $card->card_number,
                    'Title' => $card->title,
                    ...($card->content_payload ?? []),
                ]);

                return response()->streamDownload(
                    fn () => print (json_encode($cards, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    "{$deck->deck_code}-cards.json",
                    ['Content-Type' => 'application/json'],
                );
            });
    }

    private function withCardId(array $data): array
    {
        if (blank($data['content_payload']['Card_ID'] ?? null)) {
            $data['content_payload']['Card_ID'] = sprintf('%s-C%02d', $this->getOwnerRecord()->deck_code, $data['card_number']);
        }
        $data['content_payload']['Title'] = $data['title'];

        return $data;
    }
}
