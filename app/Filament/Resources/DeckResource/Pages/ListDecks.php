<?php

namespace App\Filament\Resources\DeckResource\Pages;

use App\Filament\Resources\DeckResource;
use App\Services\Catalog\DeckJsonImporter;
use Filament\Actions;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ListDecks extends ListRecords
{
    protected static string $resource = DeckResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make(), $this->importAction()];
    }

    /** Paste one deck as JSON (details, page content, digital edition, FAQs, SEO), then add cards. */
    private function importAction(): Actions\Action
    {
        return Actions\Action::make('importDeck')
            ->label('Import deck')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->modalHeading('Import a deck from JSON')
            ->modalDescription('Creates the deck with its page content, digital edition, FAQs and SEO. You land on its page to add the cards. New decks start inactive.')
            ->modalSubmitActionLabel('Import & open deck')
            ->modalWidth('4xl')
            ->form([
                Textarea::make('json')
                    ->label('Deck JSON')
                    ->required()
                    ->rows(18)
                    ->extraInputAttributes(['class' => 'font-mono text-xs'])
                    ->placeholder(DeckJsonImporter::TEMPLATE),
                Toggle::make('update_existing')->label('Update if the deck code exists')->default(false),
                Placeholder::make('format')
                    ->label('Format')
                    ->content(new HtmlString('<details><summary class="cursor-pointer text-primary-600">Show the template</summary><pre class="mt-2 overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs dark:bg-gray-900">'.e(DeckJsonImporter::TEMPLATE).'</pre></details>')),
            ])
            ->action(function (array $data, Actions\Action $action, DeckJsonImporter $importer) {
                $decoded = json_decode((string) $data['json'], true);
                if (! is_array($decoded) || array_is_list($decoded)) {
                    Notification::make()->danger()->title('That is not a single deck JSON object')
                        ->body(json_last_error() !== JSON_ERROR_NONE ? 'JSON error: '.json_last_error_msg() : 'Paste one { … } object, not a list.')
                        ->persistent()->send();
                    $action->halt();
                }

                try {
                    $deck = $importer->import($decoded, (bool) $data['update_existing']);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('The JSON needs fixing')
                        ->body(new HtmlString('<ul class="list-disc pl-4">'.collect($e->errors())->flatten()->map(fn ($m) => '<li>'.e($m).'</li>')->implode('').'</ul>'))
                        ->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()
                    ->title("{$deck->deck_code} — {$deck->title} imported")
                    ->body($importer->unknownCategories
                        ? 'Not found in Deck Categories (skipped): '.implode(', ', $importer->unknownCategories).'. Now add the cards.'
                        : 'Now add the cards.')
                    ->persistent()
                    ->send();

                $this->redirect(DeckResource::getUrl('edit', ['record' => $deck]));
            });
    }
}
