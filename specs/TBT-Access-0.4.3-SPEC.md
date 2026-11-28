# TBT Access 0.4.3 — New locked-lesson screen

**Repo:** `aparatofan/tbt-access` · **Version:** `TBT_ACCESS_VERSION = '0.4.3'` (header too) · **No new keys.** The lesson description is WordPress's own excerpt (`post_excerpt`). Access rules from 0.4.0 are unchanged.
**Files changed:** `templates/locked.php` (rewritten), `assets/tbt-access-locked.css` (rewritten), `includes/restrict.php` (assets + a view helper), `tbt-access.php` (version), `CHANGELOG.md`. No file or folder is renamed or added, except this spec.
**Prototype (accepted 28 Nov 2026):** "TBT Access 0.4.3 – zablokowana lekcja (prototyp)", project doc `specs/TBT-Access-0.4.3-PROTOTYPE.html`. Its top grey bar of switches is prototype-only.

*Written 28 Nov 2026 from Mariusz's decisions. Covers items 1, 3 and 7 of the 0.4.3 list. Items 2, 4, 5 and 6 are separate releases.*

---

## Why

The 0.4.2 locked screen reuses the sign-in layout: a blue panel fills half the screen and the lesson is squeezed into a narrow column with a 160 px cropped, greyed image. It looks like a login page, not a lesson. "Oferta w przygotowaniu" (said twice) reads like a shop under construction, which matters now: Przelewy24 is verifying the shop. And variant B offers courses the visitor already owns.

## Locked decisions

1. **Page width 1080 px** on desktop (content and header).
2. **Header = the TBT header row used on `/swipe/`** (Divi library item "HT Header tree", 369399): a rounded bar with the blue-to-white gradient and the **animated TBT tree from TBT Hub** (`[tbt_tree]`). The plugin draws the bar itself (own CSS) and takes only the tree from Hub. It does **not** embed the Divi library item, because the locked screen has no Divi template and the module's styles may not load there.
3. **Layout:** below the header, two columns. Left: the lesson (featured image whole, in its own proportions, no crop, no grey filter; a small "lock" pill on it; "LEKCJA"; title; excerpt). Right: the offer. One column below 860 px.
4. **Lesson description = the post's manual excerpt** (`post_excerpt`), shown as plain text. Empty excerpt → no description (never an auto-excerpt from the content).
5. **The offer is built around the courses the lesson belongs to** (variant A), with other courses lower down under "Odkryj też inne kursy".
6. **Never offer a course the viewer already holds** (active grant for that key), in A's "other courses" and in B.
7. **"Oferta w przygotowaniu" is gone everywhere.** B now says "Zacznij od kursu."
8. **Contact e-mail on the screen is `hi@thebluetree.pl`**, a fixed string in the text array (not `admin_email`).
9. **"Zobacz program kursu" links to the course's product page** (as 0.4.2).
10. Customer text is Polish; all strings stay in one array at the top of `templates/locked.php`.
11. **Nothing else changes:** access rules, status 200, `noindex`, `nocache_headers()` for signed-in visitors, the `the_content` excerpt swap in lists/REST/search, the `?tbt_buy=` legacy handler, product pages, cart, checkout.

---

## Phase 0 — read-only checks, then stop and report

No code until Mariusz and Claude have read the report. CC writes the SQL; Mariusz runs it in phpMyAdmin.

- **0.1 TBT Hub tree.** From the live Hub (1.6.0 or newer): confirm `tbt_hub_tree_shortcode()` exists, and on which hook and condition the style handle `tbt-tree` is **registered** (it must be registered by the time `wp_enqueue_scripts` runs at priority 99 on the locked screen). Report the handle's dependencies.
- **0.2 Free lessons ("Chcesz najpierw wypróbować lekcję?").**
  - (a) Count published posts with `_tbt_access_free = 1`, grouped by their top-level category.
  - (b) List published posts in category 947 "darmowa lekcja": ID, title, and all their categories.
  - **Stop and report.** Today the block uses free-flagged lessons from the lesson's top-level category (`tbt_access_free_lessons()`). If (a) is empty, the block would never show. Mariusz then decides: flag lessons with the T6 switch from 0.4.0, or use category 947 as the fallback (see T3).
- **0.3 Lessons in two sale courses.** Count published posts that sit in both 669 and 870 (with subcategories). Expected 0. Report only: T3 supports it either way.
- **0.4 Excerpts.** For categories 669, 870 and the library categories (668, 497, 498, 507, 1738 with subcategories): published posts total vs posts with a non-empty `post_excerpt`. Report only.

---

## Tasks

### T1 — View model (`includes/restrict.php`)

