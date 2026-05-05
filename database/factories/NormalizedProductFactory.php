<?php

namespace Database\Factories;

use App\Models\NormalizedProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NormalizedProduct>
 */
class NormalizedProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'canonical_name' => $name,
            'normalized_name' => mb_strtolower($name),
            'brand' => fake()->optional()->company(),
            'generic_name' => null,
            'category_id' => null,
            'subcategory_id' => null,
            'family_id' => null,
            'ai_confidence' => fake()->randomFloat(4, 0, 1),
            'needs_review' => false,
            'normalization_version' => '1.0',
            'source_name' => null,
            'image_url' => null,
        ];
    }

    public function lowConfidence(): static
    {
        return $this->state(['ai_confidence' => fake()->randomFloat(4, 0, 0.699)]);
    }

    public function needsReview(): static
    {
        return $this->state(['needs_review' => true]);
    }
}
