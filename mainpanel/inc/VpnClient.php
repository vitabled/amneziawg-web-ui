<?php
/**
 * VPN Client Management Class
 * Использует awg/awg-quick из amneziawg-tools (не стандартный wg)
 */
class VpnClient {
    private $clientId;
    private $data;

    public function __construct(?int $clientId = null) {
        $this->clientId = $clientId;
        if ($clientId) {
            $this->load();
        }
    }

    private function load(): void {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM vpn_clients WHERE id = ?');
        $stmt->execute([$this->clientId]);
        $this->data = $stmt->fetch();
        if (!$this->data) {
            throw new Exception('Client not found');
        }
    }

    // -------------------------------------------------------------------------
    // CREATE
    // -------------------------------------------------------------------------

    public static function create(int $serverId, int $userId, string $name, ?int $expiresInDays = null): int {
        $pdo = DB::conn();

        $name = str_replace(' ', '_', trim($name));

        $server     = new VpnServer($serverId);
        $serverData = $server->getData();

        if (!$serverData || $serverData['status'] !== 'active') {
            throw new Exception('Server is not active');
        }

        // Генерировать ключи клиента через awg (не wg)
        $keys = self::generateClientKeys($serverData, $name);

        // Получить следующий свободный IP
        $clientIP = self::getNextClientIP($serverData);

        // AWG-параметры берём с сервера
        $awgParams = json_decode($serverData['awg_params'], true);

        // Собрать конфиг клиента
        $config = self::buildClientConfig(
            $keys['private'],
            $clientIP,
            $serverData['server_public_key'],
            $serverData['preshared_key'],
            $serverData['host'],
            $serverData['vpn_port'],
            $awgParams
        );

        // Добавить клиента в wg0.conf на сервере и применить через awg syncconf
        self::addClientToServer($serverData, $keys['public'], $clientIP);

        // QR-код
        $qrCode = self::generateQRCode($config);

        // Срок действия
        $expiresAt = $expiresInDays
            ? date('Y-m-d H:i:s', strtotime("+{$expiresInDays} days"))
            : null;

        $stmt = $pdo->prepare('
            INSERT INTO vpn_clients 
            (server_id, user_id, name, client_ip, public_key, private_key, preshared_key, config, qr_code, status, expires_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $serverId, $userId, $name, $clientIP,
            $keys['public'], $keys['private'], $serverData['preshared_key'],
            $config, $qrCode, 'active', $expiresAt
        ]);

        return (int)$pdo->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // ГЕНЕРАЦИЯ КЛЮЧЕЙ — через awg (не wg!)
    // -------------------------------------------------------------------------

    /**
     * Генерировать ключи клиента внутри контейнера с помощью awg genkey / awg pubkey.
     * В app.py используется wg genkey / wg pubkey, но в контейнере amneziavpn/amnezia-wg
     * доступны именно awg-утилиты.
     */
    private static function generateClientKeys(array $serverData, string $clientName): array {
        $containerName = $serverData['container_name'];

        // Используем awg вместо wg
        $cmd = sprintf(
            "docker exec -i %s sh -c \"umask 077;"
            . " awg genkey | tee /tmp/%s_priv.key | awg pubkey > /tmp/%s_pub.key;"
            . " cat /tmp/%s_priv.key; echo '---'; cat /tmp/%s_pub.key;"
            . " rm -f /tmp/%s_priv.key /tmp/%s_pub.key\"",
            $containerName,
            $clientName, $clientName,
            $clientName, $clientName,
            $clientName, $clientName
        );

        $out = self::executeServerCommand($serverData, $cmd, true);
        $parts = explode('---', trim($out));

        if (count($parts) < 2 || empty(trim($parts[0])) || empty(trim($parts[1]))) {
            throw new Exception("Failed to generate client keys via awg. Output: " . substr($out, 0, 200));
        }

        return [
            'private' => trim($parts[0]),
            'public'  => trim($parts[1]),
        ];
    }

    // -------------------------------------------------------------------------
    // IP ALLOCATION
    // -------------------------------------------------------------------------

    private static function getNextClientIP(array $serverData): string {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT client_ip FROM vpn_clients WHERE server_id = ?');
        $stmt->execute([$serverData['id']]);
        $usedIPs = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $parts       = explode('/', $serverData['vpn_subnet']);
        $networkLong = ip2long($parts[0]);

        $used = [];
        foreach ($usedIPs as $ip) {
            $used[$ip] = true;
        }

        for ($i = 1; $i <= 253; $i++) {
            $candidate = long2ip($networkLong + $i);
            if (!isset($used[$candidate])) {
                return $candidate;
            }
        }

        throw new Exception('No free IP addresses in subnet');
    }

    // -------------------------------------------------------------------------
    // КОНФИГ КЛИЕНТА
    // -------------------------------------------------------------------------

    /**
     * Собрать .conf файл клиента с AWG-параметрами обфускации.
     * Структура идентична generate_wireguard_client_config из app.py.
     */
    private static function buildClientConfig(
        string $privateKey,
        string $clientIP,
        string $serverPublicKey,
        string $presharedKey,
        string $serverHost,
        int $serverPort,
        array $awgParams
    ): string {
        $config  = "[Interface]\n";
        $config .= "PrivateKey = {$privateKey}\n";
        $config .= "Address = {$clientIP}/32\n";
        $config .= "DNS = 1.1.1.1, 1.0.0.1\n";

        // AWG-параметры обфускации (как в app.py)
        foreach (['Jc', 'Jmin', 'Jmax', 'S1', 'S2', 'H1', 'H2', 'H3', 'H4'] as $key) {
            if (isset($awgParams[$key])) {
                $config .= "{$key} = {$awgParams[$key]}\n";
            }
        }

        $config .= "\n[Peer]\n";
        $config .= "PublicKey = {$serverPublicKey}\n";
        $config .= "PresharedKey = {$presharedKey}\n";
        $config .= "Endpoint = {$serverHost}:{$serverPort}\n";
        $config .= "AllowedIPs = 0.0.0.0/0, ::/0\n";
        $config .= "PersistentKeepalive = 25\n";

        return $config;
    }

    // -------------------------------------------------------------------------
    // ДОБАВЛЕНИЕ / УДАЛЕНИЕ КЛИЕНТА НА СЕРВЕРЕ
    // -------------------------------------------------------------------------

    /**
     * Добавить клиента в wg0.conf и применить через awg syncconf
     * (аналог addClientToServer + apply_live_config из app.py)
     */
    public static function addClientToServer(array $serverData, string $publicKey, string $clientIP): void {
        $containerName = $serverData['container_name'];

        // Peer-блок
        $peerBlock  = "\n[Peer]\n";
        $peerBlock .= "PublicKey = {$publicKey}\n";
        $peerBlock .= "PresharedKey = {$serverData['preshared_key']}\n";
        $peerBlock .= "AllowedIPs = {$clientIP}/32\n";

        // Записать во временный файл и дозаписать в wg0.conf
        $tmpFile = '/tmp/' . bin2hex(random_bytes(8)) . '.tmp';
        $b64 = base64_encode($peerBlock);

        self::executeServerCommand($serverData,
            "echo '{$b64}' | base64 -d > {$tmpFile}",
            true
        );
        self::executeServerCommand($serverData,
            "docker exec -i {$containerName} sh -c 'cat {$tmpFile} >> /opt/amnezia/awg/wg0.conf'",
            true
        );

        // Применить конфиг без перезапуска — awg syncconf
        // (аналог apply_live_config из app.py)
        self::executeServerCommand($serverData,
            "docker exec -i {$containerName} bash -c"
            . " 'awg syncconf wg0 <(awg-quick strip /opt/amnezia/awg/wg0.conf)' 2>&1",
            true
        );

        // Удалить временный файл на хосте
        self::executeServerCommand($serverData, "rm -f {$tmpFile}", true);

        // Обновить clientsTable
        self::updateClientsTable($serverData, $publicKey, $clientIP);
    }

    private static function updateClientsTable(array $serverData, string $publicKey, string $name): void {
        $containerName = $serverData['container_name'];

        $tableJson = self::executeServerCommand($serverData,
            "docker exec -i {$containerName} cat /opt/amnezia/awg/clientsTable 2>/dev/null || echo '[]'",
            true
        );
        $table = json_decode(trim($tableJson), true);
        if (!is_array($table)) $table = [];

        $table[] = [
            'clientId' => $publicKey,
            'userData' => [
                'clientName'   => $name,
                'creationDate' => date('D M j H:i:s Y'),
            ],
        ];

        $b64 = base64_encode(json_encode($table, JSON_PRETTY_PRINT));
        self::executeServerCommand($serverData,
            "echo '{$b64}' | base64 -d | docker exec -i {$containerName} sh -c 'cat > /opt/amnezia/awg/clientsTable'",
            true
        );
    }

    /**
     * Удалить клиента с сервера
     */
    private static function removeClientFromServer(array $serverData, string $publicKey): void {
        $containerName = $serverData['container_name'];

        // Живое удаление через awg set ... remove
        self::executeServerCommand($serverData,
            "docker exec -i {$containerName} awg set wg0 peer " . escapeshellarg($publicKey) . " remove",
            true
        );

        // Перечитать конфиг, убрать peer-блок, записать обратно
        $config = self::executeServerCommand($serverData,
            "docker exec -i {$containerName} cat /opt/amnezia/awg/wg0.conf",
            true
        );

        $newConfig = self::removePeerFromConfig($config, $publicKey);

        $b64 = base64_encode($newConfig);
        self::executeServerCommand($serverData,
            "echo '{$b64}' | base64 -d | docker exec -i {$containerName} sh -c 'cat > /opt/amnezia/awg/wg0.conf'",
            true
        );

        // Убрать из clientsTable
        self::removeFromClientsTable($serverData, $publicKey);
    }

    private static function removePeerFromConfig(string $config, string $publicKey): string {
        $lines       = explode("\n", $config);
        $newLines    = [];
        $inPeerBlock = false;
        $skipBlock   = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (strpos($trimmed, '[') === 0) {
                $inPeerBlock = ($trimmed === '[Peer]');
                $skipBlock   = false;
            }

            if ($inPeerBlock && strpos($trimmed, 'PublicKey') === 0) {
                $parts = explode('=', $line, 2);
                if (count($parts) === 2 && trim($parts[1]) === $publicKey) {
                    $skipBlock = true;
                    array_pop($newLines); // убрать уже добавленный [Peer]
                    continue;
                }
            }

            if ($skipBlock && $inPeerBlock) {
                if (empty($trimmed)) {
                    $skipBlock   = false;
                    $inPeerBlock = false;
                }
                continue;
            }

            $newLines[] = $line;
        }

        return implode("\n", $newLines);
    }

