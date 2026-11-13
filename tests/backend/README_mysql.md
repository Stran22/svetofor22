# MySQL integration smoke test

Run only against a separate MySQL database whose name ends in `_test`. The test refuses other database names and wraps its writes in a transaction that is rolled back.

## Preparation

1. Create a separate database, e.g. `svet22_test`, and a dedicated MySQL user with access **only** to this database.
2. Apply the project's base schema and migrations through `006_import_mapping_fingerprint.sql` in order.
3. Add at least one active row to `branches` in the test database.
4. Run on a machine with PHP PDO MySQL enabled:

```bash
SVET_TEST_DSN='mysql:host=127.0.0.1;dbname=svet22_test;charset=utf8mb4' \
SVET_TEST_USER='svet22_tester' \
SVET_TEST_PASSWORD='YOUR_TEST_PASSWORD' \
php tests/backend/mysql_integration.php
```

The script checks required table columns, InnoDB transaction support, matching/different Excel mappings, and SAVEPOINT rollback. It does **not** test full XLSX parsing, stock updates, FCM notifications, or concurrent imports; those need additional fixtures and a more comprehensive suite.

**Deployment rule:** migration 006 must be applied before the updated importer is deployed. Never run this test against the production database.
