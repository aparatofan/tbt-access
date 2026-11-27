-- TBT Access 0.4.0 — Phase 0 inventory (READ-ONLY: every statement is a SELECT, except one SET that only affects this phpMyAdmin session).
-- How to run: phpMyAdmin > the site database > SQL tab. Run one block at a time (each starts with -- [0.x]).
-- Table prefix: written for wp_. If yours differs (see wp-config.php $table_prefix), find-and-replace 'wp_' first.
-- Paste each result back (Export > CSV is fine for the long ones: 0.1a, 0.3b, 0.3e, 0.6e).
-- Membership statuses treated as active: ('wcm-active','wcm-complimentary','wcm-free_trial','wcm-pending')

SET SESSION group_concat_max_len = 100000;

-- ============================================================================
-- [0.0] Server version (tell me if this is older than MySQL 8.0 / MariaDB 10.2: the queries below use WITH RECURSIVE)
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
SELECT VERSION() AS server_version, @@group_concat_max_len AS group_concat_max_len;

-- ============================================================================
-- [0.1a] Full category tree (every category, in tree order). own_count = WordPress's own count; incl_sub = distinct published posts in the category or any subcategory.
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
WITH RECURSIVE
cat_tree AS (
  -- every category paired with itself and each of its descendants
  SELECT tt.term_id AS anc_id, tt.term_id AS des_id, tt.term_taxonomy_id AS des_ttid, 0 AS lvl
  FROM wp_term_taxonomy tt WHERE tt.taxonomy = 'category'
  UNION ALL
  SELECT c.anc_id, tt.term_id, tt.term_taxonomy_id, c.lvl + 1
  FROM cat_tree c
  JOIN wp_term_taxonomy tt ON tt.parent = c.des_id AND tt.taxonomy = 'category'
  WHERE c.lvl < 20
),
walk AS (
  SELECT tt.term_id, tt.parent, 0 AS depth, tt.term_id AS root_id,
         CAST(LPAD(tt.term_id, 7, '0') AS CHAR(400)) AS sort_path
  FROM wp_term_taxonomy tt WHERE tt.taxonomy = 'category' AND tt.parent = 0
  UNION ALL
  SELECT tt.term_id, tt.parent, w.depth + 1, w.root_id,
         CAST(CONCAT(w.sort_path, '/', LPAD(tt.term_id, 7, '0')) AS CHAR(400))
  FROM walk w JOIN wp_term_taxonomy tt ON tt.parent = w.term_id AND tt.taxonomy = 'category'
  WHERE w.depth < 20
),
sub_counts AS (
  SELECT c.anc_id, COUNT(DISTINCT p.ID) AS incl_sub
  FROM cat_tree c
  JOIN wp_term_relationships tr ON tr.term_taxonomy_id = c.des_ttid
  JOIN wp_posts p ON p.ID = tr.object_id AND p.post_status = 'publish'
  GROUP BY c.anc_id
)
SELECT w.root_id, w.depth, w.term_id, w.parent,
       CONCAT(REPEAT('. . ', w.depth), t.name) AS name,
       t.slug, tt.count AS own_count, COALESCE(s.incl_sub, 0) AS incl_sub
FROM walk w
JOIN wp_terms t ON t.term_id = w.term_id
JOIN wp_term_taxonomy tt ON tt.term_id = w.term_id AND tt.taxonomy = 'category'
LEFT JOIN sub_counts s ON s.anc_id = w.term_id
ORDER BY w.sort_path;

-- ============================================================================
-- [0.1b] / 0.2a Ancestor chain of every category the spec names (808 and 773 included). An ID missing from the result is not a category.
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
WITH RECURSIVE up AS (
  SELECT tt.term_id AS start_id, tt.term_id AS cur_id, tt.parent, 0 AS lvl
  FROM wp_term_taxonomy tt
  WHERE tt.taxonomy = 'category'
    AND tt.term_id IN (808, 773, 498, 1423, 1738, 668, 497, 507, 669, 870, 731, 1881)
  UNION ALL
  SELECT u.start_id, tt.term_id, tt.parent, u.lvl + 1
  FROM up u JOIN wp_term_taxonomy tt ON tt.term_id = u.parent AND tt.taxonomy = 'category'
  WHERE u.lvl < 20
)
SELECT u.start_id, s.name AS start_name,
       GROUP_CONCAT(CONCAT(t.name, ' (', t.term_id, ')') ORDER BY u.lvl DESC SEPARATOR '  >  ') AS chain_from_top
FROM up u
JOIN wp_terms t ON t.term_id = u.cur_id
JOIN wp_terms s ON s.term_id = u.start_id
GROUP BY u.start_id, s.name
ORDER BY u.start_id;

