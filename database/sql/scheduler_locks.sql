-- Tabulka zámků pro Emorfiq\Mutex\Store\PdoLockStore.
-- Přenositelné SQL (MySQL/MariaDB, PostgreSQL, SQLite) – použijte v libovolném migračním nástroji
-- (Flyway, Doctrine Migrations, Phinx, …) nebo spusťte ručně. Laravel: database/migrations/*.
-- Jiný název tabulky = nahraďte "scheduler_locks" a předejte ho do konstruktoru PdoLockStore.

CREATE TABLE scheduler_locks (
    lock_key   VARCHAR(191) NOT NULL PRIMARY KEY, -- prefix + klíč, max 191 znaků (utf8mb4 index)
    owner      VARCHAR(64)  NOT NULL,             -- token vlastníka (hex)
    expires_at BIGINT       NOT NULL              -- unix timestamp v milisekundách
);

CREATE INDEX scheduler_locks_expires_at_index ON scheduler_locks (expires_at); -- pro purgeExpired()
