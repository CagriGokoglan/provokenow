<?php

namespace App\Services\Catalog;

use App\Models\Card;
use App\Models\Deck;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bulk card import for the admin panel.
 *
 * Accepts either
 *  - JSON: an array of card objects (or {"cards": [...]}), or
 *  - CSV / TSV with a header row (paste straight from Google Sheets / Excel),
 * using the deck content columns: Card_ID, Title, Setting_and_Scenario, Action_Task,
 * Safety_and_Consent_Note (+ optional card_number). Unknown columns are kept in the payload.
 *
 * The card number comes from card_number, else the trailing digits of Card_ID, else row order.
 */
class CardImporter
{
    public const MAX_CARDS = 50;

    /** Normalised header → canonical payload key. */
    private const COLUMNS = [
        'cardid' => 'Card_ID',
        'title' => 'Title',
        'settingandscenario' => 'Setting_and_Scenario',
        'actiontask' => 'Action_Task',
        'safetyandconsentnote' => 'Safety_and_Consent_Note',
    ];

    /**
     * @return list<array{card_number: int, title: string, content_payload: array<string, mixed>}>
     *
     * @throws ValidationException
     */
    public function parse(string $raw, string $deckCode): array
    {
        $raw = trim(preg_replace('/^\xEF\xBB\xBF/', '', $raw));
        if ($raw === '') {
            $this->fail('Paste JSON or spreadsheet rows to import.');
        }

        $records = in_array($raw[0], ['[', '{'], true) ? $this->fromJson($raw) : $this->fromDelimited($raw);

        $cards = [];
        $errors = [];

        foreach (array_values($records) as $i => $record) {
            $row = $i + 1;
            $payload = $this->canonicalise($record);
            $number = $this->cardNumber($record, $payload, $row);
            $title = trim((string) ($payload['Title'] ?? ''));

            if ($number < 1 || $number > self::MAX_CARDS) {
                $errors[] = "Row {$row}: card number must be between 1 and ".self::MAX_CARDS.'.';
            } elseif (isset($cards[$number])) {
                $errors[] = "Row {$row}: card {$number} appears more than once.";
            }
            if ($title === '') {
                $errors[] = "Row {$row}: Title is required.";
            }

            $payload['Card_ID'] = filled($payload['Card_ID'] ?? null)
                ? (string) $payload['Card_ID']
                : sprintf('%s-C%02d', $deckCode, $number);

            $cards[$number] = ['card_number' => $number, 'title' => $title, 'content_payload' => $payload];
        }

        if (count($records) > self::MAX_CARDS) {
            $errors[] = 'A deck holds at most '.self::MAX_CARDS.' cards; got '.count($records).'.';
        }

        if ($errors !== []) {
            $this->fail(implode("\n", array_slice($errors, 0, 10)));
        }

        ksort($cards);

        return array_values($cards);
    }

    /**
     * Upserts cards by card number. Existing cards not in the import are left untouched.
     *
     * @param  list<array{card_number: int, title: string, content_payload: array}>  $cards
     * @return array{created: int, updated: int}
     */
    public function import(Deck $deck, array $cards): array
    {
        return DB::transaction(function () use ($deck, $cards) {
            $created = 0;
            $updated = 0;

            foreach ($cards as $card) {
                $model = Card::updateOrCreate(
                    ['deck_id' => $deck->getKey(), 'card_number' => $card['card_number']],
                    ['title' => $card['title'], 'content_payload' => $card['content_payload']],
                );
                $model->wasRecentlyCreated ? $created++ : $updated++;
            }

            $deck->touch();

            return ['created' => $created, 'updated' => $updated];
        });
    }

    /** @return list<array<string, mixed>> */
    private function fromJson(string $raw): array
    {
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            $this->fail('Invalid JSON: '.json_last_error_msg().'.');
        }

        $data = $data['cards'] ?? $data;
        if (! array_is_list($data) || ! collect($data)->every(fn ($r) => is_array($r))) {
            $this->fail('JSON must be an array of card objects.');
        }

        return $data;
    }

    /** @return list<array<string, string>> */
    private function fromDelimited(string $raw): array
    {
        $firstLine = strtok($raw, "\n");
        $delimiter = substr_count($firstLine, "\t") >= substr_count($firstLine, ',') ? "\t" : ',';

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $raw);
        rewind($stream);

        $header = null;
        $rows = [];
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if ($cells === [null]) {
                continue; // blank line
            }
            if ($header === null) {
                $header = array_map('trim', $cells);

                continue;
            }
            $rows[] = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
        }
        fclose($stream);

        if ($header === null || ! in_array('title', array_map(fn ($h) => $this->normalise($h), $header), true)) {
            $this->fail('The first row must be a header including a "Title" column.');
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function canonicalise(array $record): array
    {
        $payload = [];
        foreach ($record as $key => $value) {
            $normalised = $this->normalise((string) $key);
            if (in_array($normalised, ['cardnumber', 'number'], true)) {
                continue;
            }
            $payload[self::COLUMNS[$normalised] ?? (string) $key] = is_string($value) ? trim($value) : $value;
        }

        return $payload;
    }

    private function cardNumber(array $record, array $payload, int $row): int
    {
        foreach ($record as $key => $value) {
            if (in_array($this->normalise((string) $key), ['cardnumber', 'number'], true) && is_numeric($value)) {
                return (int) $value;
            }
        }

        if (preg_match('/(\d+)\s*$/', (string) ($payload['Card_ID'] ?? ''), $m)) {
            return (int) $m[1];
        }

        return $row;
    }

    private function normalise(string $key): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(str_replace('&', 'and', $key)));
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['payload' => $message]);
    }
}