-- ============================================================================
-- [0.2b] Posts and pages that belong to more than one definition (any status). Rows with an exclusive key AND library are the manual-course overlap.
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
WITH RECURSIVE
defs AS (
  SELECT 'library' AS dkey, 'cat' AS kind, 668 AS obj_id, 0 AS exclusive
  UNION ALL SELECT 'library','cat',497,0
  UNION ALL SELECT 'library','cat',498,0
  UNION ALL SELECT 'library','cat',507,0
  UNION ALL SELECT 'library','cat',1738,0
  UNION ALL SELECT 'library','page',735,0
  UNION ALL SELECT 'library','page',803,0
  UNION ALL SELECT 'library','page',11160,0
  UNION ALL SELECT 'library','page',11231,0
  UNION ALL SELECT 'library','page',11246,0
  UNION ALL SELECT 'library','page',11252,0
  UNION ALL SELECT 'library','page',11262,0
  UNION ALL SELECT 'library','page',11269,0
  UNION ALL SELECT 'library','page',11274,0
  UNION ALL SELECT 'library','page',11283,0
  UNION ALL SELECT 'library','page',11290,0
  UNION ALL SELECT 'library','page',11297,0
  UNION ALL SELECT 'library','page',11303,0
  UNION ALL SELECT 'library','page',11311,0
  UNION ALL SELECT 'library','page',11334,0
  UNION ALL SELECT 'library','page',11343,0
  UNION ALL SELECT 'library','page',11352,0
  UNION ALL SELECT 'library','page',11360,0
  UNION ALL SELECT 'library','page',11370,0
  UNION ALL SELECT 'library','page',11396,0
  UNION ALL SELECT 'library','page',12750,0
  UNION ALL SELECT 'library','page',14982,0
  UNION ALL SELECT 'library','page',7668,0
  UNION ALL SELECT 'library','page',7680,0
  UNION ALL SELECT 'course-personal-productivity','cat',669,0
  UNION ALL SELECT 'course-man-and-society','cat',870,0
  UNION ALL SELECT 'course-persuasive-presentations','cat',731,1
  UNION ALL SELECT 'course-persuasive-presentations','page',11830,1
  UNION ALL SELECT 'course-persuasive-presentations','post',11861,1
  UNION ALL SELECT 'course-graphic-designers','cat',1423,1
  UNION ALL SELECT 'course-graphic-designers','page',37315,1
  UNION ALL SELECT 'course-graphic-designers','page',225614,1
  UNION ALL SELECT 'course-graphic-designers','page',225646,1
  UNION ALL SELECT 'course-graphic-designers','page',226096,1
  UNION ALL SELECT 'course-graphic-designers','post',37256,1
  UNION ALL SELECT 'course-how-to-say-it','cat',1881,1
),
cat_tree AS (
  -- every category paired with itself and each of its descendants
  SELECT tt.term_id AS anc_id, tt.term_id AS des_id, tt.term_taxonomy_id AS des_ttid, 0 AS lvl
  FROM wp_term_taxonomy tt WHERE tt.taxonomy = 'category'
  UNION ALL
  SELECT c.anc_id, tt.term_id, tt.term_taxonomy_id, c.lvl + 1
  FROM cat_tree c
  JOIN wp_term_taxonomy tt ON tt.parent = c.des_id AND tt.taxonomy = 'category'
  WHERE c.lvl < 20
),
covered AS (
  -- (definition, post/page) pairs, the way the T2 resolver will compute them
  SELECT DISTINCT d.dkey, d.exclusive, tr.object_id AS post_id
  FROM defs d
  JOIN cat_tree c ON c.anc_id = d.obj_id
  JOIN wp_term_relationships tr ON tr.term_taxonomy_id = c.des_ttid
  WHERE d.kind = 'cat'
  UNION
  SELECT d.dkey, d.exclusive, d.obj_id FROM defs d WHERE d.kind IN ('page','post')
),
post_cats AS (
  SELECT tr.object_id AS post_id,
         GROUP_CONCAT(CONCAT(t.name, ' (', t.term_id, ')') ORDER BY t.term_id SEPARATOR ', ') AS cats
  FROM wp_term_relationships tr
  JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
  JOIN wp_terms t ON t.term_id = tt.term_id
  GROUP BY tr.object_id
)
SELECT p.ID, p.post_type, p.post_status, p.post_title,
       GROUP_CONCAT(cv.dkey ORDER BY cv.dkey SEPARATOR ', ') AS definitions,
       MAX(cv.exclusive) AS touches_exclusive,
       pc.cats
FROM covered cv
JOIN wp_posts p ON p.ID = cv.post_id
LEFT JOIN post_cats pc ON pc.post_id = p.ID
WHERE p.post_type IN ('post','page')
GROUP BY p.ID, p.post_type, p.post_status, p.post_title, pc.cats
HAVING COUNT(*) > 1
ORDER BY touches_exclusive DESC, p.ID;

-- ============================================================================
-- [0.2c] Members of VIP Student (10988) / VIP Teacher (28834) / Teacher Access (28429) with an active membership who do NOT hold the matching manual-course plan. These are the people decision 5 could take access away from (only where 0.2a/0.2b/0.2d show the plan really reaches that course).
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
WITH course_plans AS (
  SELECT 'course-persuasive-presentations' AS course, 11815 AS plan_id
  UNION ALL SELECT 'course-graphic-designers', 37312
  UNION ALL SELECT 'course-how-to-say-it', 366754
),
lib AS (
  SELECT DISTINCT m.post_author AS user_id, m.post_parent AS plan_id
  FROM wp_posts m
  WHERE m.post_type = 'wc_user_membership' AND m.post_status IN ('wcm-active','wcm-complimentary','wcm-free_trial','wcm-pending')
    AND m.post_parent IN (10988, 28834, 28429)
)
SELECT cp.course, lib.plan_id, pl.post_title AS library_plan, COUNT(DISTINCT lib.user_id) AS users_without_course_plan
FROM course_plans cp
CROSS JOIN lib
JOIN wp_posts pl ON pl.ID = lib.plan_id
WHERE NOT EXISTS (
  SELECT 1 FROM wp_posts m2
  WHERE m2.post_type = 'wc_user_membership' AND m2.post_status IN ('wcm-active','wcm-complimentary','wcm-free_trial','wcm-pending')
    AND m2.post_parent = cp.plan_id AND m2.post_author = lib.user_id
)
GROUP BY cp.course, lib.plan_id, pl.post_title
ORDER BY cp.course, lib.plan_id;

-- ============================================================================
-- [0.2c] (list) The same people, one row per user and manual course, so you can decide who gets the course by hand.
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
WITH course_plans AS (
  SELECT 'course-persuasive-presentations' AS course, 11815 AS plan_id
  UNION ALL SELECT 'course-graphic-designers', 37312
  UNION ALL SELECT 'course-how-to-say-it', 366754
),
lib AS (
  SELECT m.post_author AS user_id,
         GROUP_CONCAT(DISTINCT pl.post_title ORDER BY pl.ID SEPARATOR ', ') AS library_plans
  FROM wp_posts m JOIN wp_posts pl ON pl.ID = m.post_parent
  WHERE m.post_type = 'wc_user_membership' AND m.post_status IN ('wcm-active','wcm-complimentary','wcm-free_trial','wcm-pending')
    AND m.post_parent IN (10988, 28834, 28429)
  GROUP BY m.post_author
)
SELECT cp.course, u.ID AS user_id, u.user_email, u.display_name, lib.library_plans
FROM course_plans cp
CROSS JOIN lib
JOIN wp_users u ON u.ID = lib.user_id
WHERE NOT EXISTS (
  SELECT 1 FROM wp_posts m2
  WHERE m2.post_type = 'wc_user_membership' AND m2.post_status IN ('wcm-active','wcm-complimentary','wcm-free_trial','wcm-pending')
    AND m2.post_parent = cp.plan_id AND m2.post_author = lib.user_id
)
ORDER BY cp.course, u.user_email;

