<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NormalizedProduct;
use App\Models\ProductFamily;
use App\Models\ProductVariant;
use App\Models\SavedCart;
use App\Models\SavedCartItem;
use App\Services\KainuCatalog;
use App\Services\RelatedProductFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SavedCartsController extends Controller
{
    public function __construct(
        private readonly KainuCatalog $catalog,
        private readonly RelatedProductFinder $related,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        \assert($user !== null);

        $carts = $user->savedCarts()
            ->withCount('items')
            ->with(['items' => function ($q): void {
                $q->orderBy('id');
            }])
            ->latest('id')
            ->get();

        $data = $carts->map(fn (SavedCart $cart): array => [
            'id' => $cart->id,
            'name' => $cart->name,
            'itemCount' => (int) $cart->items_count,
            'createdAt' => $cart->created_at?->toIso8601String(),
            'updatedAt' => $cart->updated_at?->toIso8601String(),
            'items' => $cart->items->map(fn (SavedCartItem $item): array => $this->hydrateItem($item))->values(),
            'estimatedTotal' => $this->totalForCart($cart),
        ])->values();

        return Inertia::render('Sarasai/Index', [
            'carts' => $data,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.familyId' => ['nullable', 'integer'],
            'items.*.normalizedProductId' => ['nullable', 'integer'],
            'items.*.variantId' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.storeSlug' => ['nullable', 'string', 'max:64'],
            'items.*.priceCents' => ['nullable', 'integer', 'min:0'],
        ]);

        $user = $request->user();
        \assert($user !== null);

        DB::transaction(function () use ($user, $validated): void {
            /** @var SavedCart $cart */
            $cart = $user->savedCarts()->create([
                'name' => $validated['name'],
            ]);

            foreach ($validated['items'] as $item) {
                $cart->items()->create([
                    'product_family_id' => $this->resolveFamilyId($item),
                    'normalized_product_id' => $item['normalizedProductId'] ?? null,
                    'product_variant_id' => $this->resolveVariantId($item),
                    'quantity' => max(1, (int) $item['quantity']),
                    'snapshot_name' => $item['name'],
                    'snapshot_store_slug' => $item['storeSlug'] ?? null,
                    'snapshot_price_cents' => $item['priceCents'] ?? null,
                ]);
            }
        });

        return back()->with('success', 'Sąrašas išsaugotas.');
    }

    public function update(Request $request, SavedCart $savedCart): RedirectResponse
    {
        Gate::authorize('update', $savedCart);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $savedCart->update(['name' => $validated['name']]);

        return back()->with('success', 'Sąrašas pervadintas.');
    }

    public function destroy(Request $request, SavedCart $savedCart): RedirectResponse
    {
        Gate::authorize('delete', $savedCart);

        $savedCart->delete();

        return back()->with('success', 'Sąrašas ištrintas.');
    }

    public function restore(Request $request, SavedCart $savedCart): JsonResponse
    {
        Gate::authorize('view', $savedCart);

        $savedCart->loadMissing('items');

        $items = $savedCart->items
            ->map(fn (SavedCartItem $item): array => $this->hydrateItem($item, withSuggestions: true))
            ->values();

        return response()->json([
            'id' => $savedCart->id,
            'name' => $savedCart->name,
            'items' => $items,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function hydrateItem(SavedCartItem $item, bool $withSuggestions = false): array
    {
        $current = $this->resolveCurrentOffer($item);

        $snapshotPrice = $item->snapshot_price_cents !== null
            ? round($item->snapshot_price_cents / 100, 2)
            : null;

        return [
            'id' => $item->id,
            'familyId' => $item->product_family_id,
            'normalizedProductId' => $item->normalized_product_id,
            'variantId' => $item->product_variant_id,
            'quantity' => $item->quantity,
            'snapshotName' => $item->snapshot_name,
            'snapshotStoreSlug' => $item->snapshot_store_slug,
            'snapshotPrice' => $snapshotPrice,
            'available' => $current !== null,
            'current' => $current === null ? null : $this->offerToPayload($current),
            // Only computed on restore, and only for items that came back
            // unavailable — the list page must stay cheap, and live items don't
            // need a substitute.
            'suggestions' => $withSuggestions && $current === null
                ? $this->suggestionsForItem($item)
                : [],
        ];
    }

    /**
     * Size-compatible live alternatives for an unavailable item.
     *
     * Prefers the same broad "related products" set the comparison page uses
     * (same family OR same subcategory sharing the core noun) so a substitute is
     * found even when the saved product sits in a different family than its
     * shelf-mates. Legacy rows with only a family fall back to family matching.
     *
     * @return list<array<string, mixed>>
     */
    private function suggestionsForItem(SavedCartItem $item): array
    {
        if ($item->normalized_product_id !== null) {
            $offers = $this->catalog->cheapestLiveOffers(
                $this->related->siblingProductIds($item->normalized_product_id),
                $item->normalized_product_id,
            );
        } elseif ($item->product_family_id !== null) {
            $offers = $this->catalog->substitutesForFamily(
                $item->product_family_id,
                null,
                $item->product_variant_id,
            );
        } else {
            return [];
        }

        return array_map(fn (array $offer): array => $this->offerToPayload($offer), $offers);
    }

    /**
     * The JSON shape the frontend turns straight into a cart product.
     *
     * @param  array<string, mixed>  $offer
     * @return array<string, mixed>
     */
    private function offerToPayload(array $offer): array
    {
        return [
            'snapshotId' => $offer['snapshot_id'],
            'normalizedProductId' => $offer['normalized_product_id'],
            'variantId' => $offer['variant_id'],
            'name' => $offer['product_name'],
            'storeSlug' => $offer['store_slug'],
            'storeName' => $offer['store_name'],
            'price' => $offer['price'],
            'oldPrice' => $offer['old_price'],
            'unit' => $offer['unit'],
            'image' => $offer['image'],
        ];
    }

    private function totalForCart(SavedCart $cart): float
    {
        // Only live offers count: an expired item is skipped on restore, so the
        // headline total must reflect what the list actually costs today rather
        // than a frozen price the shopper can no longer get.
        $total = 0.0;
        foreach ($cart->items as $item) {
            $current = $this->resolveCurrentOffer($item);
            if ($current !== null && $current['price'] !== null) {
                $total += $current['price'] * $item->quantity;
            }
        }

        return round($total, 2);
    }

    /**
     * The current cheapest fresh offer for a saved row.
     *
     * Resolves the *exact* product the user saved first, so a saved item is
     * never silently swapped for a cheaper family sibling. Only rows with no
     * stored normalized product (legacy saves) fall back to family resolution.
     *
     * @return array<string, mixed>|null
     */
    private function resolveCurrentOffer(SavedCartItem $item): ?array
    {
        if ($item->normalized_product_id !== null) {
            return $this->catalog->latestOfferForProduct(
                $item->normalized_product_id,
                $item->product_variant_id,
            );
        }

        if ($item->product_family_id !== null) {
            return $this->catalog->latestOfferForFamily(
                $item->product_family_id,
                $item->product_variant_id,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveFamilyId(array $item): ?int
    {
        $id = $item['familyId'] ?? null;
        if ($id !== null && ProductFamily::query()->whereKey($id)->exists()) {
            return (int) $id;
        }

        $normalizedId = $item['normalizedProductId'] ?? null;
        if ($normalizedId !== null) {
            $familyId = NormalizedProduct::query()
                ->whereKey($normalizedId)
                ->value('family_id');

            return $familyId !== null ? (int) $familyId : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveVariantId(array $item): ?int
    {
        $id = $item['variantId'] ?? null;
        if ($id !== null && ProductVariant::query()->whereKey($id)->exists()) {
            return (int) $id;
        }

        return null;
    }
}
