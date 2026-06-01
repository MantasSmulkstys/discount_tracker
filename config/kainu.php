<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Catalog visibility floor
    |--------------------------------------------------------------------------
    | ISO datetime (e.g. "2026-05-12 00:00:00"). When set, the public catalog
    | (Products listing + detail + per-product price comparison + stats count)
    | only surfaces offers scraped at or after this moment. Older rows stay in
    | the database — saved shopping lists keep resolving against them via
    | KainuCatalog::latestOfferForFamily() — but no longer appear in browsing.
    |
    | Set to null/empty (default) to disable. Use this to "clean-slate" the
    | catalog UI before a fresh rescrape without deleting historical data.
    */
    'visibility_floor' => env('KAINU_VISIBILITY_FLOOR'),
];