-- ============================================================================
-- [0.2d] Memberships' own content rules, to confirm which plan reaches which category. Tick '+ Options > Full texts' in phpMyAdmin before running, then copy the whole value.
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
SELECT option_name, LENGTH(option_value) AS bytes, option_value FROM wp_options WHERE option_name = 'wc_memberships_rules';

-- ============================================================================
-- [0.3a] How much each seeded definition covers (published only).
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
WITH RECURSIVE
defs AS (
  SELECT 'library' AS dkey, 'cat' AS kind, 668 AS obj_id, 0 AS exclusive
  UNION ALL SELECT 'library','cat',497,0
  UNION ALL SELECT 'library','cat',498,0
  UNION ALL SELECT 'library','cat',507,0
  UNION ALL SELECT 'library','cat',1738,0
  UNION ALL SELECT 'library','page',735,0
  UNION ALL SELECT 'library','page',803,0
  UNION ALL SELECT 'library','page',11160,0
  UNION ALL SELECT 'library','page',11231,0
  UNION ALL SELECT 'library','page',11246,0
  UNION ALL SELECT 'library','page',11252,0
  UNION ALL SELECT 'library','page',11262,0
  UNION ALL SELECT 'library','page',11269,0
  UNION ALL SELECT 'library','page',11274,0
  UNION ALL SELECT 'library','page',11283,0
  UNION ALL SELECT 'library','page',11290,0
  UNION ALL SELECT 'library','page',11297,0
  UNION ALL SELECT 'library','page',11303,0
  UNION ALL SELECT 'library','page',11311,0
  UNION ALL SELECT 'library','page',11334,0
  UNION ALL SELECT 'library','page',11343,0
  UNION ALL SELECT 'library','page',11352,0
  UNION ALL SELECT 'library','page',11360,0
  UNION ALL SELECT 'library','page',11370,0
  UNION ALL SELECT 'library','page',11396,0
  UNION ALL SELECT 'library','page',12750,0
  UNION ALL SELECT 'library','page',14982,0
  UNION ALL SELECT 'library','page',7668,0
  UNION ALL SELECT 'library','page',7680,0
  UNION ALL SELECT 'course-personal-productivity','cat',669,0
  UNION ALL SELECT 'course-man-and-society','cat',870,0
  UNION ALL SELECT 'course-persuasive-presentations','cat',731,1
  UNION ALL SELECT 'course-persuasive-presentations','page',11830,1
  UNION ALL SELECT 'course-persuasive-presentations','post',11861,1
  UNION ALL SELECT 'course-graphic-designers','cat',1423,1
  UNION ALL SELECT 'course-graphic-designers','page',37315,1
  UNION ALL SELECT 'course-graphic-designers','page',225614,1
  UNION ALL SELECT 'course-graphic-designers','page',225646,1
  UNION ALL SELECT 'course-graphic-designers','page',226096,1
  UNION ALL SELECT 'course-graphic-designers','post',37256,1
  UNION ALL SELECT 'course-how-to-say-it','cat',1881,1
),
cat_tree AS (
  -- every category paired with itself and each of its descendants
  SELECT tt.term_id AS anc_id, tt.term_id AS des_id, tt.term_taxonomy_id AS des_ttid, 0 AS lvl
  FROM wp_term_taxonomy tt WHERE tt.taxonomy = 'category'
  UNION ALL
  SELECT c.anc_id, tt.term_id, tt.term_taxonomy_id, c.lvl + 1
  FROM cat_tree c
  JOIN wp_term_taxonomy tt ON tt.parent = c.des_id AND tt.taxonomy = 'category'
  WHERE c.lvl < 20
),
covered AS (
  -- (definition, post/page) pairs, the way the T2 resolver will compute them
  SELECT DISTINCT d.dkey, d.exclusive, tr.object_id AS post_id
  FROM defs d
  JOIN cat_tree c ON c.anc_id = d.obj_id
  JOIN wp_term_relationships tr ON tr.term_taxonomy_id = c.des_ttid
  WHERE d.kind = 'cat'
  UNION
  SELECT d.dkey, d.exclusive, d.obj_id FROM defs d WHERE d.kind IN ('page','post')
)
SELECT cv.dkey, p.post_type, COUNT(*) AS published_items
FROM covered cv JOIN wp_posts p ON p.ID = cv.post_id
WHERE p.post_status = 'publish'
GROUP BY cv.dkey, p.post_type
ORDER BY cv.dkey, p.post_type;