Add `tbt_access_locked_view( WP_Post $post, int $user_id ): array`. The template only renders what this returns. Wrap the whole function in `try`/`catch (\Throwable)`: on error, log and return variant `none` with no cards (the screen still renders and still hides the lesson).

```
variant        'sale' | 'none' | 'exclusive'
course         key|null       exclusive course (C), or the single sale course (A)
main           string[]       A: sale-course keys the post belongs to (registry order)
more           string[]       A: other sale courses, minus main, minus held
cards          string[]       B: sale courses minus held
free           int[]          up to 3 free lesson IDs (T3); empty on C
```

Rules, in order (exclusive keeps the 0.4.0 priority):

1. The post belongs to an **exclusive** course → `exclusive` (first such course, registry order).
2. The post belongs to one or more courses **that have a product** (`tbt_access_course_product()`), non-exclusive → `sale`, `main` = all of them.
3. Otherwise → `none`.

"Sale courses" = registry courses, non-exclusive, with a product. "Held" = `tbt_access_has_grant( $user_id, $key )` is true (user 0 holds nothing). The `library` key is never shown as a card (it has no product today; if it ever gets one it behaves like any other sale course).

`tbt_access_offer_for()` stays as is (other code and the admin access check use it).

### T2 — Header and tree

- On the locked screen, `tbt_access_enqueue_locked_assets()` also enqueues `tbt-tree` **if** `wp_style_is( 'tbt-tree', 'registered' )` (Phase 0.1).
- The template prints the tree with `tbt_hub_tree_shortcode( array( 'width' => 'clamp(220px,16vw,280px)', 'class' => 'tbt-locked__tree' ) )` when that function exists. It animates on load (Hub's bloom; Hub already handles `prefers-reduced-motion`).
- **Hub inactive:** no tree; the bar shows the texts only. (The white 0.2.0 tree would be invisible on the white end of the gradient.)
- Bar styles copied from the Divi global CSS (`.tbt-header-row`), scoped under `.tbt-locked`:
  - background `linear-gradient(90deg, #0856C9 0%, #0856C9 40%, #6F9EE2 72%, #DCE8F8 88%, #FFFFFF 100%)`, radius 20 px, padding 32 px 42 px, `overflow: hidden`, flex row (text 3 : tree 2).
  - eyebrow: Roboto 300, 14 px, letter-spacing .2em, uppercase, `#CECECE`, margin-bottom 16 px. Text `THE BLUE TREE`.
  - title: Roboto Mono 700, `clamp(30px, 3.6vw, 44px)`, line-height 1.25, letter-spacing .035em, white, `overflow-wrap: anywhere`. Course names are long, so smaller than Swipe's 50 px.
  - line: Roboto Slab 400, 18 px, line-height 1.5, `#CECECE`, max 46ch.
  - tree column: bottom-aligned, margin `-16px 0 -34px` (about 20 px of space above the tree, the trunk runs into the bottom edge), tree `margin-left: auto; margin-right: 16px`.
  - **≤ 700 px:** column layout, padding 26 px 24 px 0, gradient `180deg, #0856C9 0%, #0856C9 55%, #6F9EE2 80%, #DCE8F8 100%`, tree 150 px right-aligned under the text, line 16 px.
- Above the bar: "← Wróć do strony głównej" (blue link, as now) → `home_url('/')`.

### T3 — Template (`templates/locked.php`, rewritten)

Keep: own `<html>`, `wp_head()`, `body_class()`, `wp_body_open()`, `wp_footer()`; variables `$tbt_locked_post`, `$tbt_locked_offer` (still set by `tbt_access_render_locked_screen()`, which now also passes `$tbt_locked_view` from T1).

Page: white background. Container max-width 1080 px, centred; below 1120 px inline padding `max(4vw, 20px)`.

```
← Wróć do strony głównej
[ header bar: THE BLUE TREE / hero title / hero line          tree ]

[ LEFT: lesson ]                     [ RIGHT: offer ]
  image + pill                         (variant block, see below)
  LEKCJA                               "Masz już ten kurs? Zaloguj się" (guests, A and B)
  <h1> lesson title                    "Chcesz najpierw wypróbować lekcję?" (A and B, if free ≠ [])
  excerpt                              "Odkryj też inne kursy" (A, if more ≠ [])
```

Grid `minmax(0,1.1fr) minmax(0,1fr)`, gap 26 px 40 px; left column `position: sticky; top: 24px` on desktop. ≤ 860 px: one column, not sticky.

**Hero per variant:** A → `{name}` of the first `main` course + `hero_sale_line`; with 2+ `main` courses → `Biblioteka` + `hero_none_line`; B → `Biblioteka` + `hero_none_line`; C → `{name}` + `hero_excl_line`.

**Lesson (left):**
- Featured image: `get_the_post_thumbnail( $post, 'large' )`, `width: 100%; height: auto`, no `object-fit`, no filter. Portrait images (height > width, from the attachment metadata) are capped at 420 px wide and centred. Radius 14 px, 1 px border. No featured image → no image block (pill not shown).
- Pill on the image, top-left: lock icon + `pill_course` (A, C) or `pill_library` (B). Dark (`rgba(31,41,55,.88)`), white 13 px text, `white-space: nowrap`.
- "LEKCJA" (Roboto Mono label, as now), then the title as `<h1>` (Roboto Slab 700, `clamp(26px,3.4vw,32px)`).
- Excerpt: `trim( wp_strip_all_tags( $post->post_excerpt ) )` → `nl2br( esc_html() )` in a `<p>` (Roboto Slab 400, 17 px). Do **not** run `get_the_excerpt()` / `the_excerpt` filters. Empty → omitted.

**A — sale (`variant = sale`):**
- One `main` course → **featured card** (2 px blue border, soft shadow): `card_kicker` (small, muted) · course name (Roboto Slab 700, 24 px) · course description from the registry (plain text, line breaks kept) · `card_note` · price (`get_price_html()`) + primary button `card_button` → product permalink.
- Two or more `main` courses → a line `multi_lead_two` (exactly 2) or `multi_lead_many` (3+), then one **standard card** per course (same content without `card_kicker` and `card_note`, primary button).
- `more` → section `more_heading` with standard cards whose button is **secondary** (white, blue text, grey border).

**B — library (`variant = none`):**
- `cards` not empty → a white box with `none_heading` + `none_text`, then a standard card per course (primary button).
- `cards` empty (signed in and holds every sale course) → the box shows `none_owned_heading` + `none_owned_text` (with `{kontakt}` as a `mailto:` link), no cards.

**C — exclusive:** a featured card: `card_kicker` · course name · `excl_text` (with `{kontakt}` link) · button `excl_button` → `wp_login_url( permalink )` for guests only. No "try" block, no sign-in line, no "more".

**"Chcesz najpierw wypróbować lekcję?"** (A and B): `try_heading`, `try_text`, then the lessons in one line, separated by ` · `, each a link to the lesson. Source:
1. `tbt_access_free_lessons( [ top-level category of the post ], 3, $post->ID )` (as 0.4.2);
2. **fallback, only if Mariusz chooses it in Phase 0.2:** up to 3 published posts from category 947, oldest first.

Empty → the whole block is omitted.

**Sign-in line** (guests, A and B): `signin_question` + link `signin_link` → `wp_login_url( permalink )`.

### T4 — Texts (top of `templates/locked.php`)

```php
'hero_sale_line'     => 'Kurs The Blue Tree. Jednorazowa płatność, dostęp bez limitu czasu.',
'hero_none_title'    => 'Biblioteka',
'hero_none_line'     => 'Ponad 600 lekcji angielskiego.',
'hero_excl_line'     => 'Kurs przydzielany przez nauczyciela.',
'eyebrow'            => 'THE BLUE TREE',
'back'               => 'Wróć do strony głównej',
'pill_course'        => 'Lekcja w kursie',
'pill_library'       => 'Lekcja z biblioteki',
'lesson_label'       => 'Lekcja',
'card_kicker'        => 'Ta lekcja jest częścią kursu',
'card_note'          => 'Kupując kurs, zyskujesz dostęp do tej i pozostałych lekcji.',
'card_button'        => 'Zobacz program kursu',
'multi_lead_two'     => 'Ta lekcja jest dostępna w obu kursach. Wybierz ten, który bardziej Cię interesuje.',
'multi_lead_many'    => 'Ta lekcja jest dostępna w kilku kursach. Wybierz ten, który najbardziej Cię interesuje.',
'more_heading'       => 'Odkryj też inne kursy',
'none_heading'       => 'Zacznij od kursu.',
'none_text'          => 'Ta lekcja należy do biblioteki The Blue Tree. Możesz kupić jeden z kursów:',
'none_owned_heading' => 'Ta lekcja należy do biblioteki The Blue Tree.',
'none_owned_text'    => 'Dostęp do całej biblioteki przydziela nauczyciel – napisz na {kontakt}.',
'excl_text'          => 'Ten kurs jest dostępny dla uczniów, którym przydzielił go nauczyciel. Jeśli to Twój kurs, zaloguj się. Jeśli jesteś zalogowany(-a) i nie masz dostępu, napisz do mnie: {kontakt}',
'excl_button'        => 'Zaloguj się',
'try_heading'        => 'Chcesz najpierw wypróbować lekcję?',
'try_text'           => 'Wybierz temat i zobacz, jak wygląda nauka na The Blue Tree.',
'signin_question'    => 'Masz już ten kurs?',
'signin_link'        => 'Zaloguj się',
'contact_email'      => 'hi@thebluetree.pl',
```

`{name}` and `{kontakt}` are filled at render time; `{kontakt}` = `antispambot( contact_email )` as a `mailto:` link. Every other string is escaped with `esc_html()`.

### T5 — Styles (`assets/tbt-access-locked.css`, rewritten)

- Everything scoped under `.tbt-access.tbt-locked` with class-on-class selectors (Divi's global CSS still loads through `wp_head()`). Reset margins/padding of `h1–h4, p, ul, li` inside the screen.
- Tokens from 0.2.0 (`--tbt-access-*`, which read Hub's `tbt-tokens`). Body/page background white (`body.tbt-locked-screen`). Remove the 0.4.2 rule that centres a panel vertically at `min-height: 100vh`.
- Cards: white, 1 px `--tbt-access-border`, radius 14 px, padding 20–26 px; featured card 2 px `--tbt-access-blue` + `0 6px 20px rgba(24,56,96,.07)`. Price Roboto 700 22 px, `white-space: nowrap`. Card footer: price left, button right, wrapping.
- Buttons: the 0.2.0 primary pill; secondary = white background, blue text, border `--tbt-access-border`, blue border on hover. Min height 44 px.
- "Try" and "more" sections: 1 px top border, 18 px top padding, headings Roboto Slab 700 18 px.
- `tbt-access.css` keeps loading (buttons, links, tokens). Its `.tbt-access__panel` / `__hero` rules are simply no longer used here.

### T6 — Version and changelog

Bump `TBT_ACCESS_VERSION` and the header to `0.4.3`. CHANGELOG entry: new screen layout (header bar with Hub tree, 1080 px, two columns, whole image, excerpt), offer rules (T1), courses already held are never offered, new texts ("Oferta w przygotowaniu" removed), contact `hi@thebluetree.pl`. Note: "After deploy: no cache purge needed (LH.pl, no LiteSpeed page cache)."

---

## Out of scope (later in 0.4.x)

- Item 2: remove "You do not have access to any courses yet." (not in this plugin's code; find its source first).
- Item 4: own TBT Access admin menu. Item 5: Polish message when a reset link is opened while signed in to another account. Item 6: native `[wcm_restrict]` / `[wcm_nonmember]`.
- Polish excerpts for lessons (content work, Mariusz).
- My account in TBT style, `/welcome/`, 0.3.1, NIP, CAPTCHA.

## Acceptance checklist (live, enforcement on)

Test account `hi@` with **no grants** unless stated. Desktop ≥ 1280 px and phone (≈ 390 px).

- [ ] **Signed out**, `/black-box-thinking/` (Personal Productivity): header bar "Personal Productivity" with the tree blooming; image whole and in colour with "Lekcja w kursie"; title; excerpt; featured card with kicker, description, note, price and **Zobacz program kursu** → the PP product page; sign-in line; "Odkryj też inne kursy" with Man and Society (secondary button).
- [ ] Same lesson: "Chcesz najpierw wypróbować lekcję?" shows up to 3 lessons per the Phase 0.2 decision, or is absent if there are none.
- [ ] Signed out, a library lesson outside both courses: "Biblioteka" / "Ponad 600 lekcji angielskiego.", pill "Lekcja z biblioteki", "Zacznij od kursu." + two cards. **"Oferta w przygotowaniu" appears nowhere.**
- [ ] Signed out, a Persuasive Presentations lesson: variant C card with hi@thebluetree.pl and **Zaloguj się**; no try block, no other courses.
- [ ] Give `hi@` **Man and Society**: on a PP lesson, "Odkryj też inne kursy" disappears; on a library lesson only the PP card remains.
- [ ] Give `hi@` **both** courses: a library lesson shows "Ta lekcja należy do biblioteki The Blue Tree." + the teacher line with hi@thebluetree.pl, no cards. Remove both grants afterwards.
- [ ] A lesson **without** a manual excerpt: no description, nothing broken. A lesson without a featured image: no image block.
- [ ] Phone: one column, tree under the header text, nothing wider than the screen.
- [ ] Mariusz (editor) still sees the lessons themselves, with the admin-bar note.
- [ ] Lists, REST and search still show only excerpts of locked lessons (0.4.1 unchanged).
- [ ] A product page, cart and checkout work as before (Przelewy24 verification).
- [ ] PHP error log: no new warnings from `tbt-access`.
