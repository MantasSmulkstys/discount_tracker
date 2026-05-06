<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ShoppingList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShoppingListController extends Controller
{
    private const SESSION_WINDOW_SECONDS = 1800;

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.normalizedProductId' => ['required', 'integer'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.brand' => ['nullable', 'string', 'max:255'],
            'items.*.storeId' => ['required', 'string', 'max:64'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.unitPrice' => ['nullable', 'numeric'],
            'items.*.unitOldPrice' => ['nullable', 'numeric'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'originalTotal' => ['required', 'numeric', 'min:0'],
            'savings' => ['required', 'numeric', 'min:0'],
        ]);

        $userId = $request->user()->id;
        $items = $validated['items'];
        $itemCount = (int) array_sum(array_column($items, 'qty'));
        $storeIds = array_values(array_unique(array_column($items, 'storeId')));

        $payload = [
            'item_count' => $itemCount,
            'subtotal' => $validated['subtotal'],
            'original_total' => $validated['originalTotal'],
            'savings' => $validated['savings'],
            'items' => $items,
            'store_ids' => $storeIds,
        ];

        $recent = ShoppingList::query()
            ->where('user_id', $userId)
            ->where('updated_at', '>=', now()->subSeconds(self::SESSION_WINDOW_SECONDS))
            ->latest('id')
            ->first();

        if ($recent !== null) {
            $recent->update($payload);
        } else {
            ShoppingList::create(['user_id' => $userId] + $payload);
        }

        return back();
    }
}
