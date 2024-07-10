UPDATE /*_*/aspaklarya_lockdown_pages
SET al_level = CASE
    WHEN al_read_allowed = 0 THEN 1
    WHEN al_read_allowed = 1 THEN 4
    WHEN al_read_allowed = 2 THEN 8
    WHEN al_read_allowed = 4 THEN 16
    WHEN al_read_allowed = 8 THEN 2
END;