-- ============================================================================
-- [0.3b] Every published post and page each definition covers. Large: use Export > CSV rather than paging.
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
WITH RECURSIVE
defs AS (
  SELECT 'library' AS dkey, 'cat' AS kind, 668 AS obj_id, 0 AS exclusive
  UNION ALL SELECT 'library','cat',497,0
  UNION ALL SELECT 'library','cat',498,0
  UNION ALL SELECT 'library','cat',507,0
  UNION ALL SELECT 'library','cat',1738,0
  UNION ALL SELECT 'library','page',735,0
  UNION ALL SELECT 'library','page',803,0
  UNION ALL SELECT 'library','page',11160,0
  UNION ALL SELECT 'library','page',11231,0
  UNION ALL SELECT 'library','page',11246,0
  UNION ALL SELECT 'library','page',11252,0
  UNION ALL SELECT 'library','page',11262,0
  UNION ALL SELECT 'library','page',11269,0
  UNION ALL SELECT 'library','page',11274,0
  UNION ALL SELECT 'library','page',11283,0
  UNION ALL SELECT 'library','page',11290,0
  UNION ALL SELECT 'library','page',11297,0
  UNION ALL SELECT 'library','page',11303,0
  UNION ALL SELECT 'library','page',11311,0
  UNION ALL SELECT 'library','page',11334,0
  UNION ALL SELECT 'library','page',11343,0
  UNION ALL SELECT 'library','page',11352,0
  UNION ALL SELECT 'library','page',11360,0
  UNION ALL SELECT 'library','page',11370,0
  UNION ALL SELECT 'library','page',11396,0
  UNION ALL SELECT 'library','page',12750,0
  UNION ALL SELECT 'library','page',14982,0
  UNION ALL SELECT 'library','page',7668,0
  UNION ALL SELECT 'library','page',7680,0
  UNION ALL SELECT 'course-personal-productivity','cat',669,0
  UNION ALL SELECT 'course-man-and-society','cat',870,0
  UNION ALL SELECT 'course-persuasive-presentations','cat',731,1
  UNION ALL SELECT 'course-persuasive-presentations','page',11830,1
  UNION ALL SELECT 'course-persuasive-presentations','post',11861,1
  UNION ALL SELECT 'course-graphic-designers','cat',1423,1
  UNION ALL SELECT 'course-graphic-designers','page',37315,1
  UNION ALL SELECT 'course-graphic-designers','page',225614,1
  UNION ALL SELECT 'course-graphic-designers','page',225646,1
  UNION ALL SELECT 'course-graphic-designers','page',226096,1
  UNION ALL SELECT 'course-graphic-designers','post',37256,1
  UNION ALL SELECT 'course-how-to-say-it','cat',1881,1
),
cat_tree AS (
  -- every category paired with itself and each of its descendants
  SELECT tt.term_id AS anc_id, tt.term_id AS des_id, tt.term_taxonomy_id AS des_ttid, 0 AS lvl
  FROM wp_term_taxonomy tt WHERE tt.taxonomy = 'category'
  UNION ALL
  SELECT c.anc_id, tt.term_id, tt.term_taxonomy_id, c.lvl + 1
  FROM cat_tree c
  JOIN wp_term_taxonomy tt ON tt.parent = c.des_id AND tt.taxonomy = 'category'
  WHERE c.lvl < 20
),
covered AS (
  -- (definition, post/page) pairs, the way the T2 resolver will compute them
  SELECT DISTINCT d.dkey, d.exclusive, tr.object_id AS post_id
  FROM defs d
  JOIN cat_tree c ON c.anc_id = d.obj_id
  JOIN wp_term_relationships tr ON tr.term_taxonomy_id = c.des_ttid
  WHERE d.kind = 'cat'
  UNION
  SELECT d.dkey, d.exclusive, d.obj_id FROM defs d WHERE d.kind IN ('page','post')
),
post_cats AS (
  SELECT tr.object_id AS post_id,
         GROUP_CONCAT(CONCAT(t.name, ' (', t.term_id, ')') ORDER BY t.term_id SEPARATOR ', ') AS cats
  FROM wp_term_relationships tr
  JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
  JOIN wp_terms t ON t.term_id = tt.term_id
  GROUP BY tr.object_id
)
SELECT cv.dkey, p.ID, p.post_type, p.post_title, pc.cats
FROM covered cv
JOIN wp_posts p ON p.ID = cv.post_id
LEFT JOIN post_cats pc ON pc.post_id = p.ID
WHERE p.post_status = 'publish'
ORDER BY cv.dkey, p.post_type, p.ID;

-- ============================================================================
-- [0.3c] / 0.5a The extra page and post IDs in the definitions: do they exist, what type, what status, what title (7668 and 7680 included).
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
WITH RECURSIVE
defs AS (
  SELECT 'library' AS dkey, 'cat' AS kind, 668 AS obj_id, 0 AS exclusive
  UNION ALL SELECT 'library','cat',497,0
  UNION ALL SELECT 'library','cat',498,0
  UNION ALL SELECT 'library','cat',507,0
  UNION ALL SELECT 'library','cat',1738,0
  UNION ALL SELECT 'library','page',735,0
  UNION ALL SELECT 'library','page',803,0
  UNION ALL SELECT 'library','page',11160,0
  UNION ALL SELECT 'library','page',11231,0
  UNION ALL SELECT 'library','page',11246,0
  UNION ALL SELECT 'library','page',11252,0
  UNION ALL SELECT 'library','page',11262,0
  UNION ALL SELECT 'library','page',11269,0
  UNION ALL SELECT 'library','page',11274,0
  UNION ALL SELECT 'library','page',11283,0
  UNION ALL SELECT 'library','page',11290,0
  UNION ALL SELECT 'library','page',11297,0
  UNION ALL SELECT 'library','page',11303,0
  UNION ALL SELECT 'library','page',11311,0
  UNION ALL SELECT 'library','page',11334,0
  UNION ALL SELECT 'library','page',11343,0
  UNION ALL SELECT 'library','page',11352,0
  UNION ALL SELECT 'library','page',11360,0
  UNION ALL SELECT 'library','page',11370,0
  UNION ALL SELECT 'library','page',11396,0
  UNION ALL SELECT 'library','page',12750,0
  UNION ALL SELECT 'library','page',14982,0
  UNION ALL SELECT 'library','page',7668,0
  UNION ALL SELECT 'library','page',7680,0
  UNION ALL SELECT 'course-personal-productivity','cat',669,0
  UNION ALL SELECT 'course-man-and-society','cat',870,0
  UNION ALL SELECT 'course-persuasive-presentations','cat',731,1
  UNION ALL SELECT 'course-persuasive-presentations','page',11830,1
  UNION ALL SELECT 'course-persuasive-presentations','post',11861,1
  UNION ALL SELECT 'course-graphic-designers','cat',1423,1
  UNION ALL SELECT 'course-graphic-designers','page',37315,1
  UNION ALL SELECT 'course-graphic-designers','page',225614,1
  UNION ALL SELECT 'course-graphic-designers','page',225646,1
  UNION ALL SELECT 'course-graphic-designers','page',226096,1
  UNION ALL SELECT 'course-graphic-designers','post',37256,1
  UNION ALL SELECT 'course-how-to-say-it','cat',1881,1
)
SELECT d.dkey, d.kind AS listed_as, d.obj_id, p.post_type, p.post_status, p.post_name AS slug, p.post_title,
       CASE WHEN p.ID IS NULL THEN 'MISSING'
            WHEN p.post_type <> d.kind THEN 'TYPE DIFFERS'
            ELSE '' END AS problem
FROM defs d LEFT JOIN wp_posts p ON p.ID = d.obj_id
WHERE d.kind IN ('page','post')
ORDER BY d.dkey, d.obj_id;

