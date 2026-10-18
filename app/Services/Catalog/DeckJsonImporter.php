<?php

namespace App\Services\Catalog;

use App\Enums\DeckSegment;
use App\Models\Deck;
use App\Models\DeckCategory;
use App\Models\Product;
use App\Services\Seo\SeoMetadataService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates (or updates) one deck from the admin's "Import deck" JSON: details, page content,
 * digital edition, FAQs and SEO in one go. Cards are added afterwards on the deck's page.
 * See DeckJsonImporter::TEMPLATE for the format.
 */
class DeckJsonImporter
{
    public const TEMPLATE = <<<'JSON'
{
  "deck_code": "DECK-001",
  "title": "First Light",
  "segment": "Light Ignition",
  "intensity_level": 1,
  "core_theme_keywords": ["curiosity", "laughter", "first questions"],
  "fantasy_categories": ["Sensory"],
  "whats_inside": "Paragraph one.\n\nParagraph two.",
  "how_to_play": "- Step one\n- Step two\n- Step three",
  "who_its_for": "Who this deck is for, in 2-4 sentences.",
  "digital_price": 12.99,
  "digital_sku": "DIG-DECK-001",
  "sell_digital": true,
  "faqs": [
    { "question": "Question 1?", "answer": "Answer 1." },
    { "question": "Question 2?", "answer": "Answer 2." }
  ],
  "meta_title": "First Light: Card Game for Couples | PROVOKE NOW",
  "meta_description": "140-155 characters with the primary keyword first and a conversion trigger.",
  "canonical_url": null
}
JSON;

    /** @var list<string> fantasy categories in the JSON that don't exist in the admin */
    public array $unknownCategories = [];

    /**
     * @param  array<string, mixed>  $input  one deck object
     *
     * @throws ValidationException
     */
    public function import(array $input, bool $updateExisting = false): Deck
    {
        $input = $this->normalise($input);
        $existing = Deck::where('deck_code', $input['deck_code'] ?? '')->first();

        if ($existing && ! $updateExisting) {
            throw ValidationException::withMessages(['deck_code' => "{$input['deck_code']} already exists. Tick \"Update if the deck code exists\" to overwrite it."]);
        }

        $data = Validator::make($input, [
            'deck_code' => ['required', 'string', 'max:20', 'regex:/^DECK-\d{3,}$/'],
            'title' => ['required', 'string', 'max:255'],
            'segment' => ['required', Rule::enum(DeckSegment::class)],
            'intensity_level' => ['required', 'integer', 'between:1,4'],
            'core_theme_keywords' => ['nullable', 'string', 'max:1000'],
            'fantasy_categories' => ['nullable', 'array'],
            'fantasy_categories.*' => ['string', 'max:80'],
            'whats_inside' => ['nullable', 'string', 'max:2000'],
            'how_to_play' => ['nullable', 'string', 'max:2000'],
            'who_its_for' => ['nullable', 'string', 'max:2000'],
            'digital_price' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'digital_sku' => [
                'nullable', 'string', 'max:64',
                Rule::unique('decks', 'digital_sku')->ignore($existing?->getKey()),
                function (string $attribute, mixed $value, \Closure $fail) use ($existing) {
                    $own = $existing?->digitalProduct?->getKey();
                    if (Product::where('sku', $value)->when($own, fn ($q) => $q->whereKeyNot($own))->exists()) {
                        $fail('A store product already uses this SKU.');
                    }
                },
            ],
            'sell_digital' => ['boolean'],
            'faqs' => ['nullable', 'array'],
            'faqs.*.question' => ['required', 'string', 'max:200'],
            'faqs.*.answer' => ['required', 'string', 'max:1500'],
            'meta_title' => ['nullable', 'string', 'max:'.SeoMetadataService::TITLE_MAX],
            'meta_description' => ['nullable', 'string', 'max:'.SeoMetadataService::DESCRIPTION_MAX],
            'canonical_url' => ['nullable', 'url', 'max:255'],
        ], [
            'deck_code.regex' => 'Use the format DECK-001 (at least three digits).',
            'segment' => 'Segment must be Light Ignition, Medium Spice, Advanced or Taboo.',
        ])->validate();

        return DB::transaction(function () use ($data, $existing) {
            $deck = $existing ?? new Deck(['is_active' => false]);
            $deck->fill([
                'deck_code' => $data['deck_code'],
                'title' => $data['title'],
                'segment' => $data['segment'],
                'intensity_level' => $data['intensity_level'],
                'core_theme_keywords' => $data['core_theme_keywords'] ?? null,
                'whats_inside' => $data['whats_inside'] ?? null,
                'how_to_play' => $data['how_to_play'] ?? null,
                'who_its_for' => $data['who_its_for'] ?? null,
                'digital_price_cents' => isset($data['digital_price']) ? (int) round($data['digital_price'] * 100) : null,
                'digital_sku' => $data['digital_sku'] ?? null,
                'is_digital_sale_active' => isset($data['digital_price']) && ($data['sell_digital'] ?? true),
                'faqs' => array_values($data['faqs'] ?? []) ?: null,
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'canonical_url' => $data['canonical_url'] ?? null,
            ]);
            $deck->save();

            $deck->categories()->sync($this->categoryIds($data['fantasy_categories'] ?? []));

            return $deck;
        });
    }

    /** Accepts friendly values: "Medium Spice" / "medium-spice", keyword arrays, "$12.99". */
    private function normalise(array $input): array
    {
        if (is_string($input['deck_code'] ?? null)) {
            $input['deck_code'] = strtoupper(trim($input['deck_code']));
        }

        if (is_string($input['segment'] ?? null)) {
            $raw = trim($input['segment']);
            $input['segment'] = (DeckSegment::tryFrom($raw)
                ?? DeckSegment::fromSlug(Str::slug($raw))
                ?? DeckSegment::tryFrom(str_replace(' ', '_', Str::title($raw))))?->value ?? $raw;
        }

        if (is_array($input['core_theme_keywords'] ?? null)) {
            $input['core_theme_keywords'] = implode(', ', array_filter(array_map('trim', $input['core_theme_keywords'])));
        }

        if (is_string($input['digital_price'] ?? null)) {
            $input['digital_price'] = str_replace(['$', ','], ['', '.'], trim($input['digital_price']));
        }

        foreach (['whats_inside', 'how_to_play', 'who_its_for', 'digital_sku', 'meta_title', 'meta_description', 'canonical_url'] as $key) {
            if (isset($input[$key]) && is_string($input[$key]) && trim($input[$key]) === '') {
                $input[$key] = null;
            }
        }

        return $input;
    }

    /** @param list<string> $names category names or slugs */
    private function categoryIds(array $names): array
    {
        $this->unknownCategories = [];
        $ids = [];
        foreach ($names as $name) {
            $category = DeckCategory::where('slug', Str::slug($name))->orWhereRaw('lower(name) = ?', [mb_strtolower(trim($name))])->first();
            $category ? $ids[] = $category->getKey() : $this->unknownCategories[] = $name;
        }

        return array_values(array_unique($ids));
    }
}
