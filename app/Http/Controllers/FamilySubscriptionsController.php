<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FamilySubscription;
use App\Services\KainuCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FamilySubscriptionsController extends Controller
{
    public function __construct(private readonly KainuCatalog $catalog) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        \assert($user !== null);

        $subs = FamilySubscription::query()
            ->where('user_id', $user->id)
            ->with(['family'])
            ->latest('id')
            ->get();

        $data = $subs->map(function (FamilySubscription $sub): array {
            $current = $sub->family_id !== null
                ? $this->catalog->latestOfferForFamily($sub->family_id)
                : null;

            return [
                'id' => $sub->id,
                'familyId' => $sub->family_id,
                'familyName' => $sub->family?->family_name
                    ?? $sub->family?->normalized_family_name
                    ?? 'Nežinoma prekė',
                'createdAt' => $sub->created_at?->toIso8601String(),
                'lastSeen' => $current === null ? null : [
                    'name' => $current['product_name'],
                    'storeSlug' => $current['store_slug'],
                    'storeName' => $current['store_name'],
                    'price' => $current['price'],
                    'oldPrice' => $current['old_price'],
                    'image' => $current['image'],
                ],
            ];
        })->values();

        return Inertia::render('Profilis/Sekimai', [
            'subscriptions' => $data,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'family_id' => ['required', 'integer', Rule::exists('product_families', 'id')],
        ]);

        $user = $request->user();
        \assert($user !== null);

        FamilySubscription::firstOrCreate([
            'user_id' => $user->id,
            'family_id' => (int) $validated['family_id'],
            'store_id' => null,
        ]);

        return back();
    }

    public function destroy(Request $request, FamilySubscription $familySubscription): RedirectResponse
    {
        Gate::authorize('delete', $familySubscription);

        $familySubscription->delete();

        return back();
    }
}
