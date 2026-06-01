<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NormalizedProduct;
use App\Models\ProductFamily;
use App\Models\ProductVariant;
use App\Models\SavedCart;
use App\Models\SavedCartItem;
use App\Services\KainuCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SavedCartsController extends Controller
{
    public function __construct(private readonly KainuCatalog $catalog) {}

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

        $items = $savedCart->items->map(fn (SavedCartItem $item): array => $this->hydrateItem($item))->values();

        return response()->json([
            'id' => $savedCart->id,
            'name' => $savedCart->name,
            'items' => $items,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function hydrateItem(SavedCartItem $item): array
    {
        $current = null;
        if ($item->product_family_id !== null) {
            $current = $this->catalog->latestOfferForFamily(
                $item->product_family_id,
                $item->product_variant_id,
            );
        }

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
            'current' => $current === null ? null : [
                'snapshotId' => $current['snapshot_id'],
                'normalizedProductId' => $current['normalized_product_id'],
                'variantId' => $current['variant_id'],
                'name' => $current['product_name'],
                'storeSlug' => $current['store_slug'],
                'storeName' => $current['store_name'],
                'price' => $current['price'],
                'oldPrice' => $current['old_price'],
                'unit' => $current['unit'],
                'image' => $current['image'],
            ],
        ];
    }

    private function totalForCart(SavedCart $cart): float
    {
        $total = 0.0;
        foreach ($cart->items as $item) {
            $price = null;
            if ($item->product_family_id !== null) {
                $current = $this->catalog->latestOfferForFamily(
                    $item->product_family_id,
                    $item->product_variant_id,
                );
                $price = $current['price'] ?? null;
            }

            if ($price === null && $item->snapshot_price_cents !== null) {
                $price = $item->snapshot_price_cents / 100;
            }

            if ($price !== null) {
                $total += $price * $item->quantity;
            }
        }

        return round($total, 2);
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
