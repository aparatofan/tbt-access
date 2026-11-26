# Changelog

## 0.3.0 — 2026-11-26

Platform access passes, without subscriptions. A pass is an ordinary one-off WooCommerce product: buying it gives access to Notes and the teacher tools until a date (or for life), buying again adds time, and a reminder email goes out before the end. No custom tables. New keys, all additive: user meta `tbt_access_grants`, `tbt_access_reminded`; product meta `_tbt_access_grant`, `_tbt_access_duration`; order meta `_tbt_access_applied`. New file: `includes/grants.php`. WooCommerce Memberships is not touched.

- **Grants engine.** Grants are stored per user as `{ key: { until, orders[], updated } }`, where `until` is a UTC timestamp or `null` for lifetime. One key in this release: `platform`. Access is checked live at each request (cron only sends emails). Public functions: `tbt_access_grant_until()`, `tbt_access_has_grant()`, `tbt_access_extend()`, `tbt_access_set()`, `tbt_access_grant_keys()` (filter `tbt_access_grant_keys`) and `tbt_access_platform_caps()` (filter `tbt_access_platform_caps`).
- **What a pass opens:** every capability of the `teacher` role whose name starts with `tbt` (read at runtime, so a new tool that adds its capability to that role is covered), **plus `manage_tbt_notes`**. Notes gives that capability to individual users, not to the role, and Notes, Points, Results, the Homework queue and Hub's "Teacher Tools" menu all check it. A grant only ever **adds** capabilities. Nobody loses access they were given by hand or through a role.
- **Extending stacks:** new end = later of (now, current end) + the product's duration, in calendar units in the site timezone. Buying one month twice gives two months. Lifetime is never shortened automatically.
- **Product setting** on Product data → General, for virtual products: **TBT Access → Grants** (none / Platform access) and **Duration** (1–999 days, months or years, or lifetime). An invalid value, or a product that isn't virtual, saves as none with an admin notice.
- **Grant on payment:** on `woocommerce_payment_complete`, `woocommerce_order_status_processing` and `woocommerce_order_status_completed`. Processing is included because a virtual (not downloadable) product never auto-completes, and a bank transfer marked "Processing" by hand fires neither of the others. Applied once per order (`_tbt_access_applied`, plus a short per-order lock), once per quantity unit. Order note: `TBT Access: platform access until 2027-01-26 (+1 months).` or `… — lifetime.` An order without a customer account gets a note and no grant.
- **Account required for a pass.** Guest checkout stays on for everything else, but when the cart holds a pass a signed-out buyer must create an account. It works through `woocommerce_checkout_registration_required` / `_enabled`, which classic checkout and the 11.x block checkout and Store API all read. Classic checkout shows "Dostęp do platformy jest przypisany do konta, dlatego przy tym zamówieniu założymy je dla Ciebie. Masz już konto? Zaloguj się." above the account fields, linking to the sign-in screen and back to checkout.
- **Refunds and cancellations** (`refunded`, `cancelled`): each applied duration is subtracted from the current end, never earlier than now. Refunding the order that made a grant lifetime removes the grant. A lifetime grant from another order is left alone. Applied once, with an order note. Partial refunds change nothing and leave a note to adjust by hand.
- **Reminder emails:** daily WP-Cron event `tbt_access_daily` (first run 08:00 site time; scheduled on activation, self-healed on `init`, cleared on deactivation). One email 7 days before the end, one on the day, in Polish, each sent once per grant and date, never for lifetime. Sent through WooCommerce's mailer in the shop's email style (plain `wp_mail()` without WooCommerce). The renewal link is the product from the most recent order that extended the grant, or `/vip/`.
- **My account dashboard line:** `Dostęp do platformy: do 26 stycznia 2027` · Przedłuż, `Dostęp do platformy: bezterminowy`, or `Dostęp do platformy wygasł 26 stycznia 2027` · Odnów. Users who never had a pass see nothing.
- **User profile** (administrators): a "TBT Access" section with the status, a date ("access until", inclusive), **Lifetime**, **Remove**, the orders that extended the grant, and **Send reminder now (test)**, which sends the 7-day email immediately without recording it (disabled when there's no timed grant).
- **Users list:** new "Platform until" column (administrators): date, "expired {date}", "lifetime" or "—".
- Checked against WooCommerce 5.5.4 and 11.3.0. Orders are read and written only through `WC_Order` methods (HPOS-safe). Every grants error is logged to the PHP error log and never breaks checkout, sign-in or a page.

After deploy: purge LiteSpeed's cache. Then create the pass products as new simple **virtual** products (e.g. "Dostęp do platformy — 1 miesiąc", "— 12 miesięcy") and set TBT Access → Grants: Platform access with the duration.

## 0.2.2 — 2026-11-20

Close open sign-ups, keep checkout accounts. No database schema changes; one new user meta key, `tbt_access_created_via`. New file: `includes/signup-guard.php`.

- **WordPress registration stays off.** `users_can_register` always reads as 0, and saving Settings → General always stores 0, so "Anyone can register" stays unticked and an old tick can't reopen registration later. A stored 1 from before 0.2.2 is reset to 0 on the next admin page load. Settings → General shows a "Registration is closed by TBT Access" notice to administrators.
- **`/wp-login.php?action=register` goes to the Polish sign-in screen** (`/my-account/`, 302), instead of WordPress's form or its English "registration disabled" message. The "Register" link on `wp-login.php` is gone.
- **My account registration is closed**, both the form and direct POSTs: `woocommerce_enable_myaccount_registration` reads as `no`, and `WC_Form_Handler::process_registration` (`wp_loaded`, 20) is removed at `init`. It never checks that option itself, in WooCommerce 5.5.4 and 11.3.0 alike.
- **Checkout is not touched.** Classic checkout, block checkout, the 11.x order-received "create account" block, Subscriptions and Memberships purchases all keep creating accounts through `wc_create_new_customer()`. None of them reads `users_can_register` or uses `process_registration`. Users → Add New is not touched either.
- **Every new account records where it came from** in `tbt_access_created_via`: `cli`, `checkout`, `rest`, `my-account`, `wp-login`, `admin` or `other:/path`, checked in that order. Checkout is checked before REST because block checkout is a Store API request. Checkout means: `WOOCOMMERCE_CHECKOUT` is defined (classic checkout sets it just before creating the account), a Store API `/wc/store/…/checkout` request, or a `create-account` post on the order-received page. This never blocks or breaks account creation.
- **Users list: new "Created via" column** for administrators. Accounts created before 0.2.2 show "—".
- **One switch for Phase 3:** the `tbt_access_open_signup` filter (default `false`). When it returns `true`, the three closures above step aside. Nothing turns it on in this release.

After deploy: purge LiteSpeed's cache.

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
