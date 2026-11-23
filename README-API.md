# Rosera Bloom API

GraphQL API for the Rosera Bloom storefront and its admin panel.

- **Framework:** Laravel 12
- **Schema:** Lighthouse 6 driven by PHP attributes (`nodesol/laraql`)
- **Auth:** Laravel Sanctum personal access tokens (`Authorization: Bearer …`)
- **Database:** SQLite by default, MySQL/Postgres supported

---

## Getting started

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve          # http://127.0.0.1:8000/graphql
```

### Seeded accounts

| Role     | Email                        | Password   |
| -------- | ---------------------------- | ---------- |
| Admin    | `admin@roserabloom.test`     | `password` |
| Customer | `customer@roserabloom.test`  | `password` |

Seeding also creates 8 catalog products across 3 brands and 4 categories, so
the storefront is never empty on a fresh install.

---

## Authorization model

Two layers work together:

1. **Authentication** — `@guard` on a field requires a valid Sanctum token.
2. **Authorization** — `@adminOnly` requires the user to be an administrator.

The `@adminOnly` directive (`app/GraphQL/Directives/AdminOnlyDirective.php`) is a
custom Lighthouse directive that is valid on both `FIELD_DEFINITION` and
`OBJECT`. LaraQL emits a separate `extend type Query/Mutation` block for every
generated operation, so a directive declared on one operation only applies to
that operation once the type manipulators copy it down.

> **Important:** directives must be written **inside the `query` string** of a
> `#[Query]` / `#[Mutation]` / `#[Model]` operation (`query: '@guard @find'`),
> **not** in the `directives: [...]` argument. LaraQL places the latter on the
> OBJECT location, and Lighthouse hoists OBJECT directives onto *every* field of
> the merged root type — which would accidentally protect or expose unrelated
> fields.

Policies live in `app/Policies` and are registered in `AppServiceProvider`.
Gates for coarse checks: `admin`, `manage-catalog`, `manage-orders`,
`manage-users`.

---

## Access matrix

| Operation                         | Guest | User | Admin |
| --------------------------------- | ----- | ---- | ----- |
| `products`, `product`             | ✅    | ✅   | ✅    |
| `categories`, `brands`            | ✅    | ✅   | ✅    |
| `BrowseProducts`                  | ✅    | ✅   | ✅    |
| `ProductReviews` (approved only)  | ✅    | ✅   | ✅    |
| `ShopSettings` (shipping fee)     | ✅    | ✅   | ✅    |
| `SubmitContactMessage`            | ✅    | ✅   | ✅    |
| `createProduct/updateProduct/deleteProduct` | ❌ | ❌ | ✅ |
| `Register`, `Login`, `ForgotPassword`, `ConfirmForgotPassword`, `VerifyEmail` | ✅ | ✅ | ✅ |
| `me`, `MyCart`, `MyOrders`        | ❌    | ✅   | ✅    |
| `SubmitReview`, `DeleteReview`    | ❌    | ✅   | ✅    |
| `AddToCart`, `UpdateCartItem`, `RemoveCartItem`, `MergeGuestCart`, `PlaceOrder` | ❌ | ✅ | ✅ |
| `UpdateProfile`, `ResetPassword`, `ResendVerificationEmail` | ❌ | ✅ | ✅ |
| `SendEmailVerificationCode`, `VerifyEmailCode`, `EmailVerificationStatus` | ❌ | ✅ | ✅ |
| `users`, `carts`, `cart_items` (listings) | ❌ | ❌ | ✅ |
| `orders` (raw collection)         | ❌    | ❌   | ✅ (needs `order.view-all`) |
| `ManageOrders`                    | ❌    | ❌   | ✅ (scoped per role) |
| `AdminReviews`, `UpdateReview`    | ❌    | ❌   | ✅    |
| `UpdateShopSettings`              | ❌    | ❌   | ✅    |
| `AdminDashboard`, `UpdateOrderStatus`, `ToggleAdminRole`, `CreateAdminUser` | ❌ | ❌ | ✅ |
| `contact_messages` (inbox)        | ❌    | ❌   | ✅    |

