# Discount Tracker Database Schema

This document describes the database schema for the Discount Tracker application.

## Overview

The database consists of 6 core tables that support the scraping, normalization, and price tracking workflow:

1. **stores** - Retail chains (e.g., Maxima, Lidl, Rimi)
2. **scrape_batches** - Individual scraper runs
3. **raw_offers** - Raw scraped product offers
4. **normalized_products** - Normalized product identities
5. **product_variants** - Product variants (sizes, packages)
6. **offer_snapshots** - Price history snapshots

## Data Flow

```
stores
  -> scrape_batches
  -> raw_offers
  -> normalized_products
  -> product_variants
  -> offer_snapshots
```

## Tables

### 1. stores

Stores retail chain information.

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| id | bigint | No | Primary key |
| name | string | No | Store name (unique) |
| slug | string | No | URL-friendly identifier (unique) |
| website_url | text | Yes | Store website URL |
| is_active | boolean | No | Whether store is active (default: true) |
| created_at | timestamp | No | Creation timestamp |
| updated_at | timestamp | No | Update timestamp |

**Example stores:** lidl, maxima, rimi, iki, norfa

### 2. scrape_batches

Tracks each scraper run.

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| id | bigint | No | Primary key |
| store_id | foreign id | Yes | Foreign key to stores (null on delete) |
| source_name | text | No | Name of the scraper source |
| notes | text | Yes | Additional notes about the batch |
| started_at | timestamp | No | When scrape started (default: current timestamp) |
| finished_at | timestamp | Yes | When scrape finished |
| status | enum | No | Status: running, completed, failed (default: running) |
| offers_found | integer | No | Number of offers found (default: 0) |
| offers_inserted | integer | No | Number of offers inserted (default: 0) |
| offers_updated | integer | No | Number of offers updated (default: 0) |
| offers_failed | integer | No | Number of offers failed (default: 0) |
| error_message | text | Yes | Error message if failed |
| created_at | timestamp | No | Creation timestamp |
| updated_at | timestamp | No | Update timestamp |

**Indexes:** store_id, source_name, status, started_at

### 3. raw_offers

Stores raw scraped product offers before normalization.

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| id | bigint | No | Primary key |
| store_id | foreign id | No | Foreign key to stores (cascade on delete) |
| scrape_batch_id | foreign id | Yes | Foreign key to scrape_batches (null on delete) |
| external_id | string(64) | Yes | Store's product identifier |
| name | text | No | Product name |
| price | decimal(10,2) | Yes | Current price |
| old_price | decimal(10,2) | Yes | Original price |
| discount | string | Yes | Discount percentage or text |
| condition | text | Yes | Product condition |
| unit_price | string | Yes | Unit price string |
| image | text | Yes | Image URL |
| url | text | Yes | Product URL |
| valid_dates | text | Yes | Validity dates as text |
| valid_from | date | Yes | Validity start date |
| valid_to | date | Yes | Validity end date |
| loyalty_card | boolean | No | Requires loyalty card (default: false) |
| scraped_at | timestamp | Yes | When offer was scraped |
| normalization_status | string | Yes | Status: pending, processing, done, failed |
| normalized_product_id | foreign id | Yes | Foreign key to normalized_products (null on delete) |
| product_variant_id | foreign id | Yes | Foreign key to product_variants (null on delete) |
| normalized_at | timestamp | Yes | When normalization completed |
| normalization_error | text | Yes | Normalization error message |
| raw_payload | jsonb | Yes | Original raw data from scraper |
| created_at | timestamp | No | Creation timestamp |
| updated_at | timestamp | No | Update timestamp |

**Indexes:** store_id, scrape_batch_id, normalization_status, normalized_product_id, product_variant_id, scraped_at, external_id, name

### 4. normalized_products

Stores logical product identities (e.g., "Pienas", "Varškė", "Bananai").

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| id | bigint | No | Primary key |
| canonical_name | text | No | Canonical product name |
| normalized_name | text | No | Normalized name for search/deduplication |
| brand | string | Yes | Product brand |
| generic_name | string | Yes | Generic product name |
| category | string | Yes | Product category |
| subcategory | string | Yes | Product subcategory |
| ai_confidence | decimal(5,4) | Yes | AI confidence score |
| needs_review | boolean | No | Whether product needs review (default: false) |
| normalization_version | string | Yes | Normalization version |
| source_name | text | Yes | Source of normalization |
| image_url | text | Yes | Product image URL |
| created_at | timestamp | No | Creation timestamp |
| updated_at | timestamp | No | Update timestamp |

**Indexes:** normalized_name, canonical_name, brand, needs_review

### 5. product_variants

Stores concrete product variants (e.g., "500 g", "1 l", "2 pcs").

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| id | bigint | No | Primary key |
| normalized_product_id | foreign id | No | Foreign key to normalized_products (cascade on delete) |
| variant_name | text | Yes | Variant name/description |
| size_value | decimal(10,3) | Yes | Size value |
| size_unit | string | Yes | Size unit (e.g., g, ml, l, kg) |
| package_count | decimal(10,3) | Yes | Package count |
| package_unit | string | Yes | Package unit (e.g., pcs, units) |
| net_quantity_value | decimal(10,3) | Yes | Net quantity value |
| net_quantity_unit | string | Yes | Net quantity unit |
| attributes | jsonb | Yes | Additional attributes |
| created_at | timestamp | No | Creation timestamp |
| updated_at | timestamp | No | Update timestamp |

**Indexes:** normalized_product_id, size_value, size_unit, package_count, package_unit

### 6. offer_snapshots

