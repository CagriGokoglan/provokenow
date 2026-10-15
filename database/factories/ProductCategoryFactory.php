<?php

namespace Database\Factories;

use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word());

        return ['name' => $name, 'slug' => Str::slug($name), 'sort_order' => 0, 'is_active' => true];
    }
}
