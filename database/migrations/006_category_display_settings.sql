-- Category-based display restrictions. 0 = visible; 1 = hidden.
-- A restriction on any parent category also applies to descendants.
ALTER TABLE categories
    ADD COLUMN hide_tracking TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN hide_store_stock TINYINT(1) NOT NULL DEFAULT 0;
