# CloudFront CDN for catalogue images — plan (2026-09-24)

## Why

Every catalogue image (product gallery, product-description inline images, category tiles,
category banners, home banners) is served as a **1-day S3 signed URL**. That is correct for
privacy but bad for the public shop:

- Google Images indexes URLs that 403 a day later; `Product.image` / `og:image` can never be stable.
- Browsers and CDNs cannot cache beyond a day.
- **Live bug:** `AdminProductController::trixUpload()` returns a signed URL and the editor
  **saves it into `products.description_html`**, which `shop/product.blade.php:280` renders raw.
  Every inline description image breaks 24 h after upload — on staging today.

Decision (user, 2026-09-24): **Option A — CloudFront in front of the private bucket.** Stable
`https://cdn.arovolife.com/<key>` URLs for catalogue prefixes only; the bucket stays private with
Block Public Access ON; KYC and every other private prefix stays unreachable.
Cost: effectively ₹0 (S3 2 GB ≈ $0.05/mo; CloudFront always-free tier 1 TB + 10M requests/mo).

## Scope

Public (CDN) prefixes — all keys are `<prefix>/<uuid>.<ext>`, immutable, never overwritten:

| Prefix | Written by | Read by |
|---|---|---|
| `products/gallery/`, `products/inline/` | `ProductImageStorage::store()` | `ProductImage::url()` (`Models/ProductImage.php:41`) |
| `categories/` | `putRaw(…, 'categories')` in `AdminCategoryController` | `ProductCategory.php:69` |
| `category-banners/` | `putRaw(…, 'category-banners')` | `ProductCategory.php:84` |
| `banners/` | `putRaw(…, 'banners')` in `AdminBannerController` | `Banner.php:64` |

Stays signed and never on the CDN: `user_<id>/id-photo/…` (profile/ID photos), `kyc/`,
`grievance/`, `distributor-requests/`, `offline-payments/`, `payout-bank-files/`,
`adc-applications/`. Enforced in **three** layers: bucket policy (prefix-scoped), CloudFront
function (prefix allow-list), and the PHP helper (allow-list, falls back to signed).

Out of scope (follow-up, listed at the end): `og:image` / JSON-LD / sitemap for product pages.

## Part 1 — AWS + DNS (user in console; I do GoDaddy + verification)

Blocked until the AWS account verification finishes (CloudShell already refused on 2026-09-24).

1. **ACM certificate — region us-east-1** (CloudFront only accepts us-east-1 certs):
   request public cert for `cdn.arovolife.com`, DNS validation. I add the validation CNAME in
   GoDaddy; wait for *Issued*.
2. **CloudFront Function** `arovolife-catalog-only` (viewer-request, JS 2.0):
   ```js
   function handler(event) {
     var uri = event.request.uri;
     // Must match CatalogImageUrl::KEY_PATTERN exactly.
     if (/^\/(products\/(gallery|inline)|categories|category-banners|banners)\/[A-Za-z0-9-]+\.(jpe?g|png|webp)$/.test(uri)) {
       return event.request;
     }
     return { statusCode: 404, statusDescription: 'Not Found' };
   }
   ```
3. **Distribution**
   - Origin: `arovolife-prod.s3.ap-south-1.amazonaws.com` (REST endpoint, not website), **Origin
     Access Control** (sign requests), *do not* let the wizard write the bucket policy (step 4 does it scoped).
   - Default behaviour: GET/HEAD only, Redirect HTTP→HTTPS, cache policy **CachingOptimized**,
     function above on viewer-request, compression on.
   - Response headers policy (custom): `Cache-Control: public, max-age=31536000, immutable`
     (override origin) + `X-Content-Type-Options: nosniff`. Keys are UUIDs, so immutable is safe.
   - Alternate domain `cdn.arovolife.com`, the ACM cert, TLSv1.2_2021, HTTP/2+3.
   - Price class: **All edge locations** (India edges are in Price Class 200+; the free tier
     covers it).
   - **Pricing: Pay-as-you-go** (user decision 2026-09-24) — always-free 1 TB data out + 10M
     requests/month. The console wizard now offers flat-rate plans first; pick **Pay as you go**,
     not "Free" (flat-rate Free caps at 1M requests/100 GB).
   - Billing guard: an AWS Budget of $5/month with an email alert, so crossing the free tier is
     noticed, not discovered on the invoice.
   - Standard logging off (cost); WAF off (not needed for static images).
4. **Bucket policy on `arovolife-prod`** — CloudFront may read **only** the catalogue prefixes:
   ```json
   {
     "Version": "2012-10-17",
     "Statement": [{
       "Sid": "CloudFrontCatalogOnly",
       "Effect": "Allow",
       "Principal": {"Service": "cloudfront.amazonaws.com"},
       "Action": "s3:GetObject",
       "Resource": [
         "arn:aws:s3:::arovolife-prod/products/gallery/*",
         "arn:aws:s3:::arovolife-prod/products/inline/*",
         "arn:aws:s3:::arovolife-prod/categories/*",
         "arn:aws:s3:::arovolife-prod/category-banners/*",
         "arn:aws:s3:::arovolife-prod/banners/*"
       ],
       "Condition": {"StringEquals": {"AWS:SourceArn": "arn:aws:cloudfront::315084008094:distribution/<DIST_ID>"}}
     }]
   }
   ```
   Block Public Access stays ON — an OAC policy scoped by `AWS:SourceArn` is not "public", so S3 accepts it.
   Scoped to `products/gallery/*` + `products/inline/*`, never `products/*` (compliance review
   2026-09-24): anything later written elsewhere under `products/` must not become CDN-readable.
