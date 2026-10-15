<?php

namespace App\Filament\Resources\ClubSignupResource\Pages;

use App\Filament\Resources\ClubSignupResource;
use App\Models\ClubSignup;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListClubSignups extends ListRecords
{
    protected static string $resource = ClubSignupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Download CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => response()->streamDownload(function () {
                    $out = fopen('php://output', 'w');
                    fputcsv($out, ['email', 'joined_at', 'source']);
                    ClubSignup::orderBy('created_at')->each(fn (ClubSignup $s) => fputcsv($out, [$s->email, $s->created_at?->toIso8601String(), $s->source]));
                    fclose($out);
                }, 'provoke-club-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv'])),
        ];
    }
}
