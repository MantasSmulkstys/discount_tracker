<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\UnitDimension;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the set of normalized products that count as "the same thing" as a
 * given product — the single source of truth behind both the product-comparison
 * page and saved-list substitute suggestions.
 */
class RelatedProductFinder
{
    /**
     * The normalized products that should be treated as siblings of $productId.
     *
     * Membership = (same AI family) OR (same subcategory/category that shares
     * the family's core noun, e.g. "vanduo" links "gazuotas vanduo" and
     * "mineralinis vanduo"), then narrowed to the same measurement dimension
     * (volume / weight / count) so we still compare like with like. The product
     * itself is always included.
     *
     * @return array<int, int>
     */
    public function siblingProductIds(int $normalizedProductId): array
    {
        $self = DB::table('normalized_products as np')
            ->leftJoin('product_variants as pv', 'pv.normalized_product_id', '=', 'np.id')
            ->leftJoin('product_families as pf', 'pf.id', '=', 'np.family_id')
            ->where('np.id', $normalizedProductId)
            ->select([
                'np.family_id',
                'np.subcategory_id',
                'np.category_id',
                'np.generic_name as np_generic',
                'pv.size_unit',
                'pf.family_name',
                'pf.generic_name as family_generic',
            ])
            ->first();

        if (! $self) {
            return [$normalizedProductId];
        }

        $dimension = UnitDimension::dimension($self->size_unit);
        // Prefer the AI generic_name as the token source: it is the bare head
        // noun ("sviestas", "vanduo"), so it survives names where the noun is
        // not last, e.g. "sviestas su druska" (whose last word is "druska").
        $coreToken = $this->familyCoreToken(
            $self->np_generic ?? $self->family_generic ?? $self->family_name
        );

        [$scopeColumn, $scopeValue] = match (true) {
            $self->subcategory_id !== null => ['np.subcategory_id', (int) $self->subcategory_id],
            $self->category_id !== null => ['np.category_id', (int) $self->category_id],
            default => [null, null],
        };

        $hasFamily = $self->family_id !== null;
        $hasTextMatch = $coreToken !== null && $scopeColumn !== null;

        if (! $hasFamily && ! $hasTextMatch) {
            return [$normalizedProductId];
        }

        $candidates = DB::table('normalized_products as np')
            ->join('product_variants as pv', 'pv.normalized_product_id', '=', 'np.id')
            ->leftJoin('product_families as pf', 'pf.id', '=', 'np.family_id')
            ->where(function ($q) use ($hasFamily, $self, $hasTextMatch, $scopeColumn, $scopeValue, $coreToken): void {
                if ($hasFamily) {
                    $q->where('np.family_id', (int) $self->family_id);
                }

                if ($hasTextMatch) {
                    $like = '%'.$coreToken.'%';
                    $q->orWhere(function ($q2) use ($scopeColumn, $scopeValue, $like): void {
                        $q2->where($scopeColumn, $scopeValue)
                            ->where(function ($q3) use ($like): void {
                                $q3->whereRaw('LOWER(pf.family_name) LIKE ?', [$like])
                                    ->orWhereRaw('LOWER(pf.generic_name) LIKE ?', [$like])
                                    ->orWhereRaw('LOWER(np.generic_name) LIKE ?', [$like])
                                    ->orWhereRaw('LOWER(np.canonical_name) LIKE ?', [$like]);
                            });
                    });
                }
            })
            ->select(['np.id', 'pv.size_unit'])
            ->get();

        $ids = [$normalizedProductId];
        foreach ($candidates as $row) {
            if (UnitDimension::compatible(UnitDimension::dimension($row->size_unit), $dimension)) {
                $ids[] = (int) $row->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The core noun of a family name — the last token, which in Lithuanian is
     * the head noun ("gazuotas vanduo" → "vanduo"). Returns null when there is
     * no usable token to avoid over-broad matching.
     */
    private function familyCoreToken(?string $familyName): ?string
    {
        if ($familyName === null) {
            return null;
        }

        $tokens = preg_split('/\s+/', mb_strtolower(trim($familyName)), -1, PREG_SPLIT_NO_EMPTY);
        if (! $tokens) {
            return null;
        }

        $core = (string) end($tokens);

        return mb_strlen($core) >= 4 ? $core : null;
    }
}
