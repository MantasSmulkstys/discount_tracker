# Database schema — Discount Tracker

The database is shared by two processes: the **Python scraper** (`uscraper/`) writes raw offers and the **Laravel web app** (`discounttracker/`) reads from the normalized side and orchestrates the normalization pipeline.

Production uses **PostgreSQL 15+**. Tests run on **SQLite `:memory:`**.

---

## Domains

Tables group cleanly into four domains:

| Domain | Tables |
|---|---|
| **Taxonomy** | `categories`, `subcategories` |
| **Catalog** | `stores`, `product_families`, `normalized_products`, `product_variants` |
| **Pricing & history** | `scrape_batches`, `raw_offers`, `offer_snapshots`, `current_offers`, `price_changes` |
| **Users & engagement** | `users`, `product_reports`, `shopping_lists`, `saved_carts`, `saved_cart_items`, `family_subscriptions`, `notification_log` |

Framework tables (`cache`, `jobs`, `sessions`, `failed_jobs`, etc.) are managed by Laravel and not documented here.

---

## Data flow

```
              ┌─────────────────────────────────┐
              │  Python scraper (uscraper/)     │
              └────────────────┬────────────────┘
                               ▼
                          scrape_batches
                               │
                          (one per store-week, idempotent)
                               ▼
                          raw_offers
                               │
                          (normalization_status: pending → processing → done | failed)
                               ▼
              ┌─────────────────────────────────┐
              │  Python normalizer              │
              │  (process_raw_offers_normalized)│
              └────────────────┬────────────────┘
                               │ AI + deterministic fallback
                               ▼
          product_families ── normalized_products ── product_variants
                                                          │
                                                          ▼
                                                   offer_snapshots  (price history, append-only)
                                                          │
                                              ┌───────────┴───────────┐
                                              ▼                       ▼
                                       current_offers           price_changes
                                       (latest per             (delta rows for
                                        variant×store)          /statistika)
```

The Laravel read path joins through `offer_snapshots` filtered to `OfferSnapshot::latestIdsPerProductStoreSubquery()` and applies a 14-day freshness window. `current_offers` exists for cart and saved-list lookups where freshness can be relaxed.

---

## Taxonomy

### `categories`
Top-level grocery categories (Lithuanian).

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint | No | PK |
| `name_lt` | text | No | Unique. Lithuanian display name. |
| `slug` | text | No | Unique. URL-safe identifier. |

### `subcategories`

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint | No | PK |
| `category_id` | foreign | No | `categories.id`, cascade. |
| `name_lt` | text | No | |
| `slug` | text | No | |

Unique on `(category_id, name_lt)` and `(category_id, slug)`.

---

## Catalog

### `stores`
The supermarket chains we track.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint | No | PK |
| `name` | string | No | Unique. Lowercase machine name (e.g. `maxima`). UI capitalises via `Str::title()`. |
| `slug` | string | No | Unique. Same value as `name` for now; kept separate to allow future renames. |
| `website_url` | text | Yes | |
| `is_active` | bool | No | Default `true`. |
| `scrape_cron` | string | Yes | Optional per-store cron expression (e.g. `'0 6 * * 1'`). Read by `routes/console.php`. |
| `logo_path` | string | Yes | Relative path under `public/`, e.g. `/store-logos/maxima.png`. |
| `created_at` / `updated_at` | timestamp | No | |

### `product_families`
Cross-store match key. Many `normalized_products` (one per phrasing) can share one family.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint | No | PK |
| `family_name` | string | Yes | Human-friendly display. |
| `normalized_family_name` | string | No | Unique. ASCII-only, diacritic-stripped key from `normalization/ai_normalize.normalize_identity_text()`. |
| `category_id` | foreign | Yes | `categories.id`, nullable. |
| `subcategory_id` | foreign | Yes | `subcategories.id`, nullable. |
| `generic_name` | string | Yes | Older AI output style; kept for back-compat. |
| `created_at` / `updated_at` | timestamp | No | |

