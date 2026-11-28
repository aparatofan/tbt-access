# Changelog

## 0.4.3 — 2026-11-28

A new locked-lesson screen. Access rules are unchanged. No new keys.

- **New layout.** The 0.4.2 screen reused the sign-in layout (half-screen blue panel, the lesson squeezed into a narrow column with a cropped, greyed image). Now:
  - **← Wróć do strony głównej**, then the **TBT header bar** as on `/swipe/` ("HT Header tree"): rounded bar, blue-to-white gradient, "THE BLUE TREE", the course name ("Biblioteka" on B and on a lesson in two courses), one line, and **TBT Hub's animated tree** (`[tbt_tree]`, blooms on load). The plugin draws the bar itself; only the tree comes from Hub. Hub inactive: no tree, texts only.
  - **1080 px wide**, two columns: the lesson on the left (sticky on desktop), the offer on the right. One column below 860 px; below 700 px the tree sits under the header text.
  - **The lesson:** the featured image whole, in its own proportions and in colour (portrait images up to 420 px wide), with a "Lekcja w kursie" / "Lekcja z biblioteki" pill; "LEKCJA"; the title as `<h1>`; the post's **manual excerpt** as plain text (no excerpt → no description, never an auto-excerpt).
- **Offer rules** (`tbt_access_locked_view()`, which the template only renders):
  - **A, course on sale:** one course → a featured card ("Ta lekcja jest częścią kursu", name, description, note, price, **Zobacz program kursu** → the product page). Two or more courses → a short lead and one card per course. Below, **"Odkryj też inne kursy"** with the other courses on sale (secondary button).
  - **B, library:** "Zacznij od kursu." and a card per course on sale. A viewer who already holds every course sees "Ta lekcja należy do biblioteki The Blue Tree." and the teacher line instead.
  - **C, course assigned by a teacher:** one card with the text, the contact e-mail and **Zaloguj się** for guests. No other blocks.
  - **A course the viewer already holds is never offered** (A's other courses and B).
  - **"Chcesz najpierw wypróbować lekcję?"** (A and B): up to 3 lessons, never the current one, each open to the viewer: free-flagged lessons from the lesson's top-level category first (none today), then the **newest posts in category 947** "darmowa lekcja".
  - "Masz już ten kurs? Zaloguj się" for signed-out visitors (A and B).
- **New texts.** "Oferta w przygotowaniu" is gone everywhere. Contact on the screen is **hi@thebluetree.pl** (a fixed string in the text array, not `admin_email`). All texts stay in one array at the top of `templates/locked.php`.
- `tbt-tree` (Hub) is enqueued in the head on this screen when Hub has registered it, so the tree never shows unstyled. Fonts add Roboto 300 and Roboto Slab 400.
- Unchanged: status 200, `noindex`, `nocache_headers()` for signed-in visitors, the excerpt swap in lists, REST and search, the `?tbt_buy=` handler, product pages, cart and checkout. `tbt_access_offer_for()` stays as is.

After deploy: no cache purge needed (LH.pl, no LiteSpeed page cache).

## 0.4.2 — 2026-11-27

The locked page is reworked as its own screen. Access rules are unchanged. No new keys.

- **Locked lesson = a clean full-page screen.** In 0.4.1 the buy page replaced only the post content, so Divi's Theme Builder post layout stayed around it: the left site menu, the numbered lesson nav and "THE END OF THE LESSON / Świetnie! Lekcja ukończona!", with the locked block top-left under the menu.
  - Now a locked lesson renders `templates/locked.php` as a standalone page at `template_redirect` and exits before the theme picks a template. It has its own `<html>`, `wp_head()` and `wp_footer()`, so styles load, but no Theme Builder header, footer or body layout.
  - **Layout:** the 0.2.0 sign-in screen layout, centred on the canvas, for all three variants. The gradient hero on the left carries "THE BLUE TREE", the course name ("Biblioteka" on B), one line and the small white tree bottom-right. The card column on the right holds:
    - **← Wróć do strony głównej**;
    - the featured image (greyed, with a centred lock) when there is one;
    - "LEKCJA" and the lesson title;
    - the A (sale), B (not on sale, with course cards and free lessons) or C (manual course) block;
    - "Masz już ten kurs? Zaloguj się" for signed-out visitors.
  - Buttons are the 0.2.0 primary button. Hero texts are in the text array at the top of the template with the rest.
  - The page loads `tbt-access.css` (0.2.0) plus `tbt-access-locked.css`, and Roboto, Roboto Slab and Roboto Mono.
  - Kept: status 200, `noindex` (header and robots tag), `nocache_headers()` for signed-in visitors. Divi's `et-tb-*` body classes are dropped on this screen.
- **KUP KURS (A) and ZOBACZ KURS (B) both link to the course's product page.** The `?tbt_buy=` add-to-cart handler stays only so links on pages cached before 0.4.2 keep working.
- **Locked list cards:** the lesson's icon panel inside `.et_pb_image_container` (`.tbt-actions`: rocket / level bars / stopwatch), and anything else laid over the image (Divi's hover overlay), is hidden. In its place is one larger lock (64 px), centred on the image. The image stays greyed out. Free cards are unchanged. Cards without an image keep the 🔒 by the title.
- Everywhere else a locked post is printed (archives, blog modules, home, search, feeds, REST), its content is still the excerpt (0.4.1).

