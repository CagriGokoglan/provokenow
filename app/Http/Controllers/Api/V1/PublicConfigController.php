<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use App\Models\SiteSetting;
use App\Services\Seo\SchemaGeneratorService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** Browser-safe site configuration. Never add secrets here (CAPI token, Brevo key, ...). */
class PublicConfigController extends Controller
{
    public function __invoke(SchemaGeneratorService $schema): JsonResponse
    {
        $tracking = config('provoke.tracking');

        return ApiResponse::success([
            'site' => [
                'name' => config('provoke.brand.name'),
                'url' => config('provoke.frontend.url'),
            ],
            'tracking' => [
                'meta_pixel_id' => $tracking['meta_pixel_id'],
                'gtm_container_id' => $tracking['gtm_container_id'],
                'google_site_verification' => $tracking['google_site_verification'],
            ],
            'locale' => [
                'default' => config('provoke.seo.default_locale'),
                'supported' => config('provoke.seo.supported_locales'),
                'currency' => config('provoke.commerce.currency'),
            ],
            'socials' => array_filter((array) SiteSetting::value('socials', []), fn ($url) => is_string($url) && str_starts_with($url, 'https://')),
            'brand_story' => SiteSetting::value('brand_story'),
            'shipping' => [
                'label' => config('provoke.commerce.shipping_label'),
                'badge_text' => SiteSetting::value('discreet_shipping_badge_text') ?: config('provoke.commerce.shipping_badge_text'),
            ],
            'footer_categories' => ProductCategory::active()->where('show_in_footer', true)->get(['name', 'slug']),
            // Site-wide Organization entity for the frontend's root layout.
            'organization_schema_json_ld' => $schema->graph([$schema->organization()]),
        ], 'Public configuration.')->header('Cache-Control', 'public, max-age=300');
    }
}
