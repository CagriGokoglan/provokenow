<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LegalPage;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/** Legal / compliance pages, written in the admin (Markdown) and shown at /legal/{slug}. */
class LegalPageController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'pages' => LegalPage::published()->orderBy('sort_order')->orderBy('title')->get(['title', 'slug'])
                ->map(fn (LegalPage $p) => ['title' => $p->title, 'slug' => $p->slug])->values(),
        ], 'Legal pages.');
    }

    public function show(string $slug): JsonResponse
    {
        $page = LegalPage::published()->where('slug', $slug)->firstOrFail();
        // Raw HTML in the Markdown is stripped and unsafe links dropped: only Markdown formatting renders.
        $html = Str::markdown($page->content, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $brand = config('provoke.brand.name');

        return ApiResponse::success([
            'page' => [
                'title' => $page->title,
                'slug' => $page->slug,
                'content_html' => $html,
                'updated_at' => $page->updated_at?->toIso8601String(),
            ],
            'seo' => [
                'title' => $page->seo_title ?: "{$page->title} | {$brand}",
                'description' => $page->seo_description ?: Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($html))), 152),
            ],
            'company' => [
                'name' => config('provoke.company.name'),
                'support_email' => config('provoke.company.support_email'),
            ],
            'contact_form' => $page->slug === LegalPage::CONTACT_SLUG,
        ], 'Legal page.');
    }
}
