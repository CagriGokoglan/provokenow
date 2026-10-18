<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Support\YouTube;
use Filament\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/** Homepage video, Provoke Club, Instagram account, social links and footer brand story. */
class SiteSettings extends Page implements HasForms
{
    use InteractsWithForms;

    /** Setting keys this page owns. */
    private const KEYS = ['home_video', 'club', 'instagram', 'socials', 'brand_story', 'discreet_shipping_badge_text'];

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Homepage';

    protected static ?string $navigationLabel = 'Site Settings';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.site-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(collect(self::KEYS)->mapWithKeys(fn ($key) => [$key => SiteSetting::value($key)])->all());
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Section::make('Full-page video')->description('A YouTube video that autoplays muted on a loop, without controls, between New Arrivals and Provoke Club.')->columns(2)->schema([
                TextInput::make('home_video.url')
                    ->label('YouTube link')
                    ->placeholder('https://www.youtube.com/watch?v=…')
                    ->rule(YouTube::rule())
                    ->columnSpanFull(),
                Grid::make(2)->schema([
                    TextInput::make('home_video.start')
                        ->label('Skip first (seconds)')
                        ->numeric()->integer()->minValue(0)->maxValue(36000)
                        ->placeholder('0'),
                    TextInput::make('home_video.end_trim')
                        ->label('Cut last (seconds)')
                        ->numeric()->integer()->minValue(0)->maxValue(36000)
                        ->placeholder('0'),
                ]),
                TextInput::make('home_video.opacity')
                    ->label('Video opacity')
                    ->numeric()->integer()->minValue(0)->maxValue(100)
                    ->suffix('%')
                    ->default(100)
                    ->helperText('Lower it to darken the video behind the headline (100 = fully visible).'),
                TextInput::make('home_video.headline')
                    ->label('Overlay headline')
                    ->maxLength(120)
                    ->helperText('Set like the other headlines: the last word in gold italic.'),
            ]),

            Section::make('Provoke Club (coming soon)')->columns(2)->schema([
                Toggle::make('club.enabled')->label('Show the section')->default(true)->columnSpanFull(),
                TextInput::make('club.headline')->maxLength(120)->placeholder('Provoke Club'),
                Textarea::make('club.body')->rows(3)->maxLength(400),
            ]),

            Section::make('Instagram')->columns(2)->schema([
                TextInput::make('instagram.handle')->prefix('@')->maxLength(60),
                TextInput::make('instagram.profile_url')->url()->placeholder('https://www.instagram.com/provokenow/'),
            ]),

            Section::make('Shipping')->description('Shipping is always complimentary. This reassurance appears on product pages, the cart and order emails.')->schema([
                TextInput::make('discreet_shipping_badge_text')
                    ->label('Discreet shipping badge')
                    ->maxLength(160)
                    ->placeholder(config('provoke.commerce.shipping_badge_text'))
                    ->helperText('Leave empty to use the default shown above.'),
            ]),

            Section::make('Footer')->schema([
                Textarea::make('brand_story')->label('Brand story')->rows(3)->maxLength(500),
                Grid::make(3)->schema(collect(['instagram', 'tiktok', 'x', 'pinterest', 'youtube'])->map(
                    fn (string $network) => TextInput::make("socials.{$network}")
                        ->label(match ($network) {
                            'x' => 'X (Twitter)', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', default => ucfirst($network)
                        })
                        ->url()
                        ->startsWith(['https://'])
                )->all()),
            ]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach (self::KEYS as $key) {
            SiteSetting::put($key, $state[$key] ?? null);
        }

        Notification::make()->success()->title('Site settings saved')->send();
    }

    /** @return list<Action> */
    protected function getFormActions(): array
    {
        return [Action::make('save')->label('Save settings')->submit('save')];
    }
}
