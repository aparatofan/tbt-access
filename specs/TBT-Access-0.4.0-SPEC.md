# TBT Access 0.4.0 — Courses, library and content restriction (replaces WooCommerce Memberships)

**Repo:** `aparatofan/tbt-access` · **Version:** `TBT_ACCESS_VERSION = '0.4.0'` (header too) · **No custom tables.** New keys, all additive: option `tbt_access_courses`, option `tbt_access_enforce`, option `tbt_access_migration_log`, post meta `_tbt_access_free`. Grants keep using user meta `tbt_access_grants` from 0.3.0 (new keys only, same shape).
**New files:** `includes/courses.php` (registry + resolver), `includes/restrict.php` (enforcement, lists, buy page), `includes/migrate.php` (Memberships import), `templates/locked.php`. Required from `tbt-access.php` after `grants.php`.

*Written 27 Nov 2026 from Mariusz's decisions (see `specs/TBT-Access-0.4.0-NOTES.md`, corrected the same day).*

---

## Why

WooCommerce Memberships restricts TBT's lessons today, and it leaks: the 0.3.0 test showed that any account opens the library and two of the three courses, because the rules name parent categories while lessons sit in subcategories. That becomes a real problem the moment passes are sold, since every purchase creates an account.

0.4.0 moves content restriction into TBT Access, using the grants engine from 0.3.0. After it ships, Memberships is switched off. Subscriptions is already unused. Then WooCommerce core can be updated.

## What gets sold and how content opens

| Thing | Grant key | Opens | How you get it |
|---|---|---|---|
| Platform pass (0.3.0) | `platform` | Notes + Teacher Tools. **No lessons, no courses.** | Bought (1 month / 12 months) |
| **Personal Productivity** | `course-personal-productivity` | cat 669 | **Bought**, lifetime |
| **Man and Society** | `course-man-and-society` | cat 870 | **Bought**, lifetime |
| Cała biblioteka | `library` | the whole library (Learning English, General Interest, Personal Development, …) **except manual courses** | By hand, migration from Memberships, later the 7-day trial. Not sold for now. |
| Persuasive Presentations | `course-persuasive-presentations` | cat 731, page 11830, post 11861 | **By hand only** |
| English for Graphic Designers | `course-graphic-designers` | cat 1423, pages 37315, 225614, 225646, 226096, post 37256 | **By hand only** |
| How to say it | `course-how-to-say-it` | cat 1881 | **By hand only** |

## Locked decisions

1. **The library is visible, not free.** Anyone can see lesson titles in lists. Opening a lesson without access shows a buy page instead of the lesson.
2. **Free lessons.** Mariusz marks lessons as free (one per category is his plan). A free lesson is open to **everyone, signed in or not**. Free beats every rule below.
3. **A "course" is a definition, not content.** Each course (and the library) is a stored record: name, key, categories, extra page/post IDs, an "exclusive" flag and a short Polish description. Categories include **all their subcategories, automatically and at runtime** (a subcategory added next year is covered). No lesson is moved or edited.
4. **Access rule for a post or page:**
   - Free (`_tbt_access_free`) → open.
   - Viewer can `edit_others_posts` (Mariusz, Madzia, editors) → open.
   - Belongs to **no** course definition → open (normal pages, shop, blog, teacher-tool pages).
   - Belongs to one or more **exclusive** courses → open only with a grant for one of those exclusive courses. Nothing else opens it, not even `library`.
   - Otherwise → open with a grant for **any** course it belongs to. So a Personal Productivity lesson opens with `course-personal-productivity` **or** `library`.