A **seller** is staff, not an administrator: they pass the panel gate, but every
product and order query is narrowed down to their own rows (see
[Seller role](#seller-role)).

---

## Product reviews

Reviews are a storefront feature with moderation built in.

- `SubmitReview(product_id, rating, title, body)` — requires a signed in customer
  who has **actually ordered** the product. Rejected with
  *"You can only review products you have ordered."* otherwise.
- One review per customer per product: submitting again **updates** the existing
  review instead of creating a duplicate (enforced by a unique index on
  `product_id` + `user_id`).
- Every new or edited review is stored with `is_approved = false` and only
  appears on the storefront once an administrator approves it.
- `ProductReviews` is public but filters to approved reviews only.
- `AdminReviews(is_approved:)` returns everything, for moderation.
- `UpdateReview` lets an admin edit the rating, text or approval flag.
- `DeleteReview` lets a customer delete their own review; admins can delete any.

`Product.rating_average` and `Product.rating_count` are denormalised aggregates
kept in sync by `App\Observers\ReviewObserver`, so product listings never have to
aggregate the reviews table.

---

## Shipping fee

The delivery fee is stored in the `settings` table and managed from the admin
panel (`UpdateShopSettings`), never from config files.

| Key                       | Default | Meaning                                        |
| ------------------------- | ------- | ---------------------------------------------- |
| `shipping_fee`            | `9.90`  | Charged on every order below the threshold     |
| `free_shipping_threshold` | `150`   | Orders at or above this ship free; `0` disables |
| `shop_name`               | …       | Shown in emails and the admin panel             |
| `support_email` / `support_phone` | … | Contact details                          |

`App\Services\SettingService` reads the values through the cache and exposes
`shippingFor($subtotal)`, which applies the free shipping rule.

Every order snapshots `subtotal`, `shipping_amount` and `total_amount`, so
changing the fee later never rewrites historical orders:

```
subtotal        45.50
shipping_amount  9.90   <- from settings
total_amount    55.40
```

`ShopSettings` is public read-only so the cart shows the same fee the server
will charge.



---

## Image storage (Oracle Cloud Object Storage, S3 compatible)

Where product images live is configured **only through `.env`**. No code changes
are needed to move between the local disk and the bucket.

### Oracle Cloud Object Storage (production)

Uploads go to Oracle's **S3-compatible** endpoint, but browsers download from the
**native** object storage URL — the two are different hosts, which is why both
are configured:

```dotenv
MEDIA_DISK=s3
MEDIA_URL=                      # empty = derive the native OCI URL
MEDIA_FOLDER=products
MEDIA_MAX_KB=5120
MEDIA_PUBLIC_ACL=false          # Oracle ignores/rejects canned ACLs

# S3-compatible endpoint (for uploads)
AWS_ACCESS_KEY_ID=<customer secret key id>
AWS_SECRET_ACCESS_KEY=<customer secret>
AWS_DEFAULT_REGION=us-sanjose-1
AWS_BUCKET=bucket-20261123-0216
AWS_ENDPOINT=https://<namespace>.compat.objectstorage.<region>.oraclecloud.com
AWS_USE_PATH_STYLE_ENDPOINT=true

# Native public URL (for downloads)
OCI_NAMESPACE=<object storage namespace>
OCI_REGION=us-sanjose-1
OCI_PUBLIC_URL=https://objectstorage.<region>.oraclecloud.com/n/<namespace>/b/<bucket>/o
```

An image stored as `products/x.jpg` then resolves to
`https://objectstorage.us-sanjose-1.oraclecloud.com/n/<namespace>/b/<bucket>/o/products/x.jpg`.

Because Oracle Cloud's S3 compatibility layer ignores (and on some tenancies
rejects) the `x-amz-acl` header, uploads are written **without** an ACL and the
object is made public by a **bucket policy**. The simplest policy is a
pre-authenticated or public bucket read:

```text
Allow group <group> to read object in tenancy
```

plus, for anonymous downloads, a bucket policy with
`Allow any-user to read object in tenancy where request.principal.type = 'anonymous'`.

Set `MEDIA_PUBLIC_ACL=true` if you move to a plain AWS S3 / MinIO bucket that
expects `ACL: public-read`.

### Local disk (development)

```dotenv
MEDIA_DISK=public
MEDIA_URL=
MEDIA_FOLDER=products
MEDIA_MAX_KB=5120
```

Run `php artisan storage:link` once. URLs resolve to `APP_URL/storage/<path>`.

### Any other S3-compatible store (AWS, Spaces, MinIO)

```dotenv
MEDIA_DISK=s3
MEDIA_URL=                      # empty = derive from the bucket
MEDIA_PUBLIC_ACL=true
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=ap-south-1
AWS_BUCKET=rosera-media
```

That produces `https://rosera-media.s3.ap-south-1.amazonaws.com/products/x.jpg`.
A custom endpoint works too:

```dotenv
AWS_ENDPOINT=https://sgp1.digitaloceanspaces.com
AWS_USE_PATH_STYLE_ENDPOINT=true
```

To serve through a CDN or custom domain, set the base URL explicitly:

```dotenv
MEDIA_URL=https://cdn.roserabloom.pk
```

### How it works

`App\Services\MediaService` is the single source of truth. It resolves:

| Concern | Source |
| --- | --- |
| which disk to write to | `config('media.disk')` → `MEDIA_DISK` |
| the public base URL | `MEDIA_URL` → disk `url` → `OCI_PUBLIC_URL` → `OCI_NAMESPACE` + `OCI_REGION` → S3 endpoint → `APP_URL/storage` |
| whether to send `ACL: public-read` | `MEDIA_PUBLIC_ACL` |
| upload folder | `MEDIA_FOLDER` |
| size and MIME limits | `MEDIA_MAX_KB`, `config('media.allowed_mimes')` |

Everything goes through it: the upload endpoint, the `VariantImage.url`
accessor, the delete hook, and the GraphQL settings payload. Because only the
**path** is stored in the database, switching disks re-points every existing
image instantly — nothing is migrated.

### Front end follows automatically

`ShopSettings.media` publishes the resolved config:

```graphql
{ ShopSettings { media { disk driver base_url folder max_kb } } }
```

The SPA calls `configureMedia()` with that response at boot, so `imageUrl()`
builds identical URLs. Switching the backend to Oracle Cloud or a CDN needs
**no front-end rebuild**.

---

## Transactional email is queued

Every transactional email (welcome, verification code, password reset, order
confirmation, seller notice, contact form) is pushed onto the queue instead of
being sent inside the request:

* the API responds immediately — SMTP latency or an unreachable mail host can no
  longer slow down sign-up, checkout or the contact form
* delivery happens in `App\Jobs\SendTransactionalEmail`, with **3 attempts** and
  a 10 second backoff before the job is moved to `failed_jobs`
* `Mail::` is never called from a resolver; `App\Services\MailService` and
  `VerificationCodeService` only dispatch the job

Run a worker in production:

```bash
QUEUE_CONNECTION=database          # already the default

php artisan queue:work --tries=3 --backoff=10
# or, to survive deploys, use a supervisor / systemd unit
```

Without a running worker nothing is delivered, so the queue connection
(`QUEUE_CONNECTION`) is the only email-specific setting you must configure.

---

## Pakistan marketplace model

### Currency

All money is **PKR**, stored as `decimal(12,2)` so large amounts stay exact.
The storefront formats with no decimals (`Rs 6,490`) unless a value genuinely
has paisa. `ShopSettings.currency_code` / `currency_symbol` drive the label.

### Two product types

`products.type` is `clothing` or `perfume`, and it decides which detail fields
and variant options are used:

| | Clothing | Perfume |
| --- | --- | --- |
| Detail fields | `fabric`, `fit_details` | (`fit_details` carries the longevity copy) |
| Variant axis | colour **x** size | volume (`30 ml`) |
| Variant identifies itself by | `color_id` + `size_id` | `volume_id` |

Categories also carry a `type`, so the storefront can show the two catalogues
separately (`CategoryList(type: "clothing" | "perfume")`).

### The product has no price and no image

That is the core rule of the catalogue, and it is enforced by the schema: the
`products` table has no `price`, `photo` or `inventory` column, and its GraphQL
type has no such field. A product only describes *what the item is*; everything
a customer can buy lives on a variant:

| | `products` | `product_variants` |
| --- | --- | --- |
| Holds | `type`, `name`, `description`, `fabric`, `fit_details`, `is_active`, `is_featured` | `price`, `discount_price` (+ window), `stock`, `sku`, `is_default`, `is_active` |
| Images | none | `variant_images` (1..n per variant, exactly one `is_primary`) |

### Two visibility switches, and what they mean

`is_active` and `is_featured` are deliberately separate:

| Field | Off means | On means |
| --- | --- | --- |
| `is_active` | hidden from customers everywhere; the cart rejects it | buyable in the store |
| `is_featured` | only in the shop listing and category filters | also on the home page hero and "Featured" rail |

`is_featured` is merchandising only — it never hides a product. The home page
asks for the merchandised rows and falls back to the newest pieces when nothing
is flagged yet:

```graphql
# home page
{ BrowseProducts(first: 4, order_by: "newest", featured_only: true) { data { ...ProductFields } } }

# shop listing / category filters — everything that is active
{ BrowseProducts(first: 12, category_slug: "formal-wear") { data { ...ProductFields } } }

# admin: flip the flag from the product row or the edit form
mutation { UpdateProduct(id: 7, is_featured: true) { id is_featured is_active } }
```

Because LaraQL generates the schema from the live tables, dropping a column
removes the field — which is why the migration list is the source of truth for
what the API can expose.

Derived, read-only helpers on `Product`: `lowest_price`, `primary_image`,
`total_stock`, `in_stock`, `discount_percent`, `default_variant_id`,
`default_variant`, `colors` (clothing: colour → sizes) and `volumes` (perfume).

### Variants are the unit of stock and price

`product_variants` holds one row per buyable option. Cart lines and order lines
reference `product_variant_id`, and the chosen colour/size (or volume) labels
are snapshotted onto the order so old orders stay readable after an edit.

- Every product needs **at least one** variant; adding to the cart without a
  `product_variant_id` is rejected (`Please choose a colour and size first.` /
  `Please choose a volume first.`).
- **Exactly one** variant is the default. It is the combination the product page
  opens on, and a single-variant product is forced to be its own default. The
  default is always a buyable row: deactivating a variant releases the flag, and
  the first in-stock active variant takes it.
- Stock is decremented on the variant.
- `ProductVariant.final_price` is the single source of money: it is
  `discount_price` while the discount window is open, otherwise `price`. Cart
  totals, order lines and the product page all read it.

The whole tree is written through `CreateProduct` / `UpdateProduct`. LaraQL also
generates generic `createProductVariant` / `updateProductVariant` /
`deleteProductVariant` mutations; they are admin-only but they skip the service,
so the model itself keeps the invariants no matter which path is used:

- saving a variant with `is_default: true` clears the flag on its siblings, so a
  second default is never attempted (the unique index would otherwise 500);
- the last remaining variant of a product cannot be deleted
  (`A product needs at least one variant, so the last one cannot be deleted.`);
- `discount_price < price` is also a database `CHECK` constraint
  (`chk_variant_discount`) on MySQL and Postgres.

### Variant photos

Images live only on variants (`variant_images`: `url`, `sort_order`,
`is_primary`). A variant needs at least one photo, and exactly one is primary —
when the form marks none, the first one is promoted. Files are uploaded through
a small REST endpoint because GraphQL multipart uploads would need an extra
client library:

```
POST /api/uploads        Authorization: Bearer <sanctum token>
multipart: file=@photo.jpg, folder=products

201 { "path": "products/uuid.jpg", "url": ".../storage/products/uuid.jpg" }
```

Only administrators may upload. `php artisan storage:link` must have been run.

A photo row is matched by `id`, the same way variants are:

| Payload row | Effect |
| --- | --- |
| has an `id` | that photo is kept and updated (`url`, `sort_order`, `is_primary`) |
| has no `id` | a new upload is inserted at that position |
| stored photo missing from the payload | deleted, and its file is removed from the disk |

So the admin form sends every photo back with its `id` and only omits the ones
the admin removed; a gallery can be trimmed or reordered without re-uploading
anything, and removing a photo never leaves an orphaned file behind.


### Categories

Categories carry a `type` (clothing or perfume), a unique `slug` used by the
storefront filter, and a `sort_order` for display.

```graphql
# Admin listing with product counts
{ AdminCategories(type: "clothing", search: "lawn") { id name slug type sort_order product_count } }

# Create (no id) or update (with id)
mutation { SaveCategory(name: "Unstitched Lawn", type: "clothing", sort_order: 3) { id slug } }
mutation { SaveCategory(id: 7, name: "Formal Wear", type: "clothing") { id slug } }

mutation { DeleteCategory(id: 7) { message deleted_id } }
```

`SaveCategory` derives the slug from the name and refuses a name that would
collide with an existing slug. `DeleteCategory` refuses a category that still
holds products.


### Brands

A brand is an **optional** label: a product may carry one, several, or none at
all. Deleting a brand never deletes products — they simply become brand-less.

```graphql
# Admin listing with product counts
{ AdminBrands(search: "atelier") { id name slug product_count } }

# Create (no id) or update (with id)
mutation { SaveBrand(name: "Atelier Noir") { id name slug } }
mutation { SaveBrand(id: 3, name: "Atelier Noir Paris") { id name slug } }

mutation { DeleteBrand(id: 3) { message deleted_id } }
```

`SaveBrand` derives a unique slug from the name and rejects a duplicate name
(case-insensitive). `product_count` reports how many products carry the label,
defaulting to `0` rather than `null`.

The brand is attached through the product mutations, using the same optional
`brand_ids` list as the category ids:

```graphql
mutation {
  CreateProduct(
    type: "clothing"
    name: "Linen Kurta"
    description: "A lightweight linen kurta for warm afternoons."
    brand_ids: [3, 5]        # omit, or send [], for a product with no brand
    category_ids: [1]
    variants: [ /* … */ ]
  ) { id brands { name } }
}

# Later: replace the brands (an empty list clears them)
mutation { UpdateProduct(id: 12, brand_ids: []) { id brands { name } } }
```

The storefront's brand filter is `BrowseProducts(brand_id: 3)` (or
`brand_slug: "atelier-noir"`), and `ShopFilters` returns the brands that actually
have products, with their counts.