-- ============================================================================
-- [0.3d] Published posts covered by NO definition, counted per category (a post in two categories appears under both).
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
WITH RECURSIVE
defs AS (
  SELECT 'library' AS dkey, 'cat' AS kind, 668 AS obj_id, 0 AS exclusive
  UNION ALL SELECT 'library','cat',497,0
  UNION ALL SELECT 'library','cat',498,0
  UNION ALL SELECT 'library','cat',507,0
  UNION ALL SELECT 'library','cat',1738,0
  UNION ALL SELECT 'library','page',735,0
  UNION ALL SELECT 'library','page',803,0
  UNION ALL SELECT 'library','page',11160,0
  UNION ALL SELECT 'library','page',11231,0
  UNION ALL SELECT 'library','page',11246,0
  UNION ALL SELECT 'library','page',11252,0
  UNION ALL SELECT 'library','page',11262,0
  UNION ALL SELECT 'library','page',11269,0
  UNION ALL SELECT 'library','page',11274,0
  UNION ALL SELECT 'library','page',11283,0
  UNION ALL SELECT 'library','page',11290,0
  UNION ALL SELECT 'library','page',11297,0
  UNION ALL SELECT 'library','page',11303,0
  UNION ALL SELECT 'library','page',11311,0
  UNION ALL SELECT 'library','page',11334,0
  UNION ALL SELECT 'library','page',11343,0
  UNION ALL SELECT 'library','page',11352,0
  UNION ALL SELECT 'library','page',11360,0
  UNION ALL SELECT 'library','page',11370,0
  UNION ALL SELECT 'library','page',11396,0
  UNION ALL SELECT 'library','page',12750,0
  UNION ALL SELECT 'library','page',14982,0
  UNION ALL SELECT 'library','page',7668,0
  UNION ALL SELECT 'library','page',7680,0
  UNION ALL SELECT 'course-personal-productivity','cat',669,0
  UNION ALL SELECT 'course-man-and-society','cat',870,0
  UNION ALL SELECT 'course-persuasive-presentations','cat',731,1
  UNION ALL SELECT 'course-persuasive-presentations','page',11830,1
  UNION ALL SELECT 'course-persuasive-presentations','post',11861,1
  UNION ALL SELECT 'course-graphic-designers','cat',1423,1
  UNION ALL SELECT 'course-graphic-designers','page',37315,1
  UNION ALL SELECT 'course-graphic-designers','page',225614,1
  UNION ALL SELECT 'course-graphic-designers','page',225646,1
  UNION ALL SELECT 'course-graphic-designers','page',226096,1
  UNION ALL SELECT 'course-graphic-designers','post',37256,1
  UNION ALL SELECT 'course-how-to-say-it','cat',1881,1
),
cat_tree AS (
  -- every category paired with itself and each of its descendants
  SELECT tt.term_id AS anc_id, tt.term_id AS des_id, tt.term_taxonomy_id AS des_ttid, 0 AS lvl
  FROM wp_term_taxonomy tt WHERE tt.taxonomy = 'category'
  UNION ALL
  SELECT c.anc_id, tt.term_id, tt.term_taxonomy_id, c.lvl + 1
  FROM cat_tree c
  JOIN wp_term_taxonomy tt ON tt.parent = c.des_id AND tt.taxonomy = 'category'
  WHERE c.lvl < 20
),
covered AS (
  -- (definition, post/page) pairs, the way the T2 resolver will compute them
  SELECT DISTINCT d.dkey, d.exclusive, tr.object_id AS post_id
  FROM defs d
  JOIN cat_tree c ON c.anc_id = d.obj_id
  JOIN wp_term_relationships tr ON tr.term_taxonomy_id = c.des_ttid
  WHERE d.kind = 'cat'
  UNION
  SELECT d.dkey, d.exclusive, d.obj_id FROM defs d WHERE d.kind IN ('page','post')
)
SELECT t.term_id, t.name, tt.parent, COUNT(DISTINCT p.ID) AS uncovered_posts
FROM wp_posts p
JOIN wp_term_relationships tr ON tr.object_id = p.ID
JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
JOIN wp_terms t ON t.term_id = tt.term_id
WHERE p.post_type = 'post' AND p.post_status = 'publish'
  AND NOT EXISTS (SELECT 1 FROM covered cv WHERE cv.post_id = p.ID)
GROUP BY t.term_id, t.name, tt.parent
ORDER BY uncovered_posts DESC;

-- ============================================================================
-- [0.3e] The uncovered published posts themselves: each one is either a leak or an intentionally open page.
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
WITH RECURSIVE
defs AS (
  SELECT 'library' AS dkey, 'cat' AS kind, 668 AS obj_id, 0 AS exclusive
  UNION ALL SELECT 'library','cat',497,0
  UNION ALL SELECT 'library','cat',498,0
  UNION ALL SELECT 'library','cat',507,0
  UNION ALL SELECT 'library','cat',1738,0
  UNION ALL SELECT 'library','page',735,0
  UNION ALL SELECT 'library','page',803,0
  UNION ALL SELECT 'library','page',11160,0
  UNION ALL SELECT 'library','page',11231,0
  UNION ALL SELECT 'library','page',11246,0
  UNION ALL SELECT 'library','page',11252,0
  UNION ALL SELECT 'library','page',11262,0
  UNION ALL SELECT 'library','page',11269,0
  UNION ALL SELECT 'library','page',11274,0
  UNION ALL SELECT 'library','page',11283,0
  UNION ALL SELECT 'library','page',11290,0
  UNION ALL SELECT 'library','page',11297,0
  UNION ALL SELECT 'library','page',11303,0
  UNION ALL SELECT 'library','page',11311,0
  UNION ALL SELECT 'library','page',11334,0
  UNION ALL SELECT 'library','page',11343,0
  UNION ALL SELECT 'library','page',11352,0
  UNION ALL SELECT 'library','page',11360,0
  UNION ALL SELECT 'library','page',11370,0
  UNION ALL SELECT 'library','page',11396,0
  UNION ALL SELECT 'library','page',12750,0
  UNION ALL SELECT 'library','page',14982,0
  UNION ALL SELECT 'library','page',7668,0
  UNION ALL SELECT 'library','page',7680,0
  UNION ALL SELECT 'course-personal-productivity','cat',669,0
  UNION ALL SELECT 'course-man-and-society','cat',870,0
  UNION ALL SELECT 'course-persuasive-presentations','cat',731,1
  UNION ALL SELECT 'course-persuasive-presentations','page',11830,1
  UNION ALL SELECT 'course-persuasive-presentations','post',11861,1
  UNION ALL SELECT 'course-graphic-designers','cat',1423,1
  UNION ALL SELECT 'course-graphic-designers','page',37315,1
  UNION ALL SELECT 'course-graphic-designers','page',225614,1
  UNION ALL SELECT 'course-graphic-designers','page',225646,1
  UNION ALL SELECT 'course-graphic-designers','page',226096,1
  UNION ALL SELECT 'course-graphic-designers','post',37256,1
  UNION ALL SELECT 'course-how-to-say-it','cat',1881,1
),
cat_tree AS (
  -- every category paired with itself and each of its descendants
  SELECT tt.term_id AS anc_id, tt.term_id AS des_id, tt.term_taxonomy_id AS des_ttid, 0 AS lvl
  FROM wp_term_taxonomy tt WHERE tt.taxonomy = 'category'
  UNION ALL
  SELECT c.anc_id, tt.term_id, tt.term_taxonomy_id, c.lvl + 1
  FROM cat_tree c
  JOIN wp_term_taxonomy tt ON tt.parent = c.des_id AND tt.taxonomy = 'category'
  WHERE c.lvl < 20
),
covered AS (
  -- (definition, post/page) pairs, the way the T2 resolver will compute them
  SELECT DISTINCT d.dkey, d.exclusive, tr.object_id AS post_id
  FROM defs d
  JOIN cat_tree c ON c.anc_id = d.obj_id
  JOIN wp_term_relationships tr ON tr.term_taxonomy_id = c.des_ttid
  WHERE d.kind = 'cat'
  UNION
  SELECT d.dkey, d.exclusive, d.obj_id FROM defs d WHERE d.kind IN ('page','post')
),
post_cats AS (
  SELECT tr.object_id AS post_id,
         GROUP_CONCAT(CONCAT(t.name, ' (', t.term_id, ')') ORDER BY t.term_id SEPARATOR ', ') AS cats
  FROM wp_term_relationships tr
  JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
  JOIN wp_terms t ON t.term_id = tt.term_id
  GROUP BY tr.object_id
)
SELECT p.ID, p.post_date, p.post_title, pc.cats
FROM wp_posts p
LEFT JOIN post_cats pc ON pc.post_id = p.ID
WHERE p.post_type = 'post' AND p.post_status = 'publish'
  AND NOT EXISTS (SELECT 1 FROM covered cv WHERE cv.post_id = p.ID)
