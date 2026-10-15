<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
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
    private const KEYS = ['home_video', 'club', 'instagram', 'socials', 'brand_story'];

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
            Section::make('Full-page video')->description('Plays muted on a loop between New Arrivals and Provoke Club.')->columns(2)->schema([
                FileUpload::make('home_video.file')
                    ->label('Video file (MP4 / WebM)')
                    ->acceptedFileTypes(['video/mp4', 'video/webm'])
                    ->disk('public')->directory('home/video')->visibility('public')
                    ->maxSize(102400)
                    ->helperText('Up to 100 MB. Keep it short (10–30 s) and compressed for fast loading.'),
                TextInput::make('home_video.url')
                    ->label('…or video URL')
                    ->url()
                    ->placeholder('https://cdn.example.com/brand-film.mp4')
                    ->helperText('Used when no file is uploaded.'),
                FileUpload::make('home_video.poster')
                    ->label('Poster image')
                    ->image()
                    ->disk('public')->directory('home/video')->visibility('public')
                    ->maxSize(4096)
                    ->helperText('Shown while the video loads and for visitors who prefer reduced motion.'),
                TextInput::make('home_video.headline')->label('Overlay headline')->maxLength(120),
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
