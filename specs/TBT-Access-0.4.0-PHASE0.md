# TBT Access 0.4.0 — Phase 0 kit

The spec says: read-only inventory first, **no code until Mariusz and Claude have read the report**. This file is the kit for that step. Nothing in it changes the site.

Three parts:

1. **SQL** — `specs/TBT-Access-0.4.0-PHASE0.sql`. Every statement is a `SELECT`, apart from a `SET SESSION group_concat_max_len`, which affects only your phpMyAdmin session.
2. **Five minutes in a browser** for the items SQL can't see (0.7, 0.8).
3. **Answers I already found in the code** of Hub, How to say it hub, Lesson Builder and Search Table (0.8, 0.10).

---

## 1. Running the SQL

- phpMyAdmin → the site database → **SQL** tab. Run one block at a time. Each block starts with `-- [0.x]`.
- The queries assume the `wp_` prefix. If `$table_prefix` in `wp-config.php` is different, find-and-replace `wp_` first.
- Run **0.0 first**. The rest use `WITH RECURSIVE`, which needs MySQL 8.0+ or MariaDB 10.2+. If the version is older, send me the number and I'll rewrite the queries without it.
- For **0.2d**, tick *+ Options → Full texts* before you run it, so the whole rules value comes back.
- These results are long: **0.1a, 0.3b, 0.3e, 0.6e**. Use *Export → CSV* for them instead of paging.
- I checked all 27 queries on MariaDB 10.11 against a mock WordPress schema, with mismatched table and connection collations (a common phpMyAdmin setup).

| Block | Spec item | What it answers |
|---|---|---|
| 0.0 | — | Server version |
| 0.1a | 0.1 | Whole category tree: ID, name, parent, own count, posts including subcategories |
| 0.1b / 0.2a | 0.1, 0.2 | Ancestor chain of 808, 773, 498, 1423, 1738, 668, 497, 507, 669, 870, 731, 1881 |
| 0.2b | 0.2 | Posts and pages in more than one definition. Exclusive + library rows = manual-course overlap |
| 0.2c (+ list) | 0.2 | **Stop-and-report number:** active VIP Student / VIP Teacher / Teacher Access members who don't hold the matching manual-course plan, plus their names |
| 0.2d | 0.2 | Memberships' own rules (`wc_memberships_rules`), to confirm which plan reaches which category |
| 0.3a–b | 0.3 | What each seeded definition covers (counts, then every item) |
| 0.3c / 0.5a | 0.3, 0.5 | Do the extra page/post IDs exist, with their types, statuses and titles (7668 and 7680 included) |
| 0.3d–e | 0.3 | **Stop-and-report list:** published posts covered by no definition (per category, then each post) |
| 0.4a | 0.4 | **Hard stop** if any row says `YES - STOP`: account, shop, tool and other key pages vs. definitions |
| 0.4b | 0.4 | Every page a definition covers (should be lesson pages only) |
| 0.4c | 0.4 | Which post types carry categories at all |
| 0.5b | 0.5 | What 1738 "Lucy" and 507 "PRIVATE EYES ONLY" contain |
| 0.6a–f | 0.6 | Memberships statuses, meta keys (end date), counts per plan and status, import totals (~131 / 90), each membership the import would read, Memberships options |
| 0.8a | 0.8 | Whether feeds carry full text or excerpts (`rss_use_excerpt`) |
| 0.9a–c | 0.9 | Where the "UPS!" message is stored: options, posts and Divi layouts, post meta |
| 0.10 | 0.10 | Every database table, to spot any unlock-code table |

Statuses counted as "active" everywhere: `wcm-active`, `wcm-complimentary`, `wcm-free_trial`, `wcm-pending` (Memberships' "pending cancellation"). If 0.6a shows other statuses with live members, tell me.

---

## 2. In a browser (signed out, private window)

**0.7 — how lessons are listed.** Do this on three pages: a lesson **category archive** (e.g. Personal Productivity), the **VIP page**, and the **home page**:
right-click one lesson card → *Inspect* → in the panel, right-click the outermost element of that card (usually `<article …>`) → *Copy → Copy outerHTML*. Paste all three.
What I'm looking for: whether the card's `class` contains `post-12345 type-post category-…`, which means `post_class()` reaches it. Divi's blog module normally does this. A custom grid or shortcode may not.

**0.8 — leaks.** Open each of these and tell me whether you see the **full lesson** or only a short excerpt:

- `/feed/` (0.8a's `rss_use_excerpt` answers this too)
- `/wp-json/wp/v2/posts?categories=669&per_page=1`: look at `"content":{"rendered":…`
- `/?s=` plus a distinctive word from inside a Personal Productivity lesson

**0.9 — the "UPS!" page.** Open a Personal Productivity lesson signed out. Does the URL change (redirect), or does the message appear on the lesson's own URL? Copy the URL you end up on.

---

## 3. Already answered from the code

**0.8 — TBT tools that pull lesson content:**

| Tool | Exposes lesson content? | Why |
|---|---|---|
| TBT Search Table | **Titles, links and tags only**, no body text | `TBTST_Sources::lesson()` reads published posts in its lesson categories with `suppress_filters`, but it outputs only title, URL, level and tags. Titles are public anyway (decision 1). Its write routes require `manage_options`. |
| TBT Lesson Builder | No | All REST routes require `manage_options`. It writes lessons and never displays them publicly. |
| TBT Hub | No | A menu and roles only. It doesn't read posts. |
| How to say it hub | No | Its own REST API serves mind maps and games from its own tables, not WordPress posts. |

So the T3 excerpt filters are needed at most for **feed, core REST and search**, depending on part 2.

**0.10 — How to say it codes.** The `hub-how-to-say-it` plugin unlocks **its own reader app** with a book code. Readers are stored in its own tables, are **not WordPress users**, and the code says outright that a book code never gives access to the rest of the site. Nothing unlocks category 1881. Course 1881 stays manual-grant only, as the spec says.

**0.6 — does deactivating Memberships keep its data?** User memberships and plans are ordinary posts (`wc_user_membership`, `wc_membership_plan`) with post meta. Deactivating a plugin runs no cleanup. Only *Delete* (uninstall) could remove data, and the spec keeps that out of scope. 0.6f lists Memberships' options in case any of them controls uninstall cleanup.

---

## What happens next

Paste the results, or a CSV export of each block, into the conversation. I'll write the Phase 0 report with:

- the 0.2c number and names, for you to confirm or grant by hand
- the 0.3e list of uncovered posts, for you to classify as leak or intentionally open
- the 0.4a hard-stop verdict
- any corrections to the seeded definitions

Once you've read it, T1–T11 get built.