After deploy: purge LiteSpeed's cache.

## 0.4.1 — 2026-11-27

Fixes from the live test of 0.4.0. No new keys.

- **Locked posts show only their excerpt everywhere except their own page.** 0.4.0 only cut REST. Divi archives, which print the full content, showed whole locked lessons below the lock icon, e.g. `/category/vip/general/society/`. Now one `the_content` filter at priority 999999 swaps the content of every locked post the viewer can't open. It runs after Divi's builder, shortcodes and wpautop, so none of the lesson survives. It covers category archives, Divi blog modules on any page (VIP, home), search, feeds, REST and AJAX pagination. The excerpt is the manual excerpt, otherwise 55 plain words with shortcodes stripped; the builder never runs. Open and free posts are untouched.
- **A locked lesson keeps the normal theme template.** In 0.4.0 the page was replaced by `get_header()` + `templates/locked.php` + `get_footer()`, which drew the Divi Theme Builder header without its CSS (huge tree logo, unstyled menu). Now the theme renders the lesson exactly as usual: Theme Builder header and footer with their styles, title, featured image. The same filter swaps only the post content for the buy page.
  - All three versions (A sale, B not on sale, C manual course) render this way.
  - The `noindex` header and robots tag, `nocache_headers()` for signed-in visitors and the `tbt-locked-page` body class stay.
  - Comments under a locked lesson are now hidden and closed.
  - `templates/locked.php` no longer prints its own title, image or `#main-content` wrapper. `assets/tbt-access-locked.css` no longer paints a page background, and resets Divi's content-area rules (`#left-area ul` etc.) inside the buy page.
- **ZOBACZ KURS** (not-on-sale page) links to the course's **product page**.
- **Access check** accepts addresses without a scheme, e.g. `thebluetree.pl/black-box-thinking/`, as well as `www.…`, `//…`, `/black-box-thinking/` and a bare `black-box-thinking`. The scheme and `www` follow the site's own address.
- **Seed:** a fresh install now seeds Cała biblioteka with only pages 225614, 225646 and 226096, matching the live correction. The other 24 were category listing pages, which must stay visible. Existing registries are not touched.

After deploy: purge LiteSpeed's cache.

## 0.4.0 — 2026-11-27

Courses, the library and content restriction, replacing WooCommerce Memberships. No custom tables. New keys, all additive: options `tbt_access_courses`, `tbt_access_enforce`, `tbt_access_migration_log`; post meta `_tbt_access_free`. Grants still live in user meta `tbt_access_grants` (new keys only); a grant set by hand or by the import also records an optional `source` (`{ type: admin|memberships, by, plan?, time }`) so the profile can say where it came from. 0.3.0 code ignores it. New files: `includes/courses.php`, `includes/restrict.php`, `includes/migrate.php`, `templates/locked.php`, `assets/tbt-access-locked.css`. Spec: `specs/TBT-Access-0.4.0-SPEC.md` (Phase 0 results included).

**Nothing changes for visitors after deploy:** enforcement is **off** until it's switched on (Switch-over in the spec).

- **Course registry** (Users → TBT Access courses, administrators). Each course is a definition: name, key (`library` or `course-<slug>`, fixed after the first save), Polish description, categories (every subcategory included at runtime, so a subcategory added later is covered), extra page and post IDs (validated), and an "exclusive" flag. Seeded on first run (when the option is missing) with Cała biblioteka, Personal Productivity, Man and Society, and the two exclusive manual courses, Persuasive Presentations and English for Graphic Designers, using the Phase 0 corrections. How to say it is not seeded. The list shows category names and the published item count incl. subcategories, extra items, exclusive, the product that sells it (or "not on sale") and how many users hold it. Deleting a course asks for confirmation and never deletes a grant.
- **Who can open what** (`tbt_access_can_view()`), in this order: free lesson → open to everyone, signed in or not; anyone with `edit_others_posts` → open; in no course → open; in an exclusive course → only that course's grant opens it (not even `library`); otherwise a grant for any course it belongs to. `tbt_access_post_courses()`, `tbt_access_offer_for()` and `tbt_access_course_product()` (the published product whose TBT Access grant is that key; lowest ID if several, logged once a day) are public too. All cached per request.
- **Errors:** a resolver error is logged. With enforcement off the post counts as open; with enforcement on a lesson shows the buy page, never the lesson.
- **Enforcement** (`on` / `off` on the courses screen, default `off`):
  - A locked post or page renders `templates/locked.php` inside the theme header and footer, status 200, `X-Robots-Tag: noindex` plus a robots noindex tag, `nocache_headers()` for signed-in visitors. WooCommerce pages and the front page are never locked, whatever a definition says.
  - REST (`/wp/v2/posts`, `/wp/v2/pages`): a locked post's `content.rendered` becomes a plain excerpt (manual excerpt, else 55 words with every shortcode stripped, so Divi's builder never runs) and `content.protected` is true. Phase 0.8 found full lessons there. Feeds and search already show excerpts only, so they're not touched.
  - Admin bar for editors: `TBT Access: locked for others (course: …)`, with "— enforcement off" while it's off.
  - Lists: `post_class` adds `tbt-locked` / `tbt-free` (front end only, same for signed-in and signed-out visitors). Small inline CSS, all inside `:where()`: locked card images desaturated and faded with a lock badge top-left of `.et_pb_image_container`, free cards get a green "Za darmo" pill. Titles and links are unchanged. Cards without an image get the marker next to the title.