ORDER BY pc.cats, p.ID;

-- ============================================================================
-- [0.4a] HARD-STOP CHECK. Account, shop, tool and other key pages, with whether any definition covers them. Every row must say 'no' in in_definition.
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
WITH RECURSIVE
defs AS (
  SELECT 'library' AS dkey, 'cat' AS kind, 668 AS obj_id, 0 AS exclusive
  UNION ALL SELECT 'library','cat',497,0
  UNION ALL SELECT 'library','cat',498,0
  UNION ALL SELECT 'library','cat',507,0
  UNION ALL SELECT 'library','cat',1738,0
  UNION ALL SELECT 'library','page',735,0
  UNION ALL SELECT 'library','page',803,0
  UNION ALL SELECT 'library','page',11160,0
  UNION ALL SELECT 'library','page',11231,0
  UNION ALL SELECT 'library','page',11246,0
  UNION ALL SELECT 'library','page',11252,0
  UNION ALL SELECT 'library','page',11262,0
  UNION ALL SELECT 'library','page',11269,0
  UNION ALL SELECT 'library','page',11274,0
  UNION ALL SELECT 'library','page',11283,0
  UNION ALL SELECT 'library','page',11290,0
  UNION ALL SELECT 'library','page',11297,0
  UNION ALL SELECT 'library','page',11303,0
  UNION ALL SELECT 'library','page',11311,0
  UNION ALL SELECT 'library','page',11334,0
  UNION ALL SELECT 'library','page',11343,0
  UNION ALL SELECT 'library','page',11352,0
  UNION ALL SELECT 'library','page',11360,0
  UNION ALL SELECT 'library','page',11370,0
  UNION ALL SELECT 'library','page',11396,0
  UNION ALL SELECT 'library','page',12750,0
  UNION ALL SELECT 'library','page',14982,0
  UNION ALL SELECT 'library','page',7668,0
  UNION ALL SELECT 'library','page',7680,0
  UNION ALL SELECT 'course-personal-productivity','cat',669,0
  UNION ALL SELECT 'course-man-and-society','cat',870,0
  UNION ALL SELECT 'course-persuasive-presentations','cat',731,1
  UNION ALL SELECT 'course-persuasive-presentations','page',11830,1
  UNION ALL SELECT 'course-persuasive-presentations','post',11861,1
  UNION ALL SELECT 'course-graphic-designers','cat',1423,1
  UNION ALL SELECT 'course-graphic-designers','page',37315,1
  UNION ALL SELECT 'course-graphic-designers','page',225614,1
  UNION ALL SELECT 'course-graphic-designers','page',225646,1
  UNION ALL SELECT 'course-graphic-designers','page',226096,1
  UNION ALL SELECT 'course-graphic-designers','post',37256,1
  UNION ALL SELECT 'course-how-to-say-it','cat',1881,1
),
cat_tree AS (
  -- every category paired with itself and each of its descendants
  SELECT tt.term_id AS anc_id, tt.term_id AS des_id, tt.term_taxonomy_id AS des_ttid, 0 AS lvl
  FROM wp_term_taxonomy tt WHERE tt.taxonomy = 'category'
  UNION ALL
  SELECT c.anc_id, tt.term_id, tt.term_taxonomy_id, c.lvl + 1
  FROM cat_tree c
  JOIN wp_term_taxonomy tt ON tt.parent = c.des_id AND tt.taxonomy = 'category'
  WHERE c.lvl < 20
),
covered AS (
  -- (definition, post/page) pairs, the way the T2 resolver will compute them
  SELECT DISTINCT d.dkey, d.exclusive, tr.object_id AS post_id
  FROM defs d
  JOIN cat_tree c ON c.anc_id = d.obj_id
  JOIN wp_term_relationships tr ON tr.term_taxonomy_id = c.des_ttid
  WHERE d.kind = 'cat'
  UNION
  SELECT d.dkey, d.exclusive, d.obj_id FROM defs d WHERE d.kind IN ('page','post')
)
SELECT p.ID, p.post_type, p.post_status, p.post_name AS slug, p.post_title,
       GROUP_CONCAT(DISTINCT o.option_name) AS is_option_page,
       CASE WHEN EXISTS (SELECT 1 FROM covered cv WHERE cv.post_id = p.ID)
            THEN 'YES - STOP' ELSE 'no' END AS in_definition