The most important table for price history tracking. Each row represents one observed price for one product variant in one store at one scrape time.

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| id | bigint | No | Primary key |
| raw_offer_id | foreign id | Yes | Foreign key to raw_offers (null on delete) |
| product_variant_id | foreign id | No | Foreign key to product_variants (cascade on delete) |
| store_id | foreign id | No | Foreign key to stores (cascade on delete) |
| scrape_batch_id | foreign id | Yes | Foreign key to scrape_batches (null on delete) |
| price | decimal(10,2) | No | Current price |
| old_price | decimal(10,2) | Yes | Original price |
| discount_percent | decimal(5,2) | Yes | Discount percentage |
| loyalty_card | boolean | No | Requires loyalty card (default: false) |
| unit_price_value | decimal(10,3) | Yes | Unit price value |
| unit_price_unit | string | Yes | Unit price unit |
| valid_from | date | Yes | Offer valid from |
| valid_to | date | Yes | Offer valid to |
| scraped_at | timestamp | No | When snapshot was created |
| is_promotion | boolean | No | Whether this is a promotion (default: false) |
| created_at | timestamp | No | Creation timestamp |
| updated_at | timestamp | Yes | Update timestamp |

**Indexes:** product_variant_id, store_id, scrape_batch_id, raw_offer_id, scraped_at, valid_from, valid_to, price, is_promotion

## Running Migrations

### Fresh Installation

To create all tables from scratch:

```bash
php artisan migrate:fresh
```

### Incremental Updates

To run pending migrations:

```bash
php artisan migrate
```

### Verify Schema

To verify all required tables and columns exist:

```bash
php artisan db:verify-schema
```

## SQL Queries for Manual Verification

### List All Tables

```sql
SELECT table_name
FROM information_schema.tables
WHERE table_schema = 'public'
ORDER BY table_name;
```

### List All Columns for Core Tables

```sql
SELECT table_name, column_name, data_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = 'public'
  AND table_name IN (
      'stores',
      'scrape_batches',
      'raw_offers',
      'normalized_products',
      'product_variants',
      'offer_snapshots'
  )
ORDER BY table_name, ordinal_position;
```

## Python Scraper Integration

The Python scraper should:

1. **Create a scrape batch:**
   ```python
   INSERT INTO scrape_batches (store_id, source_name, notes, started_at, status)
   VALUES (1, 'maxima_scraper', 'Weekly scrape', NOW(), 'running')
   RETURNING id;
   ```

2. **Insert raw offers:**
   ```python
   INSERT INTO raw_offers (
       store_id, scrape_batch_id, external_id, name, price, old_price,
       discount, unit_price, image, url, loyalty_card, scraped_at
   ) VALUES (...);
   ```

3. **Update batch when complete:**
   ```python
   UPDATE scrape_batches
   SET status = 'completed',
       finished_at = NOW(),
       offers_inserted = 100
   WHERE id = ?;
   ```

## Normalization Flow

The normalization script should:

1. Read `raw_offers` with `normalization_status = 'pending'`
2. Create or find `normalized_products` by matching normalized names
3. Create or find `product_variants` by matching size/package info
4. Link `raw_offers` to `normalized_product_id` and `product_variant_id`
5. Create `offer_snapshots` for price history
6. Update `normalization_status` to 'done'

## Price History Queries

### Get price history for a product variant:

```sql
SELECT os.*, s.name as store_name
FROM offer_snapshots os
JOIN stores s ON s.id = os.store_id
WHERE os.product_variant_id = ?
ORDER BY os.scraped_at DESC;
```

### Compare prices across stores:

```sql
SELECT s.name, os.price, os.old_price, os.scraped_at
FROM offer_snapshots os
JOIN stores s ON s.id = os.store_id
WHERE os.product_variant_id = ?
  AND os.id IN (
      SELECT MAX(id)
      FROM offer_snapshots
      WHERE product_variant_id = ?
      GROUP BY store_id
  )
ORDER BY os.price ASC;
```

### Find current promotions:

```sql
SELECT np.canonical_name, pv.variant_name, s.name, os.price, os.old_price
FROM offer_snapshots os
JOIN product_variants pv ON pv.id = os.product_variant_id
JOIN normalized_products np ON np.id = pv.normalized_product_id
JOIN stores s ON s.id = os.store_id
WHERE os.is_promotion = true
  AND os.valid_to >= CURRENT_DATE
ORDER BY os.discount_percent DESC;
```

## Models

All Eloquent models are located in `app/Models/`:

- `Store.php` - Store model
- `ScrapeBatch.php` - Scrape batch model
- `RawOffer.php` - Raw offer model
- `NormalizedProduct.php` - Normalized product model
- `ProductVariant.php` - Product variant model
- `OfferSnapshot.php` - Offer snapshot model

## Relationships

### Store
- `hasMany(ScrapeBatch)`
- `hasMany(RawOffer)`
- `hasMany(OfferSnapshot)`

### ScrapeBatch
- `belongsTo(Store)`
- `hasMany(RawOffer)`
- `hasMany(OfferSnapshot)`

### RawOffer
- `belongsTo(Store)`
- `belongsTo(ScrapeBatch)`
- `belongsTo(NormalizedProduct)`
- `belongsTo(ProductVariant)`
- `hasMany(OfferSnapshot)`

### NormalizedProduct
- `hasMany(ProductVariant)`
- `hasMany(RawOffer)`
- `hasManyThrough(OfferSnapshot, ProductVariant)`

### ProductVariant
- `belongsTo(NormalizedProduct)`
- `hasMany(RawOffer)`
- `hasMany(OfferSnapshot)`

### OfferSnapshot
- `belongsTo(RawOffer)`
- `belongsTo(ProductVariant)`
- `belongsTo(Store)`
- `belongsTo(ScrapeBatch)`
- `hasOneThrough(NormalizedProduct, ProductVariant)`
