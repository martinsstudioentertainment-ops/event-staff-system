-- Ensure all events accept both DSP and Static staff (additive merge).
-- Safe to run: preserves steward or other roles if present; adds dsp and static where missing.

UPDATE events
SET roles_needed = CASE
    WHEN roles_needed IS NULL OR TRIM(roles_needed) = '' THEN 'dsp,static'
    WHEN FIND_IN_SET('dsp', REPLACE(roles_needed, ' ', '')) = 0
     AND FIND_IN_SET('static', REPLACE(roles_needed, ' ', '')) = 0
        THEN CONCAT(TRIM(BOTH ',' FROM roles_needed), ',dsp,static')
    WHEN FIND_IN_SET('dsp', REPLACE(roles_needed, ' ', '')) = 0
        THEN CONCAT(TRIM(BOTH ',' FROM roles_needed), ',dsp')
    WHEN FIND_IN_SET('static', REPLACE(roles_needed, ' ', '')) = 0
        THEN CONCAT(TRIM(BOTH ',' FROM roles_needed), ',static')
    ELSE roles_needed
END;
