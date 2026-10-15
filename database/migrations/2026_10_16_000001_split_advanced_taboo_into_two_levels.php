<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deck levels go from three to four: "Advanced_Taboo" becomes "Advanced" and a new "Taboo"
 * level is added. Existing decks and accessory pairing rules are moved to "Advanced".
 */
return new class extends Migration
{
    private const OLD = ['Light_Ignition', 'Medium_Spice', 'Advanced_Taboo'];

    private const NEW = ['Light_Ignition', 'Medium_Spice', 'Advanced', 'Taboo'];

    public function up(): void
    {
        $this->swapConstraint(array_unique([...self::OLD, ...self::NEW]));
        DB::table('decks')->where('segment', 'Advanced_Taboo')->update(['segment' => 'Advanced']);
        $this->renameInProductUpsellRules('Advanced_Taboo', 'Advanced');
        $this->swapConstraint(self::NEW);
    }

    public function down(): void
    {
        $this->swapConstraint(array_unique([...self::OLD, ...self::NEW]));
        DB::table('decks')->whereIn('segment', ['Advanced', 'Taboo'])->update(['segment' => 'Advanced_Taboo']);
        $this->renameInProductUpsellRules('Advanced', 'Advanced_Taboo');
        $this->renameInProductUpsellRules('Taboo', 'Advanced_Taboo');
        $this->swapConstraint(self::OLD);
    }

    /** @param list<string> $values */
    private function swapConstraint(array $values): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // SQLite: enum() is a plain CHECK added at create time; nothing to swap.
        }

        $list = "'".implode("','", $values)."'";
        DB::statement('ALTER TABLE decks DROP CONSTRAINT IF EXISTS decks_segment_check');
        DB::statement("ALTER TABLE decks ADD CONSTRAINT decks_segment_check CHECK (segment::text IN ({$list}))");
    }

    private function renameInProductUpsellRules(string $from, string $to): void
    {
        DB::table('products')->whereNotNull('metadata')->orderBy('id')->each(function ($product) use ($from, $to) {
            $metadata = json_decode($product->metadata, true) ?: [];
            $segments = $metadata['upsell']['segments'] ?? null;

            if (is_array($segments) && in_array($from, $segments, true)) {
                $metadata['upsell']['segments'] = array_values(array_unique(array_map(fn ($s) => $s === $from ? $to : $s, $segments)));
                DB::table('products')->where('id', $product->id)->update(['metadata' => json_encode($metadata)]);
            }
        });
    }
};