FROM wp_posts p
LEFT JOIN wp_options o ON o.option_value REGEXP '^[0-9]+$' AND o.option_value + 0 = p.ID
     AND o.option_name IN ('woocommerce_myaccount_page_id','woocommerce_checkout_page_id',
                           'woocommerce_cart_page_id','woocommerce_shop_page_id',
                           'woocommerce_terms_page_id','page_on_front','page_for_posts',
                           'wp_page_for_privacy_policy')
WHERE p.post_type = 'page' AND p.post_status IN ('publish','private')
  AND ( o.option_name IS NOT NULL
     OR p.post_name IN ('vip','do-zobaczenia','my-account','moje-konto','checkout','zamowienie',
                        'cart','koszyk','shop','sklep','welcome')
     OR p.post_name LIKE '%note%' OR p.post_name LIKE '%student%' OR p.post_name LIKE '%teacher%'
     OR p.post_name LIKE '%tool%' OR p.post_name LIKE '%hub%' OR p.post_name LIKE '%points%'
     OR p.post_name LIKE '%result%' OR p.post_name LIKE '%homework%'
     OR p.post_content LIKE '%[tbt%' OR p.post_content LIKE '%[htsi%' )
GROUP BY p.ID, p.post_type, p.post_status, p.post_name, p.post_title
ORDER BY in_definition DESC, p.post_name;

-- ============================================================================
-- [0.4b] Every page any definition covers (should be lesson pages only). Look for anything that is not a lesson.
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
WITH RECURSIVE
defs AS (
  SELECT 'library' AS dkey, 'cat' AS kind, 668 AS obj_id, 0 AS exclusive
  UNION ALL SELECT 'library','cat',497,0
  UNION ALL SELECT 'library','cat',498,0
  UNION ALL SELECT 'library','cat',507,0
  UNION ALL SELECT 'library','cat',1738,0
  UNION ALL SELECT 'library','page',735,0
  UNION ALL SELECT 'library','page',803,0
  UNION ALL SELECT 'library','page',11160,0
  UNION ALL SELECT 'library','page',11231,0
  UNION ALL SELECT 'library','page',11246,0
  UNION ALL SELECT 'library','page',11252,0
  UNION ALL SELECT 'library','page',11262,0
  UNION ALL SELECT 'library','page',11269,0
  UNION ALL SELECT 'library','page',11274,0
  UNION ALL SELECT 'library','page',11283,0
  UNION ALL SELECT 'library','page',11290,0
  UNION ALL SELECT 'library','page',11297,0
  UNION ALL SELECT 'library','page',11303,0
  UNION ALL SELECT 'library','page',11311,0
  UNION ALL SELECT 'library','page',11334,0
  UNION ALL SELECT 'library','page',11343,0
  UNION ALL SELECT 'library','page',11352,0
  UNION ALL SELECT 'library','page',11360,0
  UNION ALL SELECT 'library','page',11370,0
  UNION ALL SELECT 'library','page',11396,0
  UNION ALL SELECT 'library','page',12750,0
  UNION ALL SELECT 'library','page',14982,0
  UNION ALL SELECT 'library','page',7668,0
  UNION ALL SELECT 'library','page',7680,0
  UNION ALL SELECT 'course-personal-productivity','cat',669,0
  UNION ALL SELECT 'course-man-and-society','cat',870,0
  UNION ALL SELECT 'course-persuasive-presentations','cat',731,1
  UNION ALL SELECT 'course-persuasive-presentations','page',11830,1
  UNION ALL SELECT 'course-persuasive-presentations','post',11861,1
  UNION ALL SELECT 'course-graphic-designers','cat',1423,1
  UNION ALL SELECT 'course-graphic-designers','page',37315,1
  UNION ALL SELECT 'course-graphic-designers','page',225614,1
  UNION ALL SELECT 'course-graphic-designers','page',225646,1
  UNION ALL SELECT 'course-graphic-designers','page',226096,1
  UNION ALL SELECT 'course-graphic-designers','post',37256,1
  UNION ALL SELECT 'course-how-to-say-it','cat',1881,1
),
cat_tree AS (
  -- every category paired with itself and each of its descendants
  SELECT tt.term_id AS anc_id, tt.term_id AS des_id, tt.term_taxonomy_id AS des_ttid, 0 AS lvl
  FROM wp_term_taxonomy tt WHERE tt.taxonomy = 'category'
  UNION ALL
  SELECT c.anc_id, tt.term_id, tt.term_taxonomy_id, c.lvl + 1
  FROM cat_tree c
  JOIN wp_term_taxonomy tt ON tt.parent = c.des_id AND tt.taxonomy = 'category'
  WHERE c.lvl < 20
),
covered AS (
  -- (definition, post/page) pairs, the way the T2 resolver will compute them
  SELECT DISTINCT d.dkey, d.exclusive, tr.object_id AS post_id
  FROM defs d
  JOIN cat_tree c ON c.anc_id = d.obj_id
  JOIN wp_term_relationships tr ON tr.term_taxonomy_id = c.des_ttid
  WHERE d.kind = 'cat'
  UNION
  SELECT d.dkey, d.exclusive, d.obj_id FROM defs d WHERE d.kind IN ('page','post')
)
SELECT DISTINCT p.ID, p.post_status, p.post_name AS slug, p.post_title, p.post_parent,
       pm.meta_value AS page_template
FROM covered cv
JOIN wp_posts p ON p.ID = cv.post_id AND p.post_type = 'page'
LEFT JOIN wp_postmeta pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_page_template'
ORDER BY p.ID;

-- ============================================================================
-- [0.4c] Which post types carry categories at all (if anything other than 'post' appears, the resolver must know about it).
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
SELECT p.post_type, p.post_status, COUNT(DISTINCT p.ID) AS items
FROM wp_term_relationships tr
JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
JOIN wp_posts p ON p.ID = tr.object_id
GROUP BY p.post_type, p.post_status
ORDER BY items DESC;

