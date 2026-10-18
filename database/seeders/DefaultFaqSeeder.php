<?php

namespace Database\Seeders;

use App\Models\SiteSetting;
use App\Services\Seo\Faqs;
use Illuminate\Database\Seeder;

/**
 * Starter default FAQs (Site Settings → Default FAQs). Only fills an empty list, so admin edits
 * are never overwritten. Answers state only what the shop actually does; add your returns policy
 * in the admin.
 *   php artisan db:seed --class=DefaultFaqSeeder --force
 */
class DefaultFaqSeeder extends Seeder
{
    public const DECK = [
        ['How do I play the digital deck?', 'Right in your browser — on your phone, tablet or computer. There is nothing to download: the deck unlocks in your private library the moment payment completes, and you swipe through the conversation starters together.'],
        ['Is this card game made for couples?', 'Yes. Every deck is a relationship card game for 2 players: deep questions, playful dares and invitations designed to build emotional intimacy, whether it is your third date or your twentieth anniversary.'],
        ['Is it suitable for adults only?', 'Yes. PROVOKE NOW is intended for adults aged 18 and over. By purchasing you confirm that you are of legal age where you live.'],
        ['Is my purchase private?', 'Yes. Your decks live in your own account library, and you can add a vault passcode so the most intimate decks stay hidden even on a shared device. Printed boxes ship in plain, unbranded packaging.'],
        ['Can I get it as a printed box?', 'Where a printed box set is offered on this page, it includes free instant digital access, so you can start playing while your box is on its way, with complimentary discreet shipping.'],
    ];

    public const PRODUCT = [
        ['How discreet is the packaging?', 'Every order ships in plain, unbranded black packaging with no product names or logos on the outside, so nothing reveals what is inside.'],
        ['How much is shipping?', 'Nothing. Shipping is complimentary on every order.'],
        ['Can I send it as a gift?', 'Of course. Enter the recipient\'s address at checkout and it arrives in the same discreet, unbranded packaging.'],
        ['Is this product for adults only?', 'Yes. PROVOKE NOW is intended for adults aged 18 and over. By ordering you confirm that you are of legal age where you live.'],
    ];

    public function run(): void
    {
        foreach ([Faqs::DECK_DEFAULTS => self::DECK, Faqs::PRODUCT_DEFAULTS => self::PRODUCT] as $key => $faqs) {
            if (app(Faqs::class)->clean(SiteSetting::value($key)) === []) {
                SiteSetting::put($key, array_map(fn (array $f) => ['question' => $f[0], 'answer' => $f[1]], $faqs));
            }
        }
    }
}
