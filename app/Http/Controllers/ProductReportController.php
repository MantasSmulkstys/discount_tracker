<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NormalizedProduct;
use App\Models\ProductReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductReportController extends Controller
{
    public function store(Request $request, NormalizedProduct $normalizedProduct): RedirectResponse
    {
        ProductReport::firstOrCreate([
            'user_id' => $request->user()->id,
            'normalized_product_id' => $normalizedProduct->id,
        ]);

        return back();
    }

    public function destroy(Request $request, NormalizedProduct $normalizedProduct): RedirectResponse
    {
        ProductReport::where('user_id', $request->user()->id)
            ->where('normalized_product_id', $normalizedProduct->id)
            ->delete();

        return back();
    }
}
