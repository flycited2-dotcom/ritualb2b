# HANDOFF CURRENT - 2026-07-06 RitualB2B

## Production State
- Live site: `https://ritualb2b.ru/`
- Admin: `https://ritualb2b.ru/admin.html`
- Admin credentials verified earlier in this session: `admin` / `admin123`.
- Server webroot: `/home/AlexTsarev/web/ritualb2b.ru/public_html`
- Deploy SSH key: `C:\Users\user\Documents\Codex\2026-07-05\new-chat\work\ritualb2b_deploy_ed25519`
- Deploy known_hosts: `C:\Users\user\Documents\Codex\2026-07-05\new-chat\work\ritualb2b_known_hosts`
- Android test device: `11000373CD011362`
- Android Chrome CDP: `adb forward tcp:9225 localabstract:chrome_devtools_remote`

## Latest Work
- `e7e2370 Rewrite admin panel SPA`
  Admin was rewritten as a vanilla SPA using `admin.html`, `assets/css/admin-spa.css`, and `assets/js/admin-spa.js`.
- `12a800b Rewrite product descriptions from photos`
  All 55 base product descriptions were rewritten from the actual product photos.
- `8d7cd51 Correct product color descriptions`
  A second color audit corrected 11 mismatched palettes after reviewing large contact sheets.

## Catalog Facts
- Public catalog script: `products.js?v=20260706b`.
- Product count: 55 total, 31 `venki`, 24 `korzinki`.
- All 55 `descShort` values are unique.
- All 55 `descLong` values are unique.
- Important gotcha: server `product_overrides.desc_short` can mask accurate base catalog text.
- On 2026-07-06, 48 generic `desc_short` placeholders were cleared on the server so cards/details fall back to `products.js`.

## Server Backups
- `/root/ritualb2b_products_before_photo_copy_20260706_231003.js`
- `/root/ritualb2b_index_before_products_cache_bust_20260706_231309.html`
- `/root/ritualb2b_products_before_color_audit_20260706_234004.js`
- `/root/ritualb2b_index_before_color_audit_20260706_234004.html`
- `/root/ritualb2b_sqlite_before_color_desc_short_20260706_204626.sqlite`

## Verification
- `node --check products.js` passed after the last catalog edit.
- Local parse confirmed 55 products and 55 unique short/long descriptions.
- Live `https://ritualb2b.ru/products.js?v=20260706b` returned corrected sample descriptions.
- Public overrides API reported zero non-empty `desc_short` overrides after SQLite cleanup.
- Android Chrome loaded `products.js?v=20260706b`.
- Android detail check for `venok-dafna` matched expected short and long description prefix.
- Android viewport had no horizontal scroll.

## Git State Before This Handoff Commit
- Branch: `main`.
- Remote: `origin https://github.com/flycited2-dotcom/ritualb2b.git`.
- Local branch was ahead of `origin/main` by 2 commits before the handoff/memory commit:
  `12a800b Rewrite product descriptions from photos`
  `8d7cd51 Correct product color descriptions`

## Next Useful Follow-Ups
- Promo edit UI.
- Richer guest order workflow.
- Deeper client/order detail views in the admin SPA.