### Creating and updating a product

One request creates the product together with its whole variant tree, in a single
transaction. Perfume: one row per volume. Clothing: one row per colour × size.

```graphql
mutation {
  CreateProduct(
    type: "perfume"
    name: "Oud Royale"
    description: "Warm, woody oud with amber notes."
    variants: [
      {
        volume_id: 1
        sku: "OUD-6"
        price: 1500
        stock: 40
        is_default: false
        images: [{ url: "oud-6-a.jpg", is_primary: true }]
      }
      {
        volume_id: 3
        sku: "OUD-30"
        price: 6000
        discount_price: 5200
        stock: 15
        is_default: true
        images: [
          { url: "oud-30-a.jpg", is_primary: true }
          { url: "oud-30-b.jpg" }
          { url: "oud-30-c.jpg" }
        ]
      }
    ]
  ) { id default_variant_id }
}
```

```graphql
mutation {
  CreateProduct(
    type: "clothing"
    name: "Classic Linen Shirt"
    description: "Relaxed everyday shirt."
    fabric: "100% linen"
    fit_details: "Regular fit, model is 6'0 wearing M"
    variants: [
      {
        color_id: 2, size_id: 1, sku: "LS-BLK-S"
        price: 3500, discount_price: 2999, stock: 10
        images: [{ url: "ls-blk-s-1.jpg", is_primary: true }]
      }
      {
        color_id: 2, size_id: 2, sku: "LS-BLK-M"
        price: 3500, stock: 12, is_default: true
        images: [
          { url: "ls-blk-m-1.jpg", is_primary: true }
          { url: "ls-blk-m-2.jpg" }
        ]
      }
      {
        color_id: 5, size_id: 2, sku: "LS-WHT-M"
        price: 3500, stock: 8
        images: [{ url: "ls-wht-m-1.jpg", is_primary: true }]
      }
    ]
  ) { id }
}
```

