<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
            // Site-wide Organization entity for the frontend's root layout.
            'organization_schema_json_ld' => $schema->graph([$schema->organization()]),
        ], 'Public configuration.')->header('Cache-Control', 'public, max-age=300');
    }
}
