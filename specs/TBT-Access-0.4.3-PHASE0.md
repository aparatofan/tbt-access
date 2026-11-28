# TBT Access 0.4.3: Phase 0 checks

The spec says to do the read-only checks first and write **no code until Mariusz and Claude have read the report**. This file is that report: 0.1 is answered from TBT Hub's code, and 0.2–0.4 need the SQL in `specs/TBT-Access-0.4.3-PHASE0.sql`. Nothing in either file changes the site.

---

## 0.1 TBT Hub tree: answered from the code

Checked against `aparatofan/TBT-Hub` at **1.6.0** (`tbt-hub.php`).

| Question | Answer |
|---|---|
| Does `tbt_hub_tree_shortcode()` exist? | **Yes.** `add_shortcode( 'tbt_tree', 'tbt_hub_tree_shortcode' )`. Attributes: `width` (default `190px`, passed through `safecss_filter_attr`), `animate` (`yes` by default), `class`. |
| Where is `tbt-tree` registered? | In `tbt_hub_register_shared_styles()`, on **`wp_enqueue_scripts` priority 5**, with **no condition**: it runs on every front-end request and only registers the handle, never enqueues it. |
| Is it registered in time for the locked screen? | **Yes.** `tbt_access_enqueue_locked_assets()` runs on `wp_enqueue_scripts` priority **99**, so `wp_style_is( 'tbt-tree', 'registered' )` is true whenever Hub is active. |
| Dependencies | `tbt-tree` → **`tbt-tokens`** (also registered at priority 5). The locked screen already loads `tbt-tokens` through `tbt-access.css`, so nothing new is pulled in. |
| Reduced motion | `tbt-tree.css` turns the bloom off under `prefers-reduced-motion: reduce` and leaves the leaves fully visible. |

Three things that shape T2:

- The shortcode also calls `wp_enqueue_style( 'tbt-tree' )` itself. Called from the template body, that is too late for the `<head>`, and WordPress would print the stylesheet in the footer, so the tree would show without its styles for a moment. Enqueuing it at priority 99 as the spec says avoids that.
- `class` goes through `sanitize_html_class()` as **one** string, so only a single extra class works. The spec passes one (`tbt-locked__tree`), which is fine.
- The SVG is about 41 KB and is inlined (the bloom animates individual leaves). It is read once per request.

**Verdict: T2 can be built as written.**

---

## 0.2–0.4: run the SQL

phpMyAdmin → the site database → **SQL** tab. Run one block at a time; each starts with `-- [0.x]`. The queries assume the `wp_` prefix and use `WITH RECURSIVE`, like the 0.4.0 kit. I tested all six on MariaDB against a mock WordPress schema.

| Block | Spec item | What it answers |
|---|---|---|
| 0.2a | 0.2 (a) | Free-flagged published lessons, grouped by top-level category, with their IDs (oldest first) |
| 0.2a-total | 0.2 (a) | The same lessons as one total, each counted once |
| 0.2b | 0.2 (b) | Published posts in 947 "darmowa lekcja": ID, title, date, whether they're free-flagged, and all their categories (oldest first, the order the fallback would use) |
| 0.2c | 0.2 (context) | The top-level categories that the locked lessons (669, 870, library) sit under, and how many free-flagged lessons each has now. `free_now = 0` means those lessons get no "try" block under today's rule |
| 0.3 | 0.3 | Posts in both 669 and 870, with subcategories. Expected: no rows |
| 0.4 | 0.4 | Published posts vs posts with a manual excerpt, for 669, 870, each library category, and the library as a whole |

### The 0.2 decision (stop point)

Today the "Chcesz najpierw wypróbować lekcję?" block uses free-flagged lessons from the **top-level category of the lesson's first category** (`tbt_access_free_lessons()`). If 0.2a is empty, or 0.2c shows `free_now = 0` for the top-level categories your locked lessons sit under, the block never shows. Two options:

1. **Flag lessons with the T6 switch** ("Darmowa lekcja", from 0.4.0) under the top-level categories that need it. No code change.
2. **Use category 947 as the fallback:** if rule 1 finds nothing, show up to 3 published posts from 947, oldest first. 0.2b shows which three those would be.

Two notes for the decision:

- Rule 1 counts only the lesson's **first** category (WordPress's term order). For a lesson filed in two trees, it is the first category's tree that counts.
- Being in 947 doesn't make a post free. If 0.2b shows a post with `free_flag = no` that **also** sits in 669, 870 or a library category, it is locked, so a visitor who clicks "try this lesson" would land on another locked screen. In that case, either flag those posts, or have the fallback take only the 947 posts that are open. Tell me which you want.

---

## What happens next

Paste the results of the six blocks (or a CSV export of each) and say which option you want for 0.2. Then T1–T6 get built.
