# Changelog

## 0.2.1 — 2026-11-18

- **The signed-out account screens hide the Divi header's navigation** (menu, cart, search, mobile menu), so sign in, forgot password and set new password are distraction-free. The logo stays and links home. Signed-in My account and every other page keep the full menu.

## 0.2.0 — 2026-11-18

TBT-style sign-in screens, in Polish.

- **Signed-out `/my-account/` is one TBT panel**: a blue Tool Hero with the white tree on the left and the form on the right. It replaces the Divi "MY DASHBOARD" section, the delete-account text and WooCommerce's default forms. The site header and footer are unchanged, and signed-in My account looks exactly as before.
- **Four WooCommerce templates overridden** (sign in, forgot password, check email, set new password), with exact Polish copy. WooCommerce still handles the form posts, nonces, endpoints, emails and redirects. Every WooCommerce hook in those templates is kept for Phase 3.
- **Messages in Polish inside the card** instead of WooCommerce's English notices: wrong password, unknown account, empty fields, unknown user on the forgot screen, passwords that differ, an expired or used reset link, and "Hasło zmienione." after a reset. The related field is highlighted, and the typed username is kept. Errors use `role="alert"` and info uses `role="status"`. The notice queue is cleared so English notices don't appear on the next page.
- **Login failures are read from the `authenticate` result**, because WordPress doesn't fire `wp_login_failed` for empty fields. WooCommerce notices are matched through WooCommerce's own translations, so the en_GB language pack can't break the matching.
- **Sign-out now lands on the sign-in screen** (`/my-account/?tbt_signed_out=1`), with the "Wylogowano." banner and the "Do zobaczenia" hero. `/do-zobaczenia/` is no longer used.
- **Show/hide on every password field**, labelled "Pokaż hasło" / "Ukryj hasło". WooCommerce's own English toggle is kept out.
- **Strength meter on the reset form** uses WordPress core zxcvbn, with four bars and Polish hints. The save button is disabled below "Średnie" (2) or while the fields differ. The minimum lives in TBT Access's JS. WooCommerce's meter is dequeued on these screens only, and the site-wide `woocommerce_min_password_strength` (3) is not touched, so checkout and Edit account keep their rule.
- **Assets load only on signed-out account screens**: `assets/tbt-access.css` (scoped under `.tbt-access`, Hub tokens with Style Book fallbacks; Hub's ink and muted colours win), `assets/tbt-access.js`, `assets/tbt-tree-white.svg` (generated from Hub 1.5.0's tree), and Roboto 600 + Roboto Mono from Google Fonts. Columns stack below 820px.
- Checked against WooCommerce 5.5.4 and 11.3.0.

After deploy: purge LiteSpeed's CSS/JS cache, because LiteSpeed strips `?ver=`. Optionally unpublish `/do-zobaczenia/`.

## 0.1.0 — 2026-11-18

First release: one sign-in page and one set of redirects.

- **One sign-in page.** Front-end `wp_login_url()` links (header, TBT Quotes and other TBT tools) now point to `/my-account/?redirect_to=…`. wp-admin, AJAX, REST, forced re-auth, wp-admin returns and the session-expired popup still use `wp-login.php`.
- **After sign-in:** back to the safe page the person started from. Otherwise administrators go to wp-admin and everyone else to `/vip/` (filter: `tbt_access_default_destination`). Sign-in pages, `/stop/`, `/do-zobaczenia/`, `/contents/`, the My account root and its lost-password/logout endpoints are never return targets. wp-admin is a return target only for administrators.
- **Return path survives the My account form** via a hidden `redirect` field (WooCommerce 5.5 and 11.x print none).
- **After sign-out:** `/do-zobaczenia/`, via `logout_redirect` and `woocommerce_logout_default_redirect_url`. WooCommerce 11.x logs out from the My account endpoint without going through `wp-login.php`, so it needs the second filter.
- **Old sign-in pages retired (302):** `logowanie`, `membership-login`, `login_2`, `mp-login-page`, `membership-registration`, `membership-dashboard` → `/my-account/`. `membership-login/password-reset` → `/my-account/lost-password/`. `contents` → `/vip/`. A safe `redirect_to` is carried along.
- **Signed-in visitors** opening `/my-account/?redirect_to=…` go straight to the resolved destination.
- **`[tbt_signin_button label="…"]`** for `/stop/` and elsewhere. It returns the visitor to `?redirect_to`, then `?r` (a post ID), then a same-site referer. It uses the Hub `.tbt-button.tbt-button--primary` style, with a built-in fallback when Hub is inactive. Signed-in visitors see nothing.
- **`[plugin_delete_me]`** output is hidden from signed-out visitors.
- **Theme legacy code unhooked at runtime** (`functions.php` untouched): `my_login_redirect`, `wc_custom_user_redirect`, `osc_redirect_external_after_logout`, `thebluetree_user_from_registration`. The `[bluetree_check_logged]` and `[pastuser_fullname]` shortcodes now render nothing. Deactivating TBT Access restores the old behaviour.

Notes:
- The WooCommerce code was checked against the 5.5.4 and 11.3.0 tags (5.5.5 has no GitHub tag).
- Not registered with TBT Hub, because it has no admin screen.
- The first deploy needs a manual workflow run with **allow_create** ticked.
