# Changelog

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