`UpdateProduct` takes the same `variants` array plus `id`. It is a **full
description** of the tree:

| Payload row | Effect |
| --- | --- |
| has an `id` | that row is updated in place (cart lines keep working) |
| has no `id` | the row for that combination is updated if it exists, else inserted |
| stored row missing from payload | deleted — or `is_active = false` when an order already points at it |

If the removed tree no longer contains the current default, the request must
nominate a new one (`You removed the default variant…`). Omitting `variants`
entirely leaves the tree untouched, so a single field can be patched:

```graphql
mutation { UpdateProduct(id: 7, is_active: false) { id } }
```

#### Validation (all in `ProductVariantService`)

| Rule | Message |
| --- | --- |
| at least one variant | `A product needs at least one variant.` |
| perfume needs `volume_id`, no colour/size | `Every perfume variant needs a volume.` |
| clothing needs both `color_id` and `size_id`, no volume | `Every clothing variant needs a colour and a size.` |
| no duplicate combination | `The Midnight / S combination is listed twice.` |
| `price > 0` | `The price of 30 ml must be greater than zero.` |
| `discount_price < price` | `The discount price of 30 ml must be lower than its price.` |
| at least one image | `Add at least one photo to 30 ml.` |
| one primary image | the first one is promoted when none is marked |
| one default | `Only one variant can be the default.` |
| the default must be buyable | an inactive variant never holds the flag; the first in-stock active variant takes it instead |
| unique SKU | `The SKU OUD-30 is already used by another variant.` |