### `normalized_products`
The canonical product identity. One row per distinct `(normalized_name, brand, category_id, subcategory_id)` tuple, enforced by a generated `identity_key` unique index.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint | No | PK |
| `canonical_name` | text | No | UI display name (e.g. *"Pienas 2,5% Rokiškio"*). |
| `normalized_name` | text | No | ASCII-collapsed lowercase form. Driven by the AI. |
| `brand` | string | Yes | Lowercase brand (case-insensitive in `identity_key`). |
| `generic_name` | string | Yes | Plain product type (e.g. *"pienas"*). |
| `category_id` | foreign | Yes | |
| `subcategory_id` | foreign | Yes | |
| `family_id` | foreign | Yes | `product_families.id`. |
| `ai_confidence` | decimal(5,4) | Yes | `< REVIEW_CONFIDENCE_THRESHOLD` (0.7) flips review queue inclusion. |
| `needs_review` | bool | No | Default `false`. |
| `normalization_version` | string | Yes | e.g. `ai_v1`, `deterministic_v1`, `cached_by_content_hash`. |
| `source_name` | text | Yes | Raw scraped name kept as origin trace. |
| `image_url` | text | Yes | First non-placeholder image we saw. |
| `identity_key` | generated text | No | `normalized_name | brand | category_id | subcategory_id`, NULL-safe via `COALESCE`. UNIQUE. |
| `created_at` / `updated_at` | timestamp | No | |

### `product_variants`
A specific size/package variant of a normalized product. One row per `(normalized_product_id, size_value, size_unit, package_count, package_unit)`.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint | No | PK |
| `normalized_product_id` | foreign | No | Cascade on delete. |
| `variant_name` | text | Yes | Display label, e.g. *"1 l"*. |
| `size_value` / `size_unit` | decimal(10,3) / string(16) | Yes | e.g. `1.000` / `l`. |
| `package_count` / `package_unit` | decimal(10,3) / string(16) | Yes | e.g. `10` / `vnt`. |
| `net_quantity_value` / `net_quantity_unit` | decimal(10,3) / string(16) | Yes | Independent of size; used when AI reports a separate "net" value. |
| `attributes` | jsonb | Yes | Free-form AI-extracted attributes. |
| `identity_key` | generated text | No | UNIQUE. |
| `created_at` / `updated_at` | timestamp | No | |

---

## Pricing & history

### `scrape_batches`
One row per `(store_id, week_starting)`. Re-running mid-week reuses the row.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint | No | PK |
| `store_id` | foreign | Yes | `nullOnDelete`. |
| `source_name` | text | No | e.g. `iki`, `multi_store_scrape`, `demo:price-changes`. |
| `notes` | text | Yes | |
| `week_starting` | date | Yes | Monday of ISO week. Unique with `store_id`. |
| `started_at` | timestamp | No | |
| `finished_at` | timestamp | Yes | |
| `status` | string(16) | No | Default `running`. Enum (Postgres CHECK): `running`, `completed`, `failed`. |
| `offers_found` / `_inserted` / `_updated` / `_failed` | int | No | Default 0. Telemetry. |
| `error_message` | text | Yes | |
| `created_at` / `updated_at` | timestamp | No | |

### `raw_offers`
Every scraped product card before normalization.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint | No | PK |
| `store_id` | foreign | No | Cascade. |
| `scrape_batch_id` | foreign | Yes | `nullOnDelete`. |
| `content_hash` | string(64) | Yes | SHA-256 of `(store, name, url, unit_price, condition)`. Unique with `scrape_batch_id`. |
| `external_sku` | string(128) | Yes | Reserved; not currently populated. |
| `store_product_key` | text | Yes | Per-store stable identity. URL > image > text fingerprint. |
| `key_source` | string(16) | Yes | CHECK: `url` / `image` / `text`. |
| `name` | text | No | Raw scraped name. |
| `price` / `old_price` / `discount` | decimal / decimal / string | Yes | `discount` is the raw text from the card (e.g. `-40%`). |
| `condition` | text | Yes | e.g. *"1+1"*, *"Perkant 2 ar daugiau"*. |
| `unit_price` / `unit_price_value` / `unit_price_unit` | string / decimal / string(16) | Yes | Raw string + parsed numeric form. |
| `image` / `url` | text | Yes | |
| `valid_dates` / `valid_from` / `valid_to` | text / date / date | Yes | Raw string + parsed boundaries. |
| `loyalty_card` | bool | No | Default false. |
| `scraped_at` | timestamp | Yes | |
| `normalization_status` | string(16) | Yes | Enum: `pending`, `processing`, `done`, `failed`. NULL means "not yet picked up". |
| `normalization_error` | text | Yes | |
| `normalized_at` | timestamp | Yes | |
| `raw_payload` | jsonb | Yes | Reserved; not currently populated. |
| `created_at` / `updated_at` | timestamp | No | |

**`normalization_status` state machine**

```
NULL ──► pending ──► processing ──► done
                          │
                          └──────► failed
                          │
                          └──── (stale recovery, _recover_stale_rows) ──► pending
```

`failed` rows are reset to `pending` only with `--retry-failed` since some failures are deterministic.