5. **GoDaddy:** `CNAME cdn → <dist>.cloudfront.net` (TTL 1 h). I add it.
6. Staging: **no CDN** (leave `CATALOG_CDN_URL` unset) — it exercises the signed fallback, and
   the render-time rewrite below still fixes its inline-image bug. A staging distribution can be
   added later with the same recipe if wanted.

## Part 2 — Code (one small slice, config-gated)

Killswitch = empty `CATALOG_CDN_URL`: behaviour is byte-identical to today (signed URLs).

1. **Config** — `config/arovolife.php`: `'media' => ['catalog_cdn_url' => env('CATALOG_CDN_URL')]`;
   add `CATALOG_CDN_URL=` with a comment to `app/.env.example` (prod value `https://cdn.arovolife.com`).
2. **`app/Modules/Catalog/Support/CatalogImageUrl.php`** (new, `final`):
   - `PUBLIC_PREFIXES = ['products/gallery/', 'products/inline/', 'categories/', 'category-banners/', 'banners/']`
   - `for(string $key): string` — CDN configured **and** key starts with an allowed prefix →
     `rtrim(cdn,'/').'/'.$key`; otherwise `Storage::disk('s3')->temporaryUrl($key, now()->addDay())`
     (today's behaviour, unchanged).
   - `rewriteStoredHtml(string $html): string` — replaces any S3 URL in `src="…"` of the form
     `https://<bucket>.s3[.<region>].amazonaws.com/<allowed-prefix><uuid>.<ext>[?X-Amz-…]` with
     `for($key)`. External URLs and anything outside the allow-list are left untouched.
3. **Call sites (swap the inline `temporaryUrl` for the helper):** `ProductImage::url()`,
   `ProductCategory` image + banner accessors (lines ~69, ~84), `Banner` accessor (~64).
4. **Inline description images:**
   - `trixUpload()` already returns `$image->url()` → now a CDN URL on prod.
   - Render path: add `Product::descriptionHtmlForDisplay(): string` =
     `CatalogImageUrl::rewriteStoredHtml($this->description_html)`; use it at
     `shop/product.blade.php:280` (still `{!! !!}` of purified HTML — output is only URL-swapped).
     This heals every already-saved description (staging's broken images re-sign each render;
     prod's become CDN) with **no data migration**.
5. **No change** to `IdPhotoStorage`, KYC vault, grievance/ADC/payout stores, or `ProductImageStorage` writes.

### Tests (`tests/Modules/Commerce/StorefrontCatalogTest.php`, `tests/Modules/Admin/AdminCatalogTest.php`, new `tests/Modules/Catalog/CatalogImageUrlTest.php`)
- CDN set → each of the 4 accessors returns `https://cdn.example/<key>`; CDN unset → signed URL (current assertions keep passing).
- Allow-list: `user_5/id-photo/<uuid>.jpg` and `kyc/…` never get a CDN URL even with CDN set.
- `rewriteStoredHtml`: signed S3 URL for `products/inline/<uuid>.png` → CDN URL; external
  `https://example.com/x.png` unchanged; S3 URL for a non-catalogue prefix unchanged.
- Product page renders a description whose stored `src` is an expired signed URL with the CDN URL.
- `trixUpload` JSON `url` is the CDN URL when configured.
- Run per `docs/local-dev-environment.md` against `arovolife_test` (never bare `php artisan test`); Larastan L7; Pint.

## Part 3 — Deploy + verify

1. Deploy the code to prod (normal `app:deploy`), then set `CATALOG_CDN_URL=https://cdn.arovolife.com`
   in the prod `.env` (user edits; `.env` is deny-ruled for me) → `php8.4 artisan config:cache`.
2. Upload one product image + one inline description image + one category/banner via admin.
3. Checks:
   - Shop page `<img src>` values start with `https://cdn.arovolife.com/`.
   - `curl -I https://cdn.arovolife.com/products/gallery/<uuid>.jpg` → 200, `Cache-Control: public, max-age=31536000, immutable`, `x-cache: Hit from cloudfront` on the 2nd call.
   - `curl -I https://cdn.arovolife.com/kyc/anything.jpg` and `/user_1/id-photo/<uuid>.jpg` → 404 (function) — and a direct CloudFront origin test of a KYC key would 403 (bucket policy).
   - Traversal: `curl -I 'https://cdn.arovolife.com/products/inline/x.png/../../user_1/id-photo/x.jpg'` → 404/403.
   - Run all three private-path checks **before** setting `CATALOG_CDN_URL` (compliance deploy condition).
   - Direct `https://arovolife-prod.s3.ap-south-1.amazonaws.com/products/gallery/<uuid>.jpg` (unsigned) → 403 (bucket still private).
   - Profile photo on the ID card still renders (signed, 15 min).
4. Docs: `docs/runbooks/cloudways-deployment.md` production section — CDN env var, distribution
   ID, the three enforcement layers; `docs/runbooks/` note on adding a new public prefix (must
   change all three layers together).
5. Review: `compliance-officer` (touches the bucket that holds KYC scans — hard rule 8) before merge.

## Operations note
Images are cached `immutable` for a year. Deleting a catalogue image in admin does not purge
CloudFront or browsers: if an image must disappear fast (wrong upload, a photo of a person),
run a CloudFront invalidation for its path. Catalogue uploads are already EXIF-stripped
(`ProductImageStorage::stripExif`).

## Follow-ups (not in this slice)
- Product-page SEO: `og:image`, `twitter:image`, JSON-LD `Product` with the CDN image, canonical
  URL, `sitemap.xml` — only worthwhile once images are stable (this slice).
- Optional WebP variants at upload time for smaller pages.