### Variant master lists

Colours, sizes and volumes are shop wide records, so the same names are reused
across products and can be managed in one place.

```graphql
{ VariantMasters {
    colors { id name hex }
    sizes { id name label }
    volumes { id label ml }
} }

# kind = color | size | volume
mutation { SaveVariantMaster(kind: "color", label: "Dusty Rose", hex: "#c48b9f") { id } }
mutation { SaveVariantMaster(kind: "volume", label: "75 ml", ml: 75) { id } }
mutation { deleteColor(id: 7) { id } }
```

A variant references the master records it represents: `volume_id` for perfume,
`color_id` + `size_id` for clothing.

| Field | Purpose |
| --- | --- |
| `ProductVariant.color` / `size` / `volume` | the master record |
| `ProductVariant.images` | that combination's photos (`{url, sort_order, is_primary}`) |
| `ProductVariant.image_urls` | that combination's own photos, gallery order |
| `ProductVariant.primary_image` | its main photo, for cards |
| `ProductVariant.price` / `discount_price` / `final_price` | per combination |
| `ProductVariant.stock` / `sku` / `label` | per combination |
| `Product.colors` | clothing only: colour → sizes (`variant_id` per size) |
| `Product.volumes` | perfume only: the volumes it is sold in |

Validation on save: a duplicate combination, an unknown master id, a missing
photo or a duplicate SKU each produce a clear error instead of a database
exception.