### `offer_snapshots`
Append-only price log. One row per `(product_variant_id, store_id, week_starting)`, enforced by `offer_snapshots_pvs_week_unique`.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint | No | PK |
| `raw_offer_id` | foreign | Yes | `nullOnDelete`. |
| `product_variant_id` | foreign | No | Cascade. |
| `store_id` | foreign | No | Cascade. |
| `scrape_batch_id` | foreign | Yes | |
| `week_starting` | date | No | Monday of ISO week. |
| `price` / `old_price` / `discount_percent` | decimal | Yes | |
| `condition` | text | Yes | |
| `loyalty_card` | bool | No | |
| `unit_price_value` / `unit_price_unit` | decimal / string | Yes | |
| `valid_from` / `valid_to` | date | Yes | |
| `scraped_at` | timestamp | No | |
| `is_promotion` | bool | No | Default false. Set true when `old_price` or `discount_percent` or `condition` present. |
| `created_at` | timestamp | No | No `updated_at` — append-only. |

### `current_offers`
Materialised "latest snapshot per `(store, variant)`". Composite PK rather than synthetic id.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `store_id` | foreign | No | Part of PK. Cascade. |
| `product_variant_id` | foreign | No | Part of PK. Cascade. |
| `price` / `old_price` / `discount_percent` | decimal | Yes | |
| `condition` | text | Yes | |
| `is_promotion` / `loyalty_card` | bool | No | |
| `unit_price_value` / `unit_price_unit` | decimal / string | Yes | |
| `valid_from` / `valid_to` | date | Yes | |
| `last_seen_at` | timestamp | No | |
| `week_starting` | date | No | |
| `last_snapshot_id` | foreign | Yes | `offer_snapshots.id`, `nullOnDelete`. |
| `updated_at` | timestamp | No | |

Partial index `current_offers_promotion_idx` on `is_promotion` WHERE `is_promotion = true`.

### `price_changes`
Cross-week price deltas for `/statistika`.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint | No | PK |
| `product_variant_id` | foreign | No | Cascade. |
| `store_id` | foreign | No | Cascade. |
| `previous_price` / `new_price` | decimal | Yes / No | |
| `change_pct` | decimal(6,2) | Yes | Signed percentage. |
| `week_starting` | date | No | |
| `changed_at` | timestamp | No | |
| `snapshot_id` | foreign | Yes | `offer_snapshots.id`, `nullOnDelete`. |

Partial index `price_changes_big_drops_idx` on `changed_at` WHERE `change_pct < -10`.

---

## Users & engagement

### `users`
Laravel default + Fortify 2FA + Socialite Google link.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint | No | |
| `name` / `email` | string | No | |
| `email_verified_at` | timestamp | Yes | |
| `password` | string | No | Hashed by Fortify. |
| `two_factor_secret` / `two_factor_recovery_codes` / `two_factor_confirmed_at` | text/text/timestamp | Yes | Fortify 2FA. |
| `role` | string | No | `UserRole` enum: `user` / `admin`. |
| `google_id` / `google_avatar` | string / text | Yes | Set by `ResolveGoogleUser`. |
| `remember_token` | string | Yes | |
| `created_at` / `updated_at` | timestamp | No | |

### `product_reports`
User flags a product as miscategorised. Unique on `(user_id, normalized_product_id)`.

### `shopping_lists`
Sliding-window record of a completed cart checkout. Used by `/statistika` personal panel.

| Column | Type | Notes |
|---|---|---|
| `user_id` | foreign | Cascade. |
| `item_count` | int | |
| `subtotal` / `original_total` / `savings` | decimal(10,2) | |
| `items` / `store_ids` | json | Snapshot of the cart at submit time. |

### `saved_carts` + `saved_cart_items`
Persistent named carts (different from `shopping_lists` which is a check-out log).

`saved_cart_items` joins `product_families`, `normalized_products`, `product_variants` — all nullable to survive upstream deletions. Snapshot columns (`snapshot_name`, `snapshot_store_slug`, `snapshot_price_cents`) preserve the cart row's display even if the source product disappears.

### `family_subscriptions`
"Notify me when this product becomes cheaper". Unique on `(user_id, family_id, store_id)`.

### `notification_log`
Idempotency record so `SendPriceAlertsJob` doesn't double-send.

| Column | Notes |
|---|---|
| `user_id`, `family_id`, `store_id`, `current_offer_snapshot_id` | Foreign keys; cascade except `store_id` and `current_offer_snapshot_id` which `nullOnDelete`. |
| `trigger_type` | string(16) — `new` / `price_drop` / `reappearance`. |
| `sent_at` | timestamp. |

---

## Generated columns

Both `normalized_products.identity_key` and `product_variants.identity_key` are generated columns:

- **PostgreSQL**: `STORED` generated column from `normalized_name || '|' || COALESCE(brand,'') || ...`
- **SQLite**: `VIRTUAL` generated column with the same expression — SQLite forbids `STORED` via `ALTER TABLE`, but a UNIQUE index on a VIRTUAL column materialises the same guarantee.

The point is **NULL-safe uniqueness**. A plain `UNIQUE (normalized_name, brand, category_id, subcategory_id)` treats NULL values as distinct, so two rows with the same name but `NULL` brand would coexist and the dedup would miss them.

---

## Idempotency invariants

1. **`scrape_batches` UNIQUE on `(store_id, week_starting)`** — re-running mid-week reuses the batch.
2. **`raw_offers` UNIQUE on `(scrape_batch_id, content_hash)`** — same product scraped twice updates the existing row.
3. **`offer_snapshots` UNIQUE on `(product_variant_id, store_id, week_starting)`** — re-normalizing is a no-op.
4. **`current_offers` PK is `(store_id, product_variant_id)`** — guaranteed singleton.
5. **`family_subscriptions` UNIQUE on `(user_id, family_id, store_id)`** — `firstOrCreate` enforces it.
6. **`normalized_products.identity_key`** + **`product_variants.identity_key`** UNIQUE — every upsert is `ON CONFLICT DO UPDATE`.

---

## Indexes worth knowing

| Table | Index | Used by |
|---|---|---|
| `raw_offers` | `(store_id, store_product_key)` | Normalizer dedup shortcut (preferred). |
| `raw_offers` | `content_hash` | Normalizer dedup fallback. |
| `raw_offers` | `normalization_status` | Candidate selection loop. |
| `offer_snapshots` | `(product_variant_id, scraped_at)` | Price history charts. |
| `offer_snapshots` | `(store_id, scraped_at)` | Per-store views. |
| `offer_snapshots` | `(week_starting, is_promotion)` partial | Promo dashboards. |
| `current_offers` | `(product_variant_id, price)` | Cross-store cheapest lookup. |
| `current_offers` | `is_promotion` partial | Active promo listings. |
| `price_changes` | `(change_pct < -10)` partial | Big-drop stats. |

---

## Running migrations

```bash
# Fresh DB
php artisan migrate:fresh --seed

# Incremental
php artisan migrate

# Check what would run
php artisan migrate:status
```

To regenerate this document from the live schema:

```bash
php artisan schema:dump --database=pgsql
```

---

## Eloquent models

All models live under `app/Models/`. The model class structure mirrors the schema:

| Model | Table | Notable relations |
|---|---|---|
| `Store` | `stores` | `hasMany(ScrapeBatch, OfferSnapshot, CurrentOffer, RawOffer)` |
| `Category` | `categories` | — |
| `Subcategory` | `subcategories` | `belongsTo(Category)` |
| `ProductFamily` | `product_families` | `belongsTo(Category, Subcategory)`, `hasMany(NormalizedProduct)` |
| `NormalizedProduct` | `normalized_products` | `belongsTo(Category, Subcategory, ProductFamily)`, `hasMany(ProductVariant, ProductReport)`, `hasManyThrough(OfferSnapshot, CurrentOffer)` |
| `ProductVariant` | `product_variants` | `belongsTo(NormalizedProduct)`, `hasMany(OfferSnapshot, CurrentOffer, PriceChange)` |
| `ScrapeBatch` | `scrape_batches` | `belongsTo(Store)`, `hasMany(RawOffer)` |
| `RawOffer` | `raw_offers` | `belongsTo(Store, ScrapeBatch)` |
| `OfferSnapshot` | `offer_snapshots` | `belongsTo(ProductVariant, Store, RawOffer, ScrapeBatch)`, `hasOneThrough(NormalizedProduct)` |
| `CurrentOffer` | `current_offers` | `belongsTo(Store, ProductVariant)` |
| `PriceChange` | `price_changes` | — |
| `User` | `users` | `hasMany(ProductReport, SavedCart, FamilySubscription)` |
| `ProductReport` | `product_reports` | `belongsTo(User, NormalizedProduct)` |
| `ShoppingList` | `shopping_lists` | `belongsTo(User)` |
| `SavedCart` | `saved_carts` | `belongsTo(User)`, `hasMany(SavedCartItem)` |
| `SavedCartItem` | `saved_cart_items` | `belongsTo(SavedCart, ProductFamily, NormalizedProduct, ProductVariant)` |
| `FamilySubscription` | `family_subscriptions` | `belongsTo(User, ProductFamily, Store)` |
| `NotificationLog` | `notification_log` | — |
