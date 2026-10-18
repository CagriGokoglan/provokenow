<?php

namespace Database\Seeders;

use App\Enums\ProductType;
use App\Models\Deck;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * The Journal's first pillar post ("150 Deep Questions for Couples"). Created once; edits in the
 * admin are never overwritten. Featured decks/boxes are whatever is on sale when it runs.
 *   php artisan db:seed --class=BlogSeeder --force
 */
class BlogSeeder extends Seeder
{
    public const PILLAR_SLUG = 'deep-questions-for-couples';

    public function run(): void
    {
        $category = PostCategory::firstOrCreate(['slug' => 'conversation'], [
            'name' => 'Conversation & Connection',
            'description' => 'Questions, rituals and ideas for closer conversations.',
            'sort_order' => 1,
        ]);

        if (Post::where('slug', self::PILLAR_SLUG)->exists()) {
            return;
        }

        $post = Post::create([
            'post_category_id' => $category->id,
            'is_pillar' => true,
            'title' => '150 Deep Questions for Couples: Fun, Flirty & Deep',
            'slug' => self::PILLAR_SLUG,
            'excerpt' => '150 questions for couples, from playful conversation starters to the deep questions that build real emotional intimacy, plus how to turn them into a weekly ritual.',
            'tldr' => 'Take turns, both answer, and let either of you pass on any question. Start with the 50 fun questions, warm up with the 50 flirty ones, and save the 50 deep questions for an unhurried evening. Repeat weekly: small, regular moments of curiosity build more closeness than grand gestures.',
            'content' => file_get_contents(__DIR__.'/content/deep-questions-for-couples.md'),
            'seo_title' => '150 Deep Questions for Couples: Fun, Flirty & Deep',
            'seo_description' => '150 questions for couples: fun conversation starters, flirty date night questions and deep questions for emotional intimacy. Plus how to make it a ritual.',
            'author_name' => 'The PROVOKE NOW Editors',
            'author_role' => 'Relationship & intimacy editorial team',
            'author_bio' => 'We design conversation card games for couples and test every question at our own tables first. Replace this with your expert author\'s name, credentials and bio in the admin.',
            'faqs' => [
                ['question' => 'What are good deep questions to ask your partner?', 'answer' => 'Questions about needs, fears, values and memories work best: "When do you feel most understood by me?", "What does trust mean to you in practice?" or "What do you need from me when you are stressed?"'],
                ['question' => 'How many questions should we ask in one evening?', 'answer' => 'Fewer than you think. Three to five questions, each followed with genuine curiosity, usually lead to a better conversation than racing through twenty.'],
                ['question' => 'What if one of us does not want to answer?', 'answer' => 'Pass, with no explanation needed. Skipping freely is what keeps the game safe enough for honest answers.'],
            ],
            'is_published' => true,
            'published_at' => now(),
        ]);

        $decks = Deck::active()->whereNotNull('digital_price_cents')->orderBy('deck_code')->limit(2)->pluck('id');
        $post->decks()->sync($decks->mapWithKeys(fn ($id, $i) => [$id => ['sort_order' => $i]])->all());

        $boxes = Product::storefront()->where('product_type', ProductType::PodPhysicalDeck->value)->orderBy('sort_order')->limit(1)->pluck('id');
        $post->products()->sync($boxes->mapWithKeys(fn ($id, $i) => [$id => ['sort_order' => $i]])->all());
    }
}
