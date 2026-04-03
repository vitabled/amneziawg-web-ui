-- Добавить колонку traffic_limit если её ещё нет
ALTER TABLE vpn_clients
    ADD COLUMN IF NOT EXISTS traffic_limit BIGINT UNSIGNED NULL
        COMMENT 'Traffic limit in bytes (NULL = unlimited)'
        AFTER expires_at;
 
-- Добавить индекс для быстрого поиска клиентов с превышенным лимитом
CREATE INDEX IF NOT EXISTS idx_traffic_limit
    ON vpn_clients (traffic_limit, status);