5. **Exclusive = the three manual courses.** They must never open through the library or anything else.
6. **The product that sells a course is found automatically:** the published product whose TBT Access grant (0.3.0 T2) is that course's key. No product → the course is "not on sale yet".
7. **Course grants are lifetime when bought.** No reminder emails for anything except `platform` (0.3.0 T5 unchanged).
8. **Grants never add capabilities** except `platform` (0.3.0 decision 2 unchanged). Courses and the library only open content.
9. **Existing members keep what they had.** Open-ended Memberships become lifetime grants; dated ones keep their end date (e.g. the PwC group, 2027-03-04).
10. **Customer-facing text is Polish; admin is English.** Same as 0.2.x and 0.3.0.
11. **Fail open on errors, fail closed on content.** A resolver error must not break a page or checkout: log it and treat the post as belonging to no course **only if** enforcement is off. With enforcement on, an error on a lesson shows the buy page, never the lesson.
12. **WooCommerce 5.5.5 and 11.3.x must both work** (same as 0.3.0 decision 9).
13. **Switch-over is controlled.** Enforcement is an option (`off` / `on`), default `off` on upgrade. Memberships and TBT Access never restrict at the same time in normal use: Mariusz turns TBT Access on and deactivates Memberships in the same sitting (see "Switch-over").

---

## Phase 0 — read-only inventory, then stop and report

CC writes the SQL; Mariusz runs it in phpMyAdmin and pastes results. No code changes until Mariusz and Claude have read the report.

- **0.1 Category tree.** Full tree under 808, 498, 773, 1423, 1738 and any other top-level category holding lessons: ID, name, parent, post count. Name of 808 and 773.
- **0.2 Manual-course overlap.** For 731, 1423 and 1881: their ancestor chains, and whether any of their posts also sit in library categories. Count the active VIP Student / VIP Teacher / Teacher Access members who can open manual-course content **today** through those categories (e.g. 1881 sits under Learning English 668, which VIP covers). **Stop and report** the number: decision 5 takes that access away from them, and Mariusz confirms or adds those people by hand.
- **0.3 Content inventory per definition.** Using the seeded definitions in T1, list every published post and page each one covers (ID, title, categories). Then list **lesson posts covered by no definition** (published posts in categories that look like lessons but fall outside every definition). **Stop and report** that list: each item is a leak or an intentional open page, and Mariusz decides.
- **0.4 Teacher-tool and account pages.** Confirm no Notes, teacher-tool, Students, My account, checkout, `/vip/`, `/do-zobaczenia/` or shop page falls inside any definition. **Hard stop** if one does.
- **0.5 Unknown items.** Titles of pages 7668 and 7680; what category 1738 "Lucy" and 507 "PRIVATE EYES ONLY" contain.
- **0.6 Memberships data for migration.** Post type and statuses of user memberships (`wc_user_membership`; expected statuses `wcm-active`, `wcm-complimentary`, `wcm-free_trial`, `wcm-pending`, `wcm-paused`, `wcm-expired`, `wcm-cancelled`), the end-date meta key, and counts per plan and status. Confirm that deactivating the Memberships plugin leaves this data in place.
- **0.7 Where lessons are listed.** How category archives, Divi blog modules and the home/VIP pages render lesson cards: does `post_class()` reach the card element, and what markup wraps title and image? Needed for T4.
- **0.8 Other ways content leaks.** Whether full content is exposed in RSS feeds, the REST API (`/wp/v2/posts`), site search results, or any TBT tool that pulls lesson content (Hub, Lesson Builder, Search Table). Report which ones need the T3 filters.
- **0.9 The old "UPS!" path.** Where the current restricted message comes from (Memberships setting, a page, theme code) and whether anything else redirects to it.
- **0.10 How to say it codes.** Whether TBT Hub (or anything else) already unlocks category 1881 by a book code. Report only; codes are out of scope.

---

## Tasks

### T1 — Course registry (`includes/courses.php`)

Option `tbt_access_courses`: an ordered array of records:

```
key           string   'library' or 'course-<slug>'; set once, never changed after save (data contract)
name          string   e.g. 'Personal Productivity' (shown to customers as is)
description   string   short Polish text for the buy page (1–3 sentences, plain text + line breaks)
categories    int[]    category IDs; subcategories included at runtime
pages         int[]    extra page IDs
posts         int[]    extra post IDs
exclusive     bool
```

**Seed on activation/upgrade** if the option is empty, from the Memberships rules in the notes:

| key | name | categories | pages / posts | exclusive |
|---|---|---|---|---|
| `library` | Cała biblioteka | 668, 497, 498, 507, 1738 | pages 735, 803, 11160, 11231, 11246, 11252, 11262, 11269, 11274, 11283, 11290, 11297, 11303, 11311, 11334, 11343, 11352, 11360, 11370, 11396, 12750, 14982, 7668, 7680 | no |
| `course-personal-productivity` | Personal Productivity | 669 | — | no |
| `course-man-and-society` | Man and Society | 870 | — | no |
| `course-persuasive-presentations` | Persuasive Presentations | 731 | page 11830, post 11861 | **yes** |
| `course-graphic-designers` | English for Graphic Designers | 1423 | pages 37315, 225614, 225646, 226096; post 37256 | **yes** |
| `course-how-to-say-it` | How to say it | 1881 | — | **yes** |

Seeded descriptions are empty; Mariusz fills them in.

**Admin screen** (English): **Users → TBT Access courses** (or a TBT Access menu if one exists by then; capability `manage_options`).
- List: name, key, categories (names + total post count incl. subcategories), extra items count, exclusive, **product** (linked, or "not on sale"), **holders** (count of users with an active grant).
- Edit form: name, key (editable only before first save; auto-slug from name with the `course-` prefix), description, categories (hierarchical checkbox list), extra page IDs and post IDs (comma-separated, validated: must exist), exclusive checkbox.
- Add / delete. Deleting a course that has holders asks for confirmation and **does not** delete anyone's grant (the key just stops opening anything).

**Grant keys:** hook the 0.3.0 filter `tbt_access_grant_keys` to add every course key with its name, so the product "Grants" select (0.3.0 T2) and the user profile (T8) list them. When a course key is chosen on a product, the duration defaults to **lifetime**.

### T2 — Resolver

- `tbt_access_post_courses( int $post_id ): string[]` — keys of every definition containing the post: post ID or page ID listed, or any of the post's categories **or their ancestors** listed. Build a category → keys map once per request (walk ancestors with `get_ancestors()`); cache per post.
- `tbt_access_can_view( int $user_id, int $post_id ): bool` — decision 4, in that order. Signed-out = user 0 (no grants).
- `tbt_access_offer_for( int $post_id ): array` — what the buy page shows: `[ 'type' => 'exclusive'|'sale'|'none', 'course' => key|null, 'product_id' => int|null ]`. Priority: an exclusive course → a course with a product (first in registry order) → `none` (library or courses not on sale).
- `tbt_access_course_product( string $key ): int|null` — decision 6; cached per request. If several products match, use the lowest ID and log once.

### T3 — Enforcement

Only when option `tbt_access_enforce = on`.