-- ============================================================================
-- [0.5b] What category 1738 'Lucy' and 507 'PRIVATE EYES ONLY' contain (with subcategories, any status).
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
WITH RECURSIVE
cat_tree AS (
  -- every category paired with itself and each of its descendants
  SELECT tt.term_id AS anc_id, tt.term_id AS des_id, tt.term_taxonomy_id AS des_ttid, 0 AS lvl
  FROM wp_term_taxonomy tt WHERE tt.taxonomy = 'category'
  UNION ALL
  SELECT c.anc_id, tt.term_id, tt.term_taxonomy_id, c.lvl + 1
  FROM cat_tree c
  JOIN wp_term_taxonomy tt ON tt.parent = c.des_id AND tt.taxonomy = 'category'
  WHERE c.lvl < 20
),
post_cats AS (
  SELECT tr.object_id AS post_id,
         GROUP_CONCAT(CONCAT(t.name, ' (', t.term_id, ')') ORDER BY t.term_id SEPARATOR ', ') AS cats
  FROM wp_term_relationships tr
  JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
  JOIN wp_terms t ON t.term_id = tt.term_id
  GROUP BY tr.object_id
)
SELECT DISTINCT c.anc_id AS under_category, p.ID, p.post_type, p.post_status, p.post_date, p.post_title, pc.cats
FROM cat_tree c
JOIN wp_term_relationships tr ON tr.term_taxonomy_id = c.des_ttid
JOIN wp_posts p ON p.ID = tr.object_id
LEFT JOIN post_cats pc ON pc.post_id = p.ID
WHERE c.anc_id IN (1738, 507)
ORDER BY c.anc_id, p.post_date DESC;

-- ============================================================================
-- [0.6a] Memberships post types and statuses.
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
SELECT post_type, post_status, COUNT(*) AS n
FROM wp_posts
WHERE post_type IN ('wc_user_membership','wc_membership_plan')
GROUP BY post_type, post_status
ORDER BY post_type, n DESC;

-- ============================================================================
-- [0.6b] Meta keys stored on user memberships (to confirm the end-date key and its format).
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
SELECT pm.meta_key, COUNT(*) AS n, MIN(pm.meta_value) AS min_value, MAX(pm.meta_value) AS max_value
FROM wp_postmeta pm JOIN wp_posts m ON m.ID = pm.post_id
WHERE m.post_type = 'wc_user_membership'
GROUP BY pm.meta_key
ORDER BY n DESC;

-- ============================================================================
-- [0.6c] Every plan, with membership counts per status and how many have an end date.
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
SELECT pl.ID AS plan_id, pl.post_title AS plan, pl.post_status AS plan_status,
       m.post_status AS membership_status, COUNT(m.ID) AS memberships,
       COUNT(DISTINCT m.post_author) AS users,
       SUM(CASE WHEN COALESCE(e.meta_value, '') <> '' THEN 1 ELSE 0 END) AS with_end_date
FROM wp_posts pl
LEFT JOIN wp_posts m ON m.post_parent = pl.ID AND m.post_type = 'wc_user_membership'
LEFT JOIN wp_postmeta e ON e.post_id = m.ID AND e.meta_key = '_end_date'
WHERE pl.post_type = 'wc_membership_plan'
GROUP BY pl.ID, pl.post_title, pl.post_status, m.post_status
ORDER BY pl.ID, memberships DESC;

-- ============================================================================
-- [0.6d] Totals the import preview should match (spec expects about 131 memberships / 90 users).
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
SELECT COUNT(*) AS memberships, COUNT(DISTINCT post_author) AS users
FROM wp_posts
WHERE post_type = 'wc_user_membership' AND post_status IN ('wcm-active','wcm-complimentary','wcm-free_trial','wcm-pending');

-- ============================================================================
-- [0.6e] The memberships the import would read, one row each.
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
SELECT m.ID AS membership_id, m.post_author AS user_id, u.user_email,
       m.post_parent AS plan_id, pl.post_title AS plan, m.post_status,
       s.meta_value AS start_date, e.meta_value AS end_date
FROM wp_posts m
JOIN wp_posts pl ON pl.ID = m.post_parent
LEFT JOIN wp_users u ON u.ID = m.post_author
LEFT JOIN wp_postmeta s ON s.post_id = m.ID AND s.meta_key = '_start_date'
LEFT JOIN wp_postmeta e ON e.post_id = m.ID AND e.meta_key = '_end_date'
WHERE m.post_type = 'wc_user_membership' AND m.post_status IN ('wcm-active','wcm-complimentary','wcm-free_trial','wcm-pending')
ORDER BY pl.ID, u.user_email;

-- ============================================================================
-- [0.6f] Memberships settings stored as options (looking for anything that deletes data on deactivate/uninstall).
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
SELECT option_name, LEFT(option_value, 200) AS value_start, autoload
FROM wp_options
WHERE option_name LIKE 'wc_memberships%' OR option_name LIKE '%memberships_delete%'
ORDER BY option_name;

-- ============================================================================
-- [0.8a] Feed setting: rss_use_excerpt = 1 means feeds carry excerpts only; 0 means full text.
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
SELECT option_name, option_value FROM wp_options WHERE option_name IN ('rss_use_excerpt','posts_per_rss','blog_public');

-- ============================================================================
-- [0.9a] Where the 'UPS!' restricted message lives: options.
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
SELECT option_name, LENGTH(option_value) AS bytes,
       SUBSTRING(option_value, GREATEST(LOCATE('UPS!', option_value) - 150, 1), 400) AS around
FROM wp_options WHERE option_value LIKE '%UPS!%';

-- ============================================================================
-- [0.9b] ... posts, pages and Divi layouts.
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
SELECT ID, post_type, post_status, post_name, post_title,
       SUBSTRING(post_content, GREATEST(LOCATE('UPS!', post_content) - 150, 1), 400) AS around
FROM wp_posts WHERE post_content LIKE '%UPS!%' AND post_type <> 'revision';

-- ============================================================================
-- [0.9c] ... post meta.
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
SELECT pm.post_id, p.post_type, p.post_title, pm.meta_key,
       SUBSTRING(pm.meta_value, GREATEST(LOCATE('UPS!', pm.meta_value) - 150, 1), 400) AS around
FROM wp_postmeta pm JOIN wp_posts p ON p.ID = pm.post_id
WHERE pm.meta_value LIKE '%UPS!%' AND p.post_type <> 'revision';

-- ============================================================================
-- [0.10] Every table in the database, to spot any code/unlock table besides How to say it hub's own.
-- ============================================================================
SET SESSION group_concat_max_len = 100000;
SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
ORDER BY table_name;