Two further rules:

- **A product needs at least one variant.** Saving without any is rejected with
  *"A product needs at least one variant."*
- **Exactly one variant is the default.** Pass `is_default: true` on any variant
  in the create/update payload, or call `SetDefaultVariant(variant_id:)` later.
  When nobody picks one, the first in-stock variant becomes the default, and a
  single-variant product is always its own default.

```graphql
mutation { SetDefaultVariant(variant_id: 53) { id label is_default } }
```

### Multiple reviews per customer

A customer may review a product more than once (for example after buying it
again in another colour). Passing `review_id` to `SubmitReview` edits one of
their own existing reviews; omitting it adds a new one. Verified-purchase
enforcement and moderation still apply to every review.

### Shipping and payment

Both are configured from the admin panel (`UpdateShopSettings`) and applied
server side on every order:

| Setting | Default | Meaning |
| --- | --- | --- |
| `shipping_fee` | `250` | Charged below the threshold |
| `free_shipping_threshold` | `5000` | Free at or above this; `0` disables |
| `cod_enabled` | on | Offer cash on delivery |
| `cod_fee` | `100` | Cash handling charge, added only for COD |
| `bank_enabled` / `bank_details` | on | Advance bank transfer + account text |
| `wallet_enabled` / `wallet_number` | on | Easypaisa / JazzCash number |

`ShopSettings.payment_methods` returns exactly the methods that are switched on,
with their fee and the details to show the customer. A disabled method is refused
at checkout.

Each order snapshots `subtotal`, `shipping_amount`, `cod_fee` and `total_amount`,
so changing a setting later never rewrites historical orders:

```
subtotal         6,490
shipping_amount      0   (over the free threshold)
cod_fee            100   (cash on delivery)
total_amount     6,590
```

`orders.payment_status` tracks `unpaid` / `paid` / `refunded`.

### Addresses

Checkout is restricted to Pakistani addresses. `ShopSettings.provinces` returns
every province with its cities, and `PlaceOrder` rejects a province or a
city that is not in that list.


---

## Changing the schema (important)

The GraphQL schema is generated from PHP attributes on the models and from the
`@rules` strings on mutations. Lighthouse caches the compiled schema whenever
`APP_ENV` is not `local`, which means **a code change is not picked up until the
cache is cleared**.

```bash
php artisan lighthouse:clear-cache
```

Run that after changing any model, `@rules` string or resolver signature, and on
every deploy.