    private static function removeFromClientsTable(array $serverData, string $publicKey): void {
        $containerName = $serverData['container_name'];

        $tableJson = self::executeServerCommand($serverData,
            "docker exec -i {$containerName} cat /opt/amnezia/awg/clientsTable 2>/dev/null || echo '[]'",
            true
        );
        $table = json_decode(trim($tableJson), true);
        if (!is_array($table)) return;

        $table = array_values(array_filter($table, fn($c) => ($c['clientId'] ?? '') !== $publicKey));

        $b64 = base64_encode(json_encode($table, JSON_PRETTY_PRINT));
        self::executeServerCommand($serverData,
            "echo '{$b64}' | base64 -d | docker exec -i {$containerName} sh -c 'cat > /opt/amnezia/awg/clientsTable'",
            true
        );
    }

    // -------------------------------------------------------------------------
    // SSH HELPER
    // -------------------------------------------------------------------------

    private static function executeServerCommand(array $serverData, string $command, bool $sudo = false): string {
        if ($sudo && strtolower($serverData['username']) !== 'root') {
            $command = "echo '{$serverData['password']}' | sudo -S " . $command;
        }

        $escapedCmd = escapeshellarg($command);
        $sshCmd = sprintf(
            "sshpass -p '%s' ssh -p %d -q"
            . " -o LogLevel=ERROR -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no"
            . " -o PreferredAuthentications=password -o PubkeyAuthentication=no"
            . " %s@%s %s 2>&1",
            $serverData['password'],
            $serverData['port'],
            $serverData['username'],
            $serverData['host'],
            $escapedCmd
        );

        return shell_exec($sshCmd) ?? '';
    }

