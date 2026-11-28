-- TBT Access 0.4.3 — Phase 0 checks (READ-ONLY: every statement is a SELECT, except one SET that only affects this phpMyAdmin session).
-- How to run: phpMyAdmin > the site database > SQL tab. Run one block at a time (each starts with -- [0.x]).
-- Table prefix: written for wp_. If yours differs (see wp-config.php $table_prefix), find-and-replace 'wp_' first.
-- "Published post" everywhere = post_type 'post', post_status 'publish'.
-- "With subcategories" = the category itself or any category below it, at any depth.

SET SESSION group_concat_max_len = 100000;

-- ============================================================================
-- [0.2a] Free-flagged lessons (_tbt_access_free = 1), grouped by TOP-LEVEL category.
-- A post filed in two top-level trees is counted under each, so the rows can add up to more than the total in 0.2a-total.
-- If this returns no rows, the "Chcesz najpierw wypróbować lekcję?" block would never show (today's rule).
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
WITH RECURSIVE cat_root AS (
  SELECT tt.term_id, tt.term_id AS root_id, 0 AS lvl
  FROM wp_term_taxonomy tt WHERE tt.taxonomy = 'category' AND tt.parent = 0
  UNION ALL
  SELECT tt.term_id, r.root_id, r.lvl + 1
  FROM cat_root r JOIN wp_term_taxonomy tt ON tt.parent = r.term_id AND tt.taxonomy = 'category'
  WHERE r.lvl < 20
)
SELECT r.root_id                       AS top_level_id,
       t.name                          AS top_level_name,
       COUNT(DISTINCT p.ID)            AS free_lessons,
       GROUP_CONCAT(DISTINCT p.ID ORDER BY p.post_date SEPARATOR ', ') AS post_ids_oldest_first
FROM wp_posts p
JOIN wp_postmeta pm         ON pm.post_id = p.ID AND pm.meta_key = '_tbt_access_free' AND pm.meta_value = '1'
JOIN wp_term_relationships tr ON tr.object_id = p.ID
JOIN wp_term_taxonomy tt    ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
JOIN cat_root r             ON r.term_id = tt.term_id
JOIN wp_terms t             ON t.term_id = r.root_id
WHERE p.post_type = 'post' AND p.post_status = 'publish'
GROUP BY r.root_id, t.name
ORDER BY free_lessons DESC, t.name;

-- ============================================================================
-- [0.2a-total] Free-flagged published posts in total (each post once).
-- ============================================================================
SELECT COUNT(DISTINCT p.ID) AS free_lessons_total
FROM wp_posts p
JOIN wp_postmeta pm ON pm.post_id = p.ID AND pm.meta_key = '_tbt_access_free' AND pm.meta_value = '1'
WHERE p.post_type = 'post' AND p.post_status = 'publish';

-- ============================================================================
-- [0.2b] Published posts in category 947 "darmowa lekcja" (947 itself, not its subcategories): ID, title, date, free flag, and ALL their categories.
-- Oldest first: the order the fallback would use.
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
SELECT p.ID,
       p.post_title,
       p.post_date,
       IF(fm.meta_id IS NULL, 'no', 'yes')  AS free_flag,
       GROUP_CONCAT(DISTINCT CONCAT(t2.term_id, ' ', t2.name) ORDER BY t2.term_id SEPARATOR ' | ') AS all_categories
FROM wp_posts p
JOIN wp_term_relationships tr  ON tr.object_id = p.ID
JOIN wp_term_taxonomy tt       ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category' AND tt.term_id = 947
JOIN wp_term_relationships tr2 ON tr2.object_id = p.ID
JOIN wp_term_taxonomy tt2      ON tt2.term_taxonomy_id = tr2.term_taxonomy_id AND tt2.taxonomy = 'category'
JOIN wp_terms t2               ON t2.term_id = tt2.term_id
LEFT JOIN wp_postmeta fm       ON fm.post_id = p.ID AND fm.meta_key = '_tbt_access_free' AND fm.meta_value = '1'
WHERE p.post_type = 'post' AND p.post_status = 'publish'
GROUP BY p.ID, p.post_title, p.post_date, fm.meta_id
ORDER BY p.post_date ASC, p.ID ASC;

-- ============================================================================
-- [0.2c] (context for the 0.2 decision) The top-level categories that LOCKED lessons sit under, with how many free-flagged lessons each already has.
-- Scope: published posts in 669, 870 or the library categories (668, 497, 498, 507, 1738), with subcategories.
-- free_now = 0 on a row means lessons under that top-level category get no "try" block today.
-- ============================================================================
WITH RECURSIVE cat_root AS (
  SELECT tt.term_id, tt.term_id AS root_id, 0 AS lvl
  FROM wp_term_taxonomy tt WHERE tt.taxonomy = 'category' AND tt.parent = 0
  UNION ALL
  SELECT tt.term_id, r.root_id, r.lvl + 1
  FROM cat_root r JOIN wp_term_taxonomy tt ON tt.parent = r.term_id AND tt.taxonomy = 'category'
  WHERE r.lvl < 20
),
scope AS (
  SELECT tt.term_id, 0 AS lvl FROM wp_term_taxonomy tt
  WHERE tt.taxonomy = 'category' AND tt.term_id IN (669, 870, 668, 497, 498, 507, 1738)
  UNION ALL
  SELECT tt.term_id, s.lvl + 1
  FROM scope s JOIN wp_term_taxonomy tt ON tt.parent = s.term_id AND tt.taxonomy = 'category'
  WHERE s.lvl < 20
),
locked AS (
  SELECT DISTINCT p.ID
  FROM wp_posts p
  JOIN wp_term_relationships tr ON tr.object_id = p.ID
  JOIN wp_term_taxonomy tt      ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
  JOIN scope s                  ON s.term_id = tt.term_id
  WHERE p.post_type = 'post' AND p.post_status = 'publish'
),
roots AS (
  SELECT DISTINCT l.ID, r.root_id
  FROM locked l
  JOIN wp_term_relationships tr ON tr.object_id = l.ID
  JOIN wp_term_taxonomy tt      ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
  JOIN cat_root r               ON r.term_id = tt.term_id
),
free_per_root AS (
  SELECT r.root_id, COUNT(DISTINCT p.ID) AS free_now
  FROM wp_posts p
  JOIN wp_postmeta pm           ON pm.post_id = p.ID AND pm.meta_key = '_tbt_access_free' AND pm.meta_value = '1'
  JOIN wp_term_relationships tr ON tr.object_id = p.ID
  JOIN wp_term_taxonomy tt      ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
  JOIN cat_root r               ON r.term_id = tt.term_id
  WHERE p.post_type = 'post' AND p.post_status = 'publish'
  GROUP BY r.root_id
)
SELECT ro.root_id                 AS top_level_id,
       t.name                     AS top_level_name,
       COUNT(DISTINCT ro.ID)      AS locked_lessons,
       COALESCE(MAX(f.free_now), 0) AS free_now
FROM roots ro
JOIN wp_terms t            ON t.term_id = ro.root_id
LEFT JOIN free_per_root f  ON f.root_id = ro.root_id
GROUP BY ro.root_id, t.name
ORDER BY locked_lessons DESC, t.name;

-- ============================================================================
-- [0.3] Published posts in BOTH 669 (Personal Productivity) and 870 (Man and Society), with subcategories. Expected: 0 rows.
-- ============================================================================
WITH RECURSIVE tree AS (
  SELECT tt.term_id AS course, tt.term_id, 0 AS lvl
  FROM wp_term_taxonomy tt WHERE tt.taxonomy = 'category' AND tt.term_id IN (669, 870)
  UNION ALL
  SELECT t.course, tt.term_id, t.lvl + 1
  FROM tree t JOIN wp_term_taxonomy tt ON tt.parent = t.term_id AND tt.taxonomy = 'category'
  WHERE t.lvl < 20
)
SELECT p.ID, p.post_title
FROM wp_posts p
JOIN wp_term_relationships tr ON tr.object_id = p.ID
JOIN wp_term_taxonomy tt      ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
JOIN tree c                   ON c.term_id = tt.term_id
WHERE p.post_type = 'post' AND p.post_status = 'publish'
GROUP BY p.ID, p.post_title
HAVING COUNT(DISTINCT c.course) = 2
ORDER BY p.ID;

-- ============================================================================
-- [0.4] Manual excerpts: published posts vs posts with a non-empty post_excerpt, per group (with subcategories).
-- "library (all)" counts each post once across 668, 497, 498, 507 and 1738.
-- ============================================================================
WITH RECURSIVE tree AS (
  SELECT tt.term_id AS grp, tt.term_id, 0 AS lvl
  FROM wp_term_taxonomy tt WHERE tt.taxonomy = 'category' AND tt.term_id IN (669, 870, 668, 497, 498, 507, 1738)
  UNION ALL
  SELECT t.grp, tt.term_id, t.lvl + 1
  FROM tree t JOIN wp_term_taxonomy tt ON tt.parent = t.term_id AND tt.taxonomy = 'category'
  WHERE t.lvl < 20
),
posts AS (
  SELECT DISTINCT c.grp, p.ID, IF(TRIM(p.post_excerpt) <> '', 1, 0) AS has_excerpt
  FROM wp_posts p
  JOIN wp_term_relationships tr ON tr.object_id = p.ID
  JOIN wp_term_taxonomy tt      ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
  JOIN tree c                   ON c.term_id = tt.term_id
  WHERE p.post_type = 'post' AND p.post_status = 'publish'
)
SELECT * FROM (
  SELECT CONCAT(x.grp, ' ', t.name) AS grp,
         COUNT(*) AS published, SUM(x.has_excerpt) AS with_excerpt,
         IF(x.grp IN (669, 870), 1, 2) AS sort_key
  FROM posts x JOIN wp_terms t ON t.term_id = x.grp
  GROUP BY x.grp, t.name
  UNION ALL
  SELECT 'library (all)', COUNT(*), SUM(y.has_excerpt), 3
  FROM (SELECT ID, MAX(has_excerpt) AS has_excerpt FROM posts WHERE grp NOT IN (669, 870) GROUP BY ID) y
) out_rows
ORDER BY sort_key, grp;