- **Singular views:** on `template_redirect`, for `is_singular()` posts and pages where `tbt_access_can_view()` is false: send `X-Robots-Tag: noindex`, status 200, and render `templates/locked.php` inside the theme's header and footer (`get_header()` / `get_footer()`), then exit. The page keeps the lesson's title and featured image so the visitor knows what they clicked.
- **Excerpts elsewhere:** for locked posts, `the_content` returns the excerpt (no builder output) in feeds, REST responses (`rest_prepare_post`, `rest_prepare_page`) and search results. Apply only where Phase 0.8 found exposure.
- **Admins:** when someone with `edit_others_posts` views content that would be locked for others, add an admin-bar note: `TBT Access: locked for others (course: Personal Productivity)`. No block.
- **No caching surprises:** call `nocache_headers()` on the locked template for signed-in users. Signed-out locked pages may be cached (they're the same for everyone).

### T4 — Locked look in lists

- `post_class` adds `tbt-locked` to posts the current viewer can't open (skip on admin screens). Free lessons get `tbt-free`.
- CSS (scoped, `:where()`, tokens from Hub): locked cards at reduced saturation/opacity with a small lock icon on the image; free cards get a small "Za darmo" pill. Exact selectors come from Phase 0.7. Titles stay readable; links stay as they are (they lead to the buy page).
- Applies equally to signed-in and signed-out visitors, so pass holders see courses greyed out (decision from 26 Nov).

### T5 — Buy page (`templates/locked.php`)

TBT style (same tokens as the 0.2.0 account screens: gradient panel, Roboto Slab headings, pill buttons). Polish. Layout: lesson title and image on top, then one of three blocks.

**A — Sale course** (`type = sale`):
> **Ta lekcja jest częścią kursu {name}.**
> {description}
> {price}   **[KUP KURS]** → adds the product to the cart and goes to checkout
> Jednorazowa płatność, dostęp bez limitu czasu.

Signed out, add: `Masz już ten kurs? [Zaloguj się]` (returns to this lesson after sign-in, as 0.1.0 does).

**B — Not on sale yet** (`type = none`):
> **Oferta w przygotowaniu.**
> Ta lekcja jest w bibliotece The Blue Tree, która nie jest jeszcze w sprzedaży. Sprawdź kursy, które możesz kupić już teraz:

then one card per course that has a product (name, description, price, **[ZOBACZ KURS]** → that course's first free lesson if any, otherwise its category page). Below: `Darmowe lekcje z tej kategorii:` with up to 3 free lessons from the post's top-level category (omitted if none). Signed out, add the sign-in line.

**C — Manual course** (`type = exclusive`):
> **Ten kurs jest dostępny dla uczniów, którym przydzielił go nauczyciel.**
> Jeśli to Twój kurs, zaloguj się. Jeśli jesteś zalogowany(-a) i nie masz dostępu, napisz do mnie: {kontakt}

`{kontakt}` = the site admin email as a `mailto:` link. Signed out: **[ZALOGUJ SIĘ]** button.

All texts in one PHP array at the top of the template so Mariusz's corrections are one-line changes.

### T6 — Free lesson switch

- A side meta box on posts and pages (classic and block editor): checkbox **"Free lesson (open to everyone)"** → `_tbt_access_free = 1`.
- Posts list: a "Free" column and a quick filter link "Free lessons (N)".
- Free is per post; it doesn't make the category free.

### T7 — Selling courses

- Products use the 0.3.0 settings: **TBT Access → Grants: {course}**, **Duration: lifetime**. 0.3.0 T3 (grant on payment), T3b (account required) and T4 (refund removes a lifetime grant made by that order) apply unchanged.
- Order note wording for courses: `TBT Access: course "Personal Productivity" — lifetime.`
- Library key `library` is selectable on products too (for a future sale) but nothing is created now.

### T8 — Admin: user profile, Users list, bulk grant, access check

- **User profile → TBT Access:** one row per grant key (platform, library, every course): **none / until {date} / lifetime**, with the 0.3.0 controls (date, Lifetime, Remove). Source shown under each row: order links, "migrated from Memberships plan {name}", or "set by {admin} on {date}".
- **Users list:** keep "Platform until"; add **"Courses"** column (administrators only): short labels of active grants, e.g. `Biblioteka, PP, How to say it`. Hover = full names and dates.
- **Bulk action** on Users: `TBT Access: grant…` and `TBT Access: remove…` with a course select (lifetime grant). For giving a group of his students a manual course in one go.
- **Access check tool** (on the courses screen): pick a user (or "signed out") and paste a URL or post ID → **Open / Locked**, with the reason (free / editor / no course / which course and grant). Used for the acceptance tests and later for "why can't my student see this?".

### T9 — Migration from Memberships (`includes/migrate.php`)

A button on the courses screen: **"Import from WooCommerce Memberships"**. Two steps:

1. **Preview** (no writes): a table of every user membership with status `wcm-active`, `wcm-complimentary`, `wcm-free_trial` or `wcm-pending-cancellation` (adjust to Phase 0.6): user, plan, → grant key, until (date or lifetime), and whether the user already has an equal or better grant.
2. **Import:** writes grants with `tbt_access_set()`, **never shortening** an existing grant (lifetime beats a date; a later date beats an earlier one). Logs the run to `tbt_access_migration_log` (date, admin, counts per plan, skipped). Safe to run twice.

Plan → grant map:

| Plan | Grant |
|---|---|
| 10988 VIP Student Access, 28834 VIP Teacher Access, 28429 Teacher Access, 28833 Student Access, 10786 / 10796 / 10799 | `library` |
| 11815 Persuasive Presentations | `course-persuasive-presentations` |
| 37312 English for Graphic Designers | `course-graphic-designers` |
| 366754 How to say it | `course-how-to-say-it` |

`until` = the membership's end date at 23:59:59 Warsaw time, or lifetime when it has none (decision 9). Memberships data is only read, never changed.

### T10 — My account: "Twoje kursy"

Under the 0.3.0 platform line on the My account dashboard, when the user has any library or course grant:

> **Twoje kursy**
> Personal Productivity · bezterminowo → link
> Cała biblioteka · do 4 marca 2027 → link

Link = the course's first category page. Nothing shown to users with no such grants.

### T11 — Version and changelog

Bump `TBT_ACCESS_VERSION` and the header to `0.4.0`. CHANGELOG entry. Upgrade routine: seed T1 if empty; set `tbt_access_enforce = off` if missing.

---

## Switch-over (Mariusz, one sitting, after deploy)

1. **Backup** (lh.pl snapshot or UpdraftPlus). Enforcement is **off** after deploy, so nothing changes for visitors yet.
2. **Users → TBT Access courses:** check the six seeded definitions against the Phase 0 report; write the descriptions for Personal Productivity and Man and Society.
3. **Create the two course products:** simple virtual products, **TBT Access → Grants: {course}, Duration: lifetime**, prices of your choice. Publish.
4. **Mark the free lessons** (one per category).
5. **Run the import** from Memberships: preview, check the numbers (about 131 memberships / 90 users), import.
6. **Access check tool:** try the test account (no grants), a VIP member, a How to say it student and "signed out" on a few URLs from each course.
7. **Deactivate WooCommerce Memberships**, then set **Enforcement: on**, then **purge LiteSpeed's cache**. Do these three together.
8. Run the acceptance checklist below.

**Rollback:** Enforcement off + reactivate Memberships. Grants stay stored; nothing is lost.

---

## Out of scope

- **Mind-map discounts** for members (Memberships' 100% rules). They stop when Memberships is deactivated. If Mariusz wants them back, a small release gives `library` holders the discount.
- How to say it **book codes** (HUB). Manual grants now.
- Phase 3: free 7-day accounts (will grant `library` for 7 days, plus `platform` for teachers), sign-up, CAPTCHA, Google sign-in.
- Selling the whole library. The key exists; the decision and product come later.
- 0.3.1 (repeat purchase fixes), NIP field, `/welcome/` page.
- Deleting Memberships and Subscriptions plugins and their data (after a few quiet weeks).
- Updating WooCommerce core.

## Acceptance checklist (live, enforcement on)

Test account `hi@` with **no grants** unless stated.

- [ ] **Signed out**, open a Learning English lesson: buy page **B** ("Oferta w przygotowaniu"), with the two course cards and the sign-in line.
- [ ] Signed out, open a **free** lesson: the full lesson.
- [ ] Signed out, open a Personal Productivity lesson: buy page **A** with price and **KUP KURS**; a lesson in a **subcategory** of a library category is blocked too.
- [ ] Signed out, open a How to say it lesson: page **C**.
- [ ] **Buy Personal Productivity** as `hi@` (100% coupon): order note `course "Personal Productivity" — lifetime`; every PP lesson opens; Man and Society and other library lessons still show the buy page; My account shows "Twoje kursy".
- [ ] Refund that order: PP lessons lock again.
- [ ] Give `hi@` **Cała biblioteka** on the profile: Learning English, General Interest, Personal Development, PP and Man and Society open; **How to say it, Persuasive Presentations and Graphic Designers stay locked** (exclusive).
- [ ] Bulk-grant **How to say it** to `hi@` from the Users list: How to say it opens; Persuasive Presentations stays locked.
- [ ] Give `hi@` a **platform pass** only: teacher tools and Notes open; all lessons locked; lists show courses greyed out.
- [ ] A migrated VIP member: library open; a migrated PwC member: Persuasive Presentations open with end date 2027-03-04 on the profile.
- [ ] Category archive and the VIP page: locked lessons greyed with a lock, free ones with "Za darmo", for `hi@` and signed out.
- [ ] Mariusz and Madzia see everything, with the admin-bar note on locked content.
- [ ] RSS / REST / search (whichever Phase 0.8 flagged) show no full content of locked lessons.
- [ ] Teacher-tool pages, Notes, My account, checkout and the shop work as before for a pass holder.
- [ ] Sign in, sign out, password reset and pass purchase behave as in 0.3.0.
- [ ] PHP error log: no new warnings from `tbt-access`. Remove test grants from `hi@`.