    // -------------------------------------------------------------------------
    // СТАТИСТИКА — через awg show (не wg show!)
    // -------------------------------------------------------------------------

    /**
     * Синхронизировать статистику клиента через `awg show wg0 dump`
     * (аналог getClientStatsFromServer из app.py, но использует awg)
     */
    public function syncStats(): bool {
        if (!$this->data) throw new Exception('Client not loaded');

        $server     = new VpnServer($this->data['server_id']);
        $serverData = $server->getData();

        if (!$serverData || $serverData['status'] !== 'active') return false;

        try {
            $stats = self::getClientStatsFromServer($serverData, $this->data['public_key']);

            $pdo  = DB::conn();
            $stmt = $pdo->prepare('
                UPDATE vpn_clients 
                SET bytes_sent = ?, bytes_received = ?, last_handshake = ?, last_sync_at = NOW()
                WHERE id = ?
            ');

            $lastHandshake = $stats['last_handshake'] > 0
                ? date('Y-m-d H:i:s', $stats['last_handshake'])
                : null;

            return $stmt->execute([
                $stats['bytes_sent'],
                $stats['bytes_received'],
                $lastHandshake,
                $this->clientId,
            ]);
        } catch (Exception $e) {
            error_log('Failed to sync client stats: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Получить статистику клиента через `awg show wg0 dump` (не wg show!)
     * Формат dump: pubkey psk endpoint allowed_ips latest_handshake rx tx keepalive
     */
    private static function getClientStatsFromServer(array $serverData, string $publicKey): array {
        $containerName = $serverData['container_name'];

        // awg show wg0 dump — вместо wg show wg0 dump
        $output = self::executeServerCommand($serverData,
            "docker exec -i {$containerName} awg show wg0 dump 2>/dev/null || echo ''",
            true
        );

        $stats = ['bytes_sent' => 0, 'bytes_received' => 0, 'last_handshake' => 0];

        foreach (explode("\n", trim($output)) as $line) {
            if (empty($line)) continue;
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 7) continue;

            if ($parts[0] === $publicKey) {
                $stats['last_handshake']  = (int)$parts[4];
                $stats['bytes_sent']      = (int)$parts[5];
                $stats['bytes_received']  = (int)$parts[6];
                break;
            }
        }

        return $stats;
    }

    // -------------------------------------------------------------------------
    // REVOKE / RESTORE / DELETE
    // -------------------------------------------------------------------------

    public function revoke(): bool {
        if (!$this->data) throw new Exception('Client not loaded');

        $server     = new VpnServer($this->data['server_id']);
        $serverData = $server->getData();

        if ($serverData && $serverData['status'] === 'active') {
            try {
                self::removeClientFromServer($serverData, $this->data['public_key']);
            } catch (Exception $e) {
                error_log('Failed to remove client from server: ' . $e->getMessage());
            }
        }

        $pdo  = DB::conn();
        $stmt = $pdo->prepare('UPDATE vpn_clients SET status = ? WHERE id = ?');
        return $stmt->execute(['disabled', $this->clientId]);
    }

    public function restore(): bool {
        if (!$this->data) throw new Exception('Client not loaded');

        $server     = new VpnServer($this->data['server_id']);
        $serverData = $server->getData();

        if ($serverData && $serverData['status'] === 'active') {
            try {
                self::addClientToServer($serverData, $this->data['public_key'], $this->data['client_ip']);
            } catch (Exception $e) {
                throw new Exception('Failed to restore client on server: ' . $e->getMessage());
            }
        }

        $pdo  = DB::conn();
        $stmt = $pdo->prepare('UPDATE vpn_clients SET status = ? WHERE id = ?');
        return $stmt->execute(['active', $this->clientId]);
    }

    public function delete(): bool {
        if (!$this->data) throw new Exception('Client not loaded');
        if ($this->data['status'] === 'active') $this->revoke();
        $pdo  = DB::conn();
        $stmt = $pdo->prepare('DELETE FROM vpn_clients WHERE id = ?');
        return $stmt->execute([$this->clientId]);
    }

    // -------------------------------------------------------------------------
    // LIST
    // -------------------------------------------------------------------------

    public static function listByServer(int $serverId): array {
        $pdo  = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM vpn_clients WHERE server_id = ? ORDER BY created_at DESC');
        $stmt->execute([$serverId]);
        return $stmt->fetchAll();
    }

    public static function listByUser(int $userId): array {
        $pdo  = DB::conn();
        $stmt = $pdo->prepare('
            SELECT c.*, s.name as server_name, s.host as server_host
            FROM vpn_clients c
            LEFT JOIN vpn_servers s ON c.server_id = s.id
            WHERE c.user_id = ?
            ORDER BY c.created_at DESC
        ');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function syncAllStatsForServer(int $serverId): int {
        $pdo  = DB::conn();
        $stmt = $pdo->prepare('SELECT id FROM vpn_clients WHERE server_id = ? AND status = ?');
        $stmt->execute([$serverId, 'active']);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $synced = 0;
        foreach ($ids as $id) {
            try {
                $client = new self($id);
                if ($client->syncStats()) $synced++;
            } catch (Exception $e) {
                error_log('Failed to sync stats for client ' . $id . ': ' . $e->getMessage());
            }
        }
        return $synced;
    }

    // -------------------------------------------------------------------------
    // QR
    // -------------------------------------------------------------------------

    private static function generateQRCode(string $config): string {
        $qrFile = __DIR__ . '/QrUtil.php';
        if (!file_exists($qrFile)) return '';

        require_once $qrFile;

        try {
            $payload = QrUtil::encodeOldPayloadFromConf($config);
            return QrUtil::pngBase64($payload);
        } catch (Throwable $e) {
            error_log('Failed to generate QR code: ' . $e->getMessage());
            return '';
        }
    }

    // -------------------------------------------------------------------------
    // GETTERS
    // -------------------------------------------------------------------------

    public function getData(): ?array  { return $this->data; }
    public function getConfig(): string { return $this->data['config'] ?? ''; }
    public function getQRCode(): string { return $this->data['qr_code'] ?? ''; }

    public function getFormattedStats(): array {
        if (!$this->data) {
            return ['sent' => 'N/A', 'received' => 'N/A', 'total' => 'N/A', 'last_seen' => 'Never', 'is_online' => false];
        }

        $sent     = $this->formatBytes($this->data['bytes_sent'] ?? 0);
        $received = $this->formatBytes($this->data['bytes_received'] ?? 0);
        $total    = $this->formatBytes(($this->data['bytes_sent'] ?? 0) + ($this->data['bytes_received'] ?? 0));

        $lastSeen  = 'Never';
        $isOnline  = false;
        if (!empty($this->data['last_handshake'])) {
            $diff = time() - strtotime($this->data['last_handshake']);
            if ($diff < 300)       { $lastSeen = 'Online'; $isOnline = true; }
            elseif ($diff < 3600)  { $lastSeen = floor($diff / 60) . ' minutes ago'; }
            elseif ($diff < 86400) { $lastSeen = floor($diff / 3600) . ' hours ago'; }
            else                   { $lastSeen = floor($diff / 86400) . ' days ago'; }
        }

        return ['sent' => $sent, 'received' => $received, 'total' => $total, 'last_seen' => $lastSeen, 'is_online' => $isOnline];
    }

    private function formatBytes(int $bytes): string {
        return number_format($bytes / 1048576, 2) . ' MB';
    }

    // -------------------------------------------------------------------------
    // EXPIRATION / TRAFFIC LIMIT
    // -------------------------------------------------------------------------

    public static function setExpiration(int $clientId, ?string $expiresAt): bool {
        $pdo  = DB::conn();
        $stmt = $pdo->prepare('UPDATE vpn_clients SET expires_at = ? WHERE id = ?');
        return $stmt->execute([$expiresAt, $clientId]);
    }

    public static function extendExpiration(int $clientId, int $days): bool {
        $pdo  = DB::conn();
        $stmt = $pdo->prepare('SELECT expires_at FROM vpn_clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $client = $stmt->fetch();
        if (!$client) return false;

        $base       = $client['expires_at'] ? strtotime($client['expires_at']) : time();
        $newExpires = date('Y-m-d H:i:s', strtotime("+{$days} days", $base));
        return self::setExpiration($clientId, $newExpires);
    }

    public static function getExpiringClients(int $days = 7): array {
        $pdo  = DB::conn();
        $stmt = $pdo->prepare('
            SELECT c.*, s.name as server_name, s.host, u.name as user_name, u.email
            FROM vpn_clients c
            JOIN vpn_servers s ON c.server_id = s.id
            JOIN users u ON c.user_id = u.id
            WHERE c.expires_at IS NOT NULL 
              AND c.expires_at <= DATE_ADD(NOW(), INTERVAL ? DAY)
              AND c.expires_at > NOW()
              AND c.status = "active"
            ORDER BY c.expires_at ASC
        ');
        $stmt->execute([$days]);
        return $stmt->fetchAll();
    }

    public static function getExpiredClients(): array {
        $pdo  = DB::conn();
        $stmt = $pdo->query('
            SELECT c.*, s.name as server_name, s.host
            FROM vpn_clients c
            JOIN vpn_servers s ON c.server_id = s.id
            WHERE c.expires_at IS NOT NULL AND c.expires_at <= NOW() AND c.status = "active"
            ORDER BY c.expires_at DESC
        ');
        return $stmt->fetchAll();
    }

    public static function disableExpiredClients(): int {
        $count = 0;
        foreach (self::getExpiredClients() as $cd) {
            try { $c = new self($cd['id']); $c->revoke(); $count++; }
            catch (Exception $e) { error_log("Failed to disable expired client {$cd['id']}: " . $e->getMessage()); }
        }
        return $count;
    }

    public function isExpired(): bool {
        return $this->data && $this->data['expires_at'] !== null && strtotime($this->data['expires_at']) <= time();
    }

    public function getDaysUntilExpiration(): ?int {
        if (!$this->data || $this->data['expires_at'] === null) return null;
        return (int)floor((strtotime($this->data['expires_at']) - time()) / 86400);
    }

    public function setTrafficLimit(?int $limitBytes): bool {
        if (!$this->data) throw new Exception('Client not loaded');
        $pdo  = DB::conn();
        $stmt = $pdo->prepare('UPDATE vpn_clients SET traffic_limit = ? WHERE id = ?');
        $result = $stmt->execute([$limitBytes, $this->clientId]);
        if ($result) $this->data['traffic_limit'] = $limitBytes;
        return $result;
    }

    public function getTotalTraffic(): int {
        if (!$this->data) return 0;
        return (int)($this->data['bytes_sent'] ?? 0) + (int)($this->data['bytes_received'] ?? 0);
    }

    public function isOverLimit(): bool {
        if (!$this->data || ($this->data['traffic_limit'] ?? null) === null) return false;
        return $this->getTotalTraffic() >= (int)$this->data['traffic_limit'];
    }

    public function getTrafficLimitStatus(): array {
        $total = $this->getTotalTraffic();
        $limit = $this->data['traffic_limit'] ?? null;
        return [
            'total_traffic' => $total,
            'traffic_limit' => $limit,
            'is_unlimited'  => $limit === null,
            'is_over_limit' => $this->isOverLimit(),
            'percentage_used' => $limit ? min(100, round(($total / $limit) * 100, 2)) : 0,
            'remaining'     => $limit ? max(0, $limit - $total) : null,
        ];
    }

    public static function getClientsOverLimit(): array {
        $pdo  = DB::conn();
        $stmt = $pdo->query('
            SELECT id, name, bytes_sent, bytes_received, traffic_limit 
            FROM vpn_clients 
            WHERE traffic_limit IS NOT NULL 
              AND (bytes_sent + bytes_received) >= traffic_limit 
              AND status = "active"
            ORDER BY id
        ');
        return $stmt->fetchAll();
    }

    public static function disableClientsOverLimit(): int {
        $disabled = 0;
        foreach (self::getClientsOverLimit() as $cd) {
            try { $c = new self($cd['id']); if ($c->revoke()) $disabled++; }
            catch (Exception $e) { error_log("Failed to disable client {$cd['id']}: " . $e->getMessage()); }
        }
        return $disabled;
    }
}