- **Buy page**, in Polish, TBT style (gradient panel, Roboto Slab headings, pill buttons). All texts are in one array at the top of `templates/locked.php`.
  - **A** (course on sale): "Ta lekcja jest częścią kursu {name}.", the description, the price and **KUP KURS**, then "Jednorazowa płatność, dostęp bez limitu czasu."
  - **B** (not on sale yet): "Oferta w przygotowaniu.", a card for each course on sale (**ZOBACZ KURS** goes to its first free lesson, otherwise its category page), and up to 3 free lessons from the lesson's top-level category.
  - **C** (manual course): the teacher message with the site admin email as a `mailto:` link, and **ZALOGUJ SIĘ** for signed-out visitors.
  - Signed-out visitors on A and B also get "Masz już ten kurs? Zaloguj się", which returns them to the lesson.
  - KUP KURS is `/?tbt_buy=<product>`: it adds the product to the cart once (quantity 1, never twice) and goes to checkout. A product that can't be bought goes to its product page.
- **Free lesson switch:** a "TBT Access" side box on posts and pages (classic and block editor) with **Free lesson (open to everyone)**. It also shows which courses the post belongs to. Posts and Pages lists get a "Free" column and a "Free lessons (N)" filter link.
- **Selling courses:** every course and the library appear in the product's **TBT Access → Grants** select. Choosing a course switches Duration to lifetime. Grant on payment, account required at checkout, and refunds work exactly as in 0.3.0. Order note: `TBT Access: course "Personal Productivity" — lifetime.` With a course in the cart (and no pass), the checkout account note reads "Kurs jest przypisany do konta…". Reminder emails stay platform-only.
- **User profile → TBT Access:** one row per grant key (platform, library, every course, plus any stored key whose course was deleted), each with status, date, Lifetime and Remove. Under each row is where the grant came from: order links, "Migrated from Memberships plan {name} on {date}", or "Set by {admin} on {date}". The platform reminder test button stays on the platform row.
- **Users list:** "Courses" column for administrators, listing active library and course grants (`Biblioteka, Personal Productivity`), with full names and dates on hover. Two new bulk actions, **TBT Access: grant…** and **TBT Access: remove…**, use a course select next to the bulk actions. Grant gives the course for life.
- **Access check** (courses screen): pick a user, or tick "signed out", and give a URL or post ID. The result is **Open / Locked** with the reason (free, editor, no course, which course or grant) and the user's active grants. It works with enforcement off too.
- **Import from WooCommerce Memberships** (courses screen):
  - Reads `wc_user_membership` posts with status `wcm-active` straight from the database, so it works with Memberships deactivated, and never changes Memberships data.
  - Plans 10988, 28834, 28429, 28833, 10786, 10796 and 10799 → `library`, 11815 → Persuasive Presentations, 37312 → Graphic Designers. Any other plan (366754 How to say it, for example) is listed as not imported.
  - `_end_date` (UTC, as stored) → that end, or lifetime when empty.
  - The **preview** writes nothing. It lists every membership: user, plan, grant, until, what the user already has, and the result.
  - **Import** merges several memberships of one user into the best grant, never shortens an existing grant (lifetime beats a date, a later date beats an earlier one) and logs the run to `tbt_access_migration_log` (last 20 runs, shown on the screen). Running it twice writes nothing the second time.
- **My account:** "Twoje kursy" under the platform line, e.g. `Personal Productivity · bezterminowo`, `Cała biblioteka · do 4 marca 2027`, each with a link to the course's first category page. Users without library or course grants see nothing.
- **Upgrade:** on activation and on every `init` (an FTP deploy never runs activation), the registry is seeded if the option is missing, and `tbt_access_enforce` is set to `off` if missing.
- Checked against WooCommerce 11.3.0 (WordPress 7.1.5) and 5.5.4, PHP 8.3.

After deploy: purge LiteSpeed's cache. Then follow "Switch-over" in the spec: check the five definitions, write the descriptions, create the two course products, run the import (expect 108 active memberships), try the access check, then deactivate Memberships, set Enforcement on, and purge LiteSpeed again, in one sitting.

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
