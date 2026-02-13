-- Pre-check before running normalization migration 2026_02_13_000350
-- Target: MySQL 8+

-- 1) Potential conflicts in pdv_user_mappings (legacy schema: many rows per pdv_user_id)
SELECT
    pdv_user_id,
    COUNT(*) AS rows_count,
    GROUP_CONCAT(
        CONCAT('id=', id, ',store_pdv_id=', COALESCE(store_pdv_id, 'null'),
               ',user_id=', COALESCE(user_id, 'null'),
               ',confidence=', COALESCE(confidence, 0))
        ORDER BY confidence DESC, updated_at DESC, id DESC
        SEPARATOR ' | '
    ) AS candidates
FROM pdv_user_mappings
GROUP BY pdv_user_id
HAVING COUNT(*) > 1
ORDER BY rows_count DESC, pdv_user_id;

-- 2) Potential conflicts in pdv_store_mappings for composite key target (pdv_store_id + alias)
SELECT
    pdv_store_id,
    COALESCE(alias, '<<NULL>>') AS alias_key,
    COUNT(*) AS rows_count,
    GROUP_CONCAT(CONCAT('id=', id, ',store_id=', store_id) ORDER BY id SEPARATOR ' | ') AS candidates
FROM pdv_store_mappings
GROUP BY pdv_store_id, COALESCE(alias, '<<NULL>>')
HAVING COUNT(*) > 1
ORDER BY rows_count DESC, pdv_store_id, alias_key;

