-- One subscription per user and product. Test subscriptions are intentionally discarded.
-- Run after 006 and only while push workers are stopped.
DELETE FROM subscriptions;
ALTER TABLE subscriptions
    DROP INDEX uq_subscription_user_product_branch,
    MODIFY branch_id BIGINT UNSIGNED NULL DEFAULT NULL,
    ADD UNIQUE KEY uq_subscription_user_product (user_id, product_id);