`LIGHTHOUSE_SCHEMA_CACHE_ENABLE=false` is set in `.env.example` so local edits
always apply immediately. Turn it on in production and clear it on deploy.

Symptom of a stale cache: the API still demands an input you removed, or still
accepts one you deleted — e.g. *"Variable `$inventory` of required type `Int!`
was not provided"* after stock moved onto variants.

## Roles & permissions

Authorization is built on `spatie/laravel-permission`. Access is granted by
**permission**, never by the `is_admin` column alone.

### The catalogue

`PermissionSeeder` creates 39 permissions in the `<resource>.<action>` form:

| Resource | Abilities |
| --- | --- |
| `product`, `brand`, `category`, `role` | `view`, `create`, `update`, `delete` |
| `order` | the four + `update-status`, `view-all` |
| `review` | the four + `approve` |
| `message` | the four + `reply` |
| `user` | the four + `assign-role` |
| `setting` | `view`, `update` (key/value config has no create/delete) |
| `dashboard` | `view` |

### Default roles

`RoleSeeder` creates five roles:

| Role | Access |
| --- | --- |
| `super-admin` | everything, implicitly, via `Gate::before` |
| `manager` | full catalog, orders, reviews, messages; read-only users and settings |
| `catalog-manager` | products, brands, categories only |
| `support` | orders, reviews, messages; read-only products and users |
| `seller` | **only their own** products and the orders containing them |

`super-admin` is locked: it cannot be edited or deleted, because it holds every
permission by definition rather than through rows.

### Demo accounts

Every seeded account uses the password `password`:

| Email | Role |
| --- | --- |
| `admin@roserabloom.test` | super-admin |
| `manager@roserabloom.test` | manager |
| `catalog@roserabloom.test` | catalog-manager |
| `support@roserabloom.test` | support |
| `seller@roserabloom.test` | seller |
| `customer@roserabloom.test` | none (storefront only) |

### Seller role

A seller trades their own catalogue inside the same admin panel. Selling is a
**role**, not the `is_admin` flag, and the scoping happens at two levels:

1. **Permissions** — the `seller` role holds `dashboard.view`, `product.*`,
   `order.view`, `order.update-status` and `review.view`. Nothing else.
2. **Rows** — every product and order query is filtered by
   `products.seller_id`, so two sellers never see each other's data.

| Concern | How it is scoped |
| --- | --- |
| `products.seller_id` | `CreateProduct` stamps the seller's id; administrators leave it `NULL` (shop owned) |
| `BrowseProducts` | `mine: true` narrows to the caller's rows; a seller is always narrowed |
| `UpdateProduct`, `DeleteProduct` | `ProductPolicy` rejects a row a seller does not own |
| `createProductVariant`, `updateProductVariant`, `deleteProductVariant` | model hooks in `Product`/`ProductVariant` throw for foreign rows |
| `ManageOrders` | a seller only receives orders containing their products, and only their own line items |
| `UpdateOrderStatus` | `OrderPolicy::updateStatus` refuses orders without a seller product |
| `AdminDashboard` | a seller's counts and revenue describe their own shop; `customers` is `0` |

The `User` type exposes three hints so the SPA never has to derive them:
`is_staff` (may open the panel), `is_seller` (scopes the panel) and
`is_email_verified`.

```graphql
# Seller: only their own rows, whatever they ask for
query { BrowseProducts(first: 20, mine: true) { data { id name seller_name } } }
query { ManageOrders(first: 20) { data { id items { product_id quantity } } } }
```

### Email verification

Two channels exist, and both are needed:

| Channel | Mutation | Use |
| --- | --- | --- |
| 6 digit code | `SendEmailVerificationCode` / `VerifyEmailCode` | the SPA flow: the customer types the code on the verification page |
| signed link | the link inside the email | opens the same page and is exchanged automatically |

A brand new account is signed in but **unverified**, and the SPA blocks every
private page until `is_email_verified` becomes true.

```graphql
query { EmailVerificationStatus { email is_verified code_ttl_minutes seconds_until_resend } }

mutation { SendEmailVerificationCode { message email expires_in_minutes sent } }

mutation {
  VerifyEmailCode(code: "123456") {
    message
    verified
    user { id email is_email_verified }
  }
}
```

The rules live in `App\Services\VerificationCodeService`:

