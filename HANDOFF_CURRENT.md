# HANDOFF CURRENT - 2026-07-07 RitualB2B

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
- Admin editor reliability fix:
  Product edit drawer now has a real internal scroll container on mobile/desktop, locks the background while open, keeps Save/Cancel reachable, and adds S/M/L size buttons next to the manual size input. Admin asset cache is `20260707a`.
- Admin API/UI reliability pass:
  Product save now sends `active` in the same save request, toggles/selects roll back on API failure, settings save checks `config.php` writes, current/last admin demotion is blocked, Telegram report errors are explicit, and custom product delete clears matching overrides.
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
- `/root/ritualb2b_admin_fix_20260707_133223`
- `/root/ritualb2b_sqlite_before_admin_qa_20260707_133409.sqlite`
- `/root/ritualb2b_products_before_photo_copy_20260706_231003.js`
- `/root/ritualb2b_index_before_products_cache_bust_20260706_231309.html`
- `/root/ritualb2b_products_before_color_audit_20260706_234004.js`
- `/root/ritualb2b_index_before_color_audit_20260706_234004.html`
- `/root/ritualb2b_sqlite_before_color_desc_short_20260706_204626.sqlite`

## Verification
- Live admin credentials confirmed: `admin` / `admin123`.
- `node --check assets/js/admin-spa.js` passed after the admin editor fix.
- Server PHP lint passed on temp and live copies of `api/admin.php`.
- Live `admin.html` serves CSS/JS with `v=20260707a`.
- Authenticated API QA passed: login/profile/products list, self-demotion guard, temp custom product save/toggle/delete, temp override save/cleanup, promo create/toggle/delete, carousel save with same ids, settings save with same values, CSV export, and Telegram report.
- Android Chrome USB/CDP verified product editor scroll: `.drawer-body` had `clientHeight=503`, `scrollHeight=2301`, touch moved `scrollTop` to `775`, background `windowScrollY` stayed `0`, size chip `M` updated the manual size input, no horizontal scroll.
- Desktop headless Chrome verified product editor scroll and size chip `L`, no horizontal scroll, drawer close removed `drawer-open`.
- Desktop headless Chrome verified UI Add Product -> Save creates a temp custom product, then Delete removes it; DB QA cleanup check returned zero temp rows in `custom_products`, `product_overrides`, and `promo_rules`.
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
- Before this admin fix commit, `main` matched `origin/main` at `1720516 Update handoff and memory`.

## Next Useful Follow-Ups
- Promo edit UI.
- Richer guest order workflow.
- Deeper client/order detail views in the admin SPA.