- the code is six digits, **hashed at rest** (`users.verification_code`) and
  valid for `CODE_TTL_MINUTES` (15)
- a code is burned the moment it is used, and after `MAX_ATTEMPTS` (5) wrong
  guesses
- resending is rate limited to one code every `RESEND_COOLDOWN_SECONDS` (60)
- the three code columns are in `User::$hidden`, so LaraQL keeps them out of the
  GraphQL schema entirely

### Order emails

`PlaceOrder` sends two kinds of mail:

| Recipient | Mailable | Content |
| --- | --- | --- |
| the customer | `OrderConfirmationMail` | the full receipt |
| each seller | `SellerOrderPlacedMail` | only **their** lines, their subtotal and the shipping address |

A status change sends `OrderStatusChangedMail` to the customer and
`SellerOrderStatusMail` to every seller in the order. Mail failures are logged
and never break checkout.

Set the credentials in `.env`:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.your-provider.com
MAIL_PORT=587
MAIL_USERNAME=your-smtp-user
MAIL_PASSWORD=your-smtp-password
MAIL_FROM_ADDRESS="hello@your-domain.com"
MAIL_FROM_NAME="${APP_NAME}"
FRONTEND_URL=http://localhost:9100   # email links point here
```

### Where enforcement happens

1. **Policies** — `PermissionPolicy` maps `view`/`create`/`update`/`delete` onto
   `<resource>.<ability>` and lets subclasses add extras (`approve`,
   `update-status`, `assign-role`, `reply`).
2. **GraphQL directives** — `@permission(require: ["product.update"])` on a field.
   Without `require` it means "any staff member".
3. **Resolvers** — coarse gates: `manage-catalog`, `manage-orders`,
   `manage-users`, `manage-settings`.
4. **Uploads** — `POST /api/uploads` requires `product.create`.

Permissions are seeded for both the `web` and `sanctum` guards, because the SPA
authenticates with a Sanctum token while Laravel's own checks default to `web`.

### Managing roles over the API

```graphql
{ RoleList { id name users_count is_locked permissions } }
{ PermissionList { resource permissions { name action } } }

mutation { CreateRole(name: "order-clerk", permissions: ["order.view", "order.update-status"]) { id } }
mutation { UpdateRole(id: 10, permissions: ["order.view"]) { id } }
mutation { AssignUserRole(user_id: 5, roles: ["moderator"]) { id is_admin permission_list } }
mutation { DeleteRole(id: 10) { message } }
```

`AssignUserRole` sets `is_admin` only when the `super-admin` role is among the
assigned roles, so a limited role never accidentally becomes an administrator.

### What the frontend receives

`me`, `Login` and `Register` return the effective access alongside the user:

```graphql
me { role_list { name } permission_list }
```

`permission_list` is the flattened set of everything the user can do (for a
super-admin, the whole catalogue), which lets the SPA gate UI in one round trip
without guessing.

---

## Emails

All transactional mail goes through `App\Services\MailService`, which never
lets an SMTP failure break an API call (it logs a warning instead).

| Mailable                     | Trigger                              |
| ---------------------------- | ------------------------------------ |
| `WelcomeMail`                | registration, admin creation         |
| `EmailVerificationMail`      | registration, `ResendVerificationEmail` |
| `PasswordResetMail`          | `ForgotPassword`                     |
| `OrderConfirmationMail`      | `PlaceOrder`                         |
| `OrderStatusChangedMail`     | `UpdateOrderStatus`                  |
| `ContactMessageMail`         | `SubmitContactMessage` (to `ADMIN_EMAIL`) |

Templates live in `resources/views/emails` and share a branded layout.
Email links point to `FRONTEND_URL`, so the SPA handles verification and reset
screens.

With the default `MAIL_MAILER=log` every message is written to
`storage/logs/laravel.log`, which is handy for local testing.

---

## Tests

```bash
php artisan test
# or
./vendor/bin/phpunit
```

42 feature tests cover registration, login (including throttling), logout token
revocation, password reset, email verification, cart merging, checkout totals,
stock reduction, order status transitions, and every authorization boundary.

The review and shipping additions are covered by `ReviewTest` and
`ShippingSettingsTest`: verified-purchase enforcement, one-review-per-product,
moderation visibility, rating aggregates, the shipping fee on every order, the
free shipping threshold, and admin-only settings access. **59 tests, 140
assertions in total.**
