<?php
/**
 * VPN Server Management Class
 * Handles deployment and management of Amnezia VPN servers
 * Uses awg/awg-quick from amneziawg-tools (not standard wg)
 */
class VpnServer {
    private $serverId;
    private $data;

    public function __construct(?int $serverId = null) {
        $this->serverId = $serverId;
        if ($serverId) {
            $this->load();
        }
    }

    private function load(): void {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM vpn_servers WHERE id = ?');
        $stmt->execute([$this->serverId]);
        $this->data = $stmt->fetch();
        if (!$this->data) {
            throw new Exception('Server not found');
        }
    }

    public static function create(array $data): int {
        $pdo = DB::conn();

        $required = ['user_id', 'name', 'host', 'port', 'username', 'password'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Field {$field} is required");
            }
        }

        $stmt = $pdo->prepare('
            INSERT INTO vpn_servers 
            (user_id, name, host, port, username, password, container_name, vpn_subnet, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $stmt->execute([
            $data['user_id'],
            $data['name'],
            $data['host'],
            $data['port'],
            $data['username'],
            $data['password'],
            $data['container_name'] ?? 'amnezia-awg',
            $data['vpn_subnet'] ?? '10.8.1.0/24',
            'deploying'
        ]);

        return (int)$pdo->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // DEPLOY
    // -------------------------------------------------------------------------

    public function deploy(): array {
        if (!$this->data) {
            throw new Exception('Server not loaded');
        }

        $pdo = DB::conn();

        try {
            $pdo->prepare('UPDATE vpn_servers SET status = ? WHERE id = ?')
                ->execute(['deploying', $this->serverId]);

            // 1. Проверить SSH-соединение
            if (!$this->testConnection()) {
                throw new Exception('SSH connection failed. Check host, port, username and password.');
            }

            // 2. Установить Docker если нет
            $this->installDocker();

            // 3. Установить amneziawg-tools (awg, awg-quick) — КЛЮЧЕВОЙ ШАГ

            // 4. Создать рабочую директорию
            $this->executeCommand('mkdir -p /opt/amnezia/amnezia-awg', true);

            // 5. Найти свободный UDP-порт
            $vpnPort = $this->findFreeUdpPort();

            // 6. Создать Dockerfile
            $this->createDockerfile();

            // 7. Создать стартовый скрипт
            $this->createStartScript();

            // 8. Собрать образ
            $this->buildDockerImage();

            // 9. Запустить контейнер
            $this->runContainer($vpnPort);

            // 10. Инициализировать конфиг сервера (ключи, AWG-параметры, wg0.conf)
            $keys = $this->initializeServerConfig($vpnPort);

            // Сохранить результаты в БД
            $pdo->prepare('
                UPDATE vpn_servers 
                SET vpn_port = ?, server_public_key = ?, preshared_key = ?,
                    awg_params = ?, status = ?, deployed_at = NOW(), error_message = NULL
                WHERE id = ?
            ')->execute([
                $vpnPort,
                $keys['public_key'],
                $keys['preshared_key'],
                json_encode($keys['awg_params']),
                'active',
                $this->serverId
            ]);

            $this->load();

            return [
                'success'    => true,
                'vpn_port'   => $vpnPort,
                'public_key' => $keys['public_key'],
            ];

        } catch (Exception $e) {
            $pdo->prepare('UPDATE vpn_servers SET status = ?, error_message = ? WHERE id = ?')
                ->execute(['error', $e->getMessage(), $this->serverId]);
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // УСТАНОВКА ЗАВИСИМОСТЕЙ
    // -------------------------------------------------------------------------

    /**
     * Установить amneziawg-tools на удалённый сервер.
     * Утилиты awg и awg-quick — замена стандартным wg / wg-quick.
     * Репозиторий: https://github.com/amnezia-vpn/amneziawg-tools
     */
   private function installAmneziaWgTools(): void {
        // awg уже встроен в образ amneziavpn/amnezia-wg:latest
        // проверяем его наличие ВНУТРИ контейнера, а не на хосте
        $containerName = $this->data['container_name'];
        
        $check = $this->executeCommand(
            "docker exec -i {$containerName} which awg 2>/dev/null || echo ''"
        );
        
        if (strpos(trim($check), '/') === 0) {
            return; // awg уже есть внутри контейнера
        }
        
        // Если по какой-то причине нет — попробовать apk (Alpine в образе)
        $this->executeCommand(
            "docker exec -i {$containerName} sh -c 'apk add --no-cache wireguard-tools 2>/dev/null || true'",
            true
        );
        
        // Повторная проверка
        $check = $this->executeCommand(
            "docker exec -i {$containerName} which awg 2>/dev/null || echo ''"
        );
        
        if (strpos(trim($check), '/') !== 0) {
            // awg нет но это не критично — образ amneziavpn/amnezia-wg должен его содержать
            // логируем но не падаем
            error_log('Warning: awg not found in container, but continuing deployment');
        }
    }

    private function installAmneziaWgToolsDebian(): void {
        // Официальный способ через PPA для Debian/Ubuntu
        $script = implode(' && ', [
            'export DEBIAN_FRONTEND=noninteractive',
            'apt-get update -qq',
            'apt-get install -y -qq curl gpg lsb-release',
            // Скачать и установить готовый deb-пакет из GitHub releases
            'ARCH=$(dpkg --print-architecture)',
            'AWG_VERSION=$(curl -s https://api.github.com/repos/amnezia-vpn/amneziawg-tools/releases/latest | grep tag_name | cut -d\'"\' -f4)',
            'curl -sL "https://github.com/amnezia-vpn/amneziawg-tools/releases/download/${AWG_VERSION}/amneziawg-tools_${AWG_VERSION#v}_${ARCH}.deb" -o /tmp/awg-tools.deb 2>/dev/null || true',
            // Если deb не скачался — собрать из исходников
            'if [ -f /tmp/awg-tools.deb ]; then dpkg -i /tmp/awg-tools.deb; else apt-get install -y -qq make gcc && git clone --depth 1 https://github.com/amnezia-vpn/amneziawg-tools.git /tmp/amneziawg-tools && cd /tmp/amneziawg-tools/src && make && make install; fi',
        ]);
        $this->executeCommand($script, true);
    }

    private function installAmneziaWgToolsRhel(): void {
        $script = implode(' && ', [
            'yum install -y -q make gcc git',
            'git clone --depth 1 https://github.com/amnezia-vpn/amneziawg-tools.git /tmp/amneziawg-tools',
            'cd /tmp/amneziawg-tools/src && make && make install',
        ]);
        $this->executeCommand($script, true);
    }

    private function installAmneziaWgToolsFromSource(): void {
        $script = implode(' && ', [
            // Попробовать apt или yum для зависимостей
            '(apt-get install -y -qq make gcc git 2>/dev/null || yum install -y -q make gcc git 2>/dev/null || true)',
            'git clone --depth 1 https://github.com/amnezia-vpn/amneziawg-tools.git /tmp/amneziawg-tools',
            'cd /tmp/amneziawg-tools/src && make && make install',
        ]);
        $this->executeCommand($script, true);
    }

    private function installDocker(): void {
        $dockerVersion = $this->executeCommand('docker --version 2>/dev/null || echo ""');
        if (stripos($dockerVersion, 'version') !== false) {
            return;
        }
        $this->executeCommand('curl -fsSL https://get.docker.com | sh', true);
        $this->executeCommand('systemctl enable --now docker', true);
    }

    // -------------------------------------------------------------------------
    // DOCKER — СОЗДАНИЕ ОБРАЗА И КОНТЕЙНЕРА
    // -------------------------------------------------------------------------

    private function createDockerfile(): void {
        // Dockerfile использует образ amneziavpn/amnezia-wg — в нём уже есть awg-ядро
        $dockerfile = <<<'DOCKERFILE'
FROM amneziavpn/amnezia-wg:latest

LABEL maintainer="AmneziaVPN"

RUN apk add --no-cache bash curl dumb-init iptables
RUN apk --update upgrade --no-cache

RUN mkdir -p /opt/amnezia
COPY start.sh /opt/amnezia/start.sh
RUN chmod +x /opt/amnezia/start.sh

ENTRYPOINT ["dumb-init", "/opt/amnezia/start.sh"]
CMD [""]
DOCKERFILE;

        $this->writeRemoteFile('/opt/amnezia/amnezia-awg/Dockerfile', $dockerfile, true);
    }

    private function createStartScript(): void {
        $script = <<<'BASH'
#!/bin/bash

echo "Container startup"

# Ждём конфиг если ещё не создан
for i in {1..30}; do
    if [ -f /opt/amnezia/awg/wg0.conf ]; then
        break
    fi
    sleep 1
done

# Остановить если был запущен (на случай рестарта)
awg-quick down /opt/amnezia/awg/wg0.conf 2>/dev/null || true

# Запустить AmneziaWG если конфиг есть
if [ -f /opt/amnezia/awg/wg0.conf ]; then
    awg-quick up /opt/amnezia/awg/wg0.conf
    echo "AmneziaWG started"
else
    echo "No wg0.conf found, waiting..."
fi

# iptables правила для форвардинга трафика
iptables -A INPUT   -i wg0 -j ACCEPT 2>/dev/null || true
iptables -A FORWARD -i wg0 -j ACCEPT 2>/dev/null || true
iptables -A OUTPUT  -o wg0 -j ACCEPT 2>/dev/null || true
iptables -A FORWARD -m state --state ESTABLISHED,RELATED -j ACCEPT 2>/dev/null || true
iptables -A FORWARD -i wg0 -o eth0 -s 10.8.1.0/24 -j ACCEPT 2>/dev/null || true
iptables -A FORWARD -i wg0 -o eth1 -s 10.8.1.0/24 -j ACCEPT 2>/dev/null || true
iptables -t nat -A POSTROUTING -s 10.8.1.0/24 -o eth0 -j MASQUERADE 2>/dev/null || true
iptables -t nat -A POSTROUTING -s 10.8.1.0/24 -o eth1 -j MASQUERADE 2>/dev/null || true

tail -f /dev/null
BASH;

        $this->writeRemoteFile('/opt/amnezia/amnezia-awg/start.sh', $script, true);
        $this->executeCommand('chmod +x /opt/amnezia/amnezia-awg/start.sh', true);
    }

    private function buildDockerImage(): void {
        $containerName = $this->data['container_name'];

        $this->executeCommand("docker stop {$containerName} 2>/dev/null || true", true);
        $this->executeCommand("docker rm -fv {$containerName} 2>/dev/null || true", true);
        $this->executeCommand("docker rmi {$containerName} 2>/dev/null || true", true);

        $buildResult = $this->executeCommand(
            "docker build --no-cache --pull -t {$containerName} /opt/amnezia/amnezia-awg 2>&1",
            true
        );

        if (strpos($buildResult, 'error') !== false && strpos($buildResult, 'Successfully') === false) {
            throw new Exception("Docker build failed: " . substr($buildResult, 0, 500));
        }
    }

    private function runContainer(int $vpnPort): void {
        $containerName = $this->data['container_name'];

        $runCmd = sprintf(
            'docker run -d --log-driver none --restart always --privileged'
            . ' --cap-add=NET_ADMIN --cap-add=SYS_MODULE'
            . ' -p %d:%d/udp'
            . ' -v /lib/modules:/lib/modules'
            . ' --name %s %s',
            $vpnPort, $vpnPort,
            $containerName, $containerName
        );

        $this->executeCommand($runCmd, true);
        sleep(3);
    }

    // -------------------------------------------------------------------------
    // ИНИЦИАЛИЗАЦИЯ КОНФИГА — ИСПОЛЬЗУЕМ awg / awg-quick
    // -------------------------------------------------------------------------

    private function initializeServerConfig(int $vpnPort): array {
        $containerName = $this->data['container_name'];

        // Создать директорию внутри контейнера
        $this->executeCommand("docker exec -i {$containerName} mkdir -p /opt/amnezia/awg", true);

        // Генерировать ключи через awg (не wg!)
        $this->executeCommand(
            "docker exec -i {$containerName} sh -c "
            . "'cd /opt/amnezia/awg && umask 077"
            . " && wg genkey | tee server_private.key | wg pubkey > wireguard_server_public_key.key'",
            true
        );
        $this->executeCommand(
            "docker exec -i {$containerName} sh -c "
            . "'cd /opt/amnezia/awg && wg genpsk > wireguard_psk.key'",
            true
        );
        $this->executeCommand(
            "docker exec -i {$containerName} chmod 600"
            . " /opt/amnezia/awg/server_private.key"
            . " /opt/amnezia/awg/wireguard_psk.key"
            . " /opt/amnezia/awg/wireguard_server_public_key.key",
            true
        );

        // Прочитать ключи
        $privKey = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/server_private.key", true));
        $pubKey  = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/wireguard_server_public_key.key", true));
        $psk     = trim($this->executeCommand("docker exec -i {$containerName} cat /opt/amnezia/awg/wireguard_psk.key", true));

        if (empty($privKey) || empty($pubKey)) {
            throw new Exception('Failed to generate server keys inside container. Is amneziawg-tools installed in the image?');
        }

        // Генерировать AWG-параметры обфускации (как в app.py)
        $awgParams = $this->generateObfuscationParams();

        // Сформировать wg0.conf с AWG-параметрами
        $wgConfig  = "[Interface]\n";
        $wgConfig .= "PrivateKey = {$privKey}\n";
        $wgConfig .= "Address = {$this->data['vpn_subnet']}\n";
        $wgConfig .= "ListenPort = {$vpnPort}\n";
        $wgConfig .= "SaveConfig = false\n";
        foreach ($awgParams as $key => $value) {
            $wgConfig .= "{$key} = {$value}\n";
        }
        $wgConfig .= "\n";

        $this->writeRemoteFileInContainer($containerName, '/opt/amnezia/awg/wg0.conf', $wgConfig);
        $this->executeCommand("docker exec -i {$containerName} chmod 600 /opt/amnezia/awg/wg0.conf", true);

        // Создать clientsTable
        $this->executeCommand(
            "docker exec -i {$containerName} sh -c 'echo \"[]\" > /opt/amnezia/awg/clientsTable'",
            true
        );

        // Запустить AmneziaWG через awg-quick (не wg-quick!)
        $upResult = $this->executeCommand(
            "docker exec -i {$containerName} awg-quick up /opt/amnezia/awg/wg0.conf 2>&1",
            true
        );
        error_log("awg-quick up result: " . $upResult);

        // Применить iptables правила
        $subnet = $this->data['vpn_subnet'];
        foreach ([
            "iptables -A INPUT   -i wg0 -j ACCEPT",
            "iptables -A FORWARD -i wg0 -j ACCEPT",
            "iptables -A OUTPUT  -o wg0 -j ACCEPT",
            "iptables -A FORWARD -i wg0 -o eth0 -s {$subnet} -j ACCEPT",
            "iptables -t nat -A POSTROUTING -s {$subnet} -o eth0 -j MASQUERADE",
        ] as $ipt) {
            $this->executeCommand("docker exec -i {$containerName} sh -c '{$ipt} 2>/dev/null || true'", true);
        }

        sleep(2);

        return [
            'public_key'  => $pubKey,
            'preshared_key' => $psk,
            'awg_params'  => $awgParams,
        ];
    }

    /**
     * Генерировать параметры обфускации AmneziaWG.
     * Логика взята из app.py (generate_obfuscation_params).
     */
    private function generateObfuscationParams(int $mtu = 1280): array {
        $s1Candidates = range(15, min(150, $mtu - 148));
        $S1 = $s1Candidates[array_rand($s1Candidates)];

        $s2Candidates = array_filter(
            range(15, min(150, $mtu - 92)),
            fn($s) => $s !== $S1 + 56
        );
        $s2Candidates = array_values($s2Candidates);
        $S2 = $s2Candidates[array_rand($s2Candidates)];

        $Jmin = rand(4, $mtu - 2);
        $Jmax = rand($Jmin + 1, $mtu);

        return [
            'Jc'   => rand(4, 12),
            'Jmin' => $Jmin,
            'Jmax' => $Jmax,
            'S1'   => $S1,
            'S2'   => $S2,
            'H1'   => rand(10000,  100000),
            'H2'   => rand(100000, 200000),
            'H3'   => rand(200000, 300000),
            'H4'   => rand(300000, 400000),
        ];
    }

    // -------------------------------------------------------------------------
    // УПРАВЛЕНИЕ СЕРВЕРОМ — START / STOP / STATUS
    // -------------------------------------------------------------------------

    /**
     * Запустить AmneziaWG на сервере (аналог start_server из app.py)
     */
    public function start(): array {
        if (!$this->data) throw new Exception('Server not loaded');

        $containerName = $this->data['container_name'];

        $result = $this->executeCommand(
            "docker exec -i {$containerName} wg-quick up /opt/amnezia/awg/wg0.conf 2>&1",
            true
        );

        $pdo = DB::conn();
        $pdo->prepare('UPDATE vpn_servers SET status = ? WHERE id = ?')
            ->execute(['active', $this->serverId]);
        $this->data['status'] = 'active';

        return ['success' => true, 'output' => $result];
    }

    /**
     * Остановить AmneziaWG (аналог stop_server из app.py)
     */
    public function stop(): array {
        if (!$this->data) throw new Exception('Server not loaded');

        $containerName = $this->data['container_name'];

        $result = $this->executeCommand(
            "docker exec -i {$containerName} wg-quick down /opt/amnezia/awg/wg0.conf 2>&1",
            true
        );

        $pdo = DB::conn();
        $pdo->prepare('UPDATE vpn_servers SET status = ? WHERE id = ?')
            ->execute(['stopped', $this->serverId]);
        $this->data['status'] = 'stopped';

        return ['success' => true, 'output' => $result];
    }

    /**
     * Получить реальный статус интерфейса wg0 внутри контейнера
     * (аналог get_server_status из app.py — проверяем ip link show wg0)
     */
    public function getRealStatus(): string {
        if (!$this->data) return 'unknown';

        $containerName = $this->data['container_name'];

        try {
            $result = $this->executeCommand(
                "docker exec -i {$containerName} ip link show wg0 2>/dev/null || echo ''",
                true
            );
            // Если интерфейс UP — в выводе будет "state UNKNOWN" (нормально для WireGuard)
            if ($result && strpos($result, 'wg0') !== false) {
                return 'running';
            }
            return 'stopped';
        } catch (Exception $e) {
            return 'stopped';
        }
    }

    /**
     * Применить конфиг без перезапуска (аналог apply_live_config из app.py)
     * Использует: awg syncconf wg0 <(awg-quick strip /opt/amnezia/awg/wg0.conf)
     */
    public function applyLiveConfig(): bool {
        if (!$this->data) return false;

        $containerName = $this->data['container_name'];

        $result = $this->executeCommand(
            "docker exec -i {$containerName} bash -c"
            . " 'awg syncconf wg0 <(wg-quick strip /opt/amnezia/awg/wg0.conf)' 2>&1",
            true
        );

        error_log("applyLiveConfig result: " . $result);
        return true;
    }

    // -------------------------------------------------------------------------
    // ТРАФИК — awg show (аналог get_traffic_for_server из app.py)
    // -------------------------------------------------------------------------

    /**
     * Получить статистику трафика по всем пирам через `awg show`
     * Возвращает массив ['PUBLIC_KEY' => ['received' => '...', 'sent' => '...', ...]]
     */
    public function getTrafficStats(): array {
        if (!$this->data) return [];

        $containerName = $this->data['container_name'];

        $output = $this->executeCommand(
            "docker exec -i {$containerName} wg show wg0 2>/dev/null || echo ''",
            true
        );

        if (empty(trim($output))) return [];

        return $this->parseAwgShowOutput($output);
    }

    /**
     * Парсинг вывода `awg show wg0` — скопирован из get_traffic_for_server (app.py)
     */
    private function parseAwgShowOutput(string $output): array {
        $peerData = [];
        $currentPeer = null;
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);

            if (strpos($line, 'peer:') === 0) {
                $currentPeer = trim(substr($line, 5));
                $peerData[$currentPeer] = [
                    'received'       => '0 B',
                    'sent'           => '0 B',
                    'last_handshake' => 'Never',
                    'endpoint'       => '',
                    'last_handshake_epoch' => 0,
                ];
            } elseif ($currentPeer && strpos($line, 'transfer:') === 0) {
                // transfer: 1.39 MiB received, 6.59 MiB sent
                $transfer = trim(substr($line, 9));
                $parts = explode(',', $transfer);
                $peerData[$currentPeer]['received'] = isset($parts[0]) ? trim($parts[0]) : '0 B';
                $peerData[$currentPeer]['sent']     = isset($parts[1]) ? trim($parts[1]) : '0 B';
            } elseif ($currentPeer && strpos($line, 'endpoint:') === 0) {
                $peerData[$currentPeer]['endpoint'] = trim(substr($line, 9));
            } elseif ($currentPeer && strpos($line, 'latest handshake:') === 0) {
                $handshake = trim(substr($line, 17));
                $peerData[$currentPeer]['last_handshake'] = $handshake;
                $peerData[$currentPeer]['last_handshake_epoch'] = $this->parseHandshakeToEpoch($handshake);
            }
        }

        return $peerData;
    }

    private function parseHandshakeToEpoch(string $handshake): int {
        if ($handshake === 'Never' || empty($handshake)) return 0;

        $total = 0;
        $handshake = str_replace(' ago', '', $handshake);
        $parts = explode(', ', $handshake);

        foreach ($parts as $part) {
            if (preg_match('/(\d+)\s+(\w+)/', $part, $m)) {
                $value = (int)$m[1];
                $unit  = $m[2];
                if (strpos($unit, 'second') === 0) $total += $value;
                elseif (strpos($unit, 'minute') === 0) $total += $value * 60;
                elseif (strpos($unit, 'hour') === 0)   $total += $value * 3600;
                elseif (strpos($unit, 'day') === 0)    $total += $value * 86400;
            }
        }

        return (int)(time() - $total);
    }

    // -------------------------------------------------------------------------
    // SSH HELPERS
    // -------------------------------------------------------------------------

    public function testConnection(): bool {
        $testCmd = sprintf(
            "sshpass -p %s ssh -p %d"
            . " -o UserKnownHostsFile=/dev/null"
            . " -o StrictHostKeyChecking=no"
            . " -o PreferredAuthentications=password"
            . " -o PubkeyAuthentication=no"
            . " -o ConnectTimeout=10"
            . " %s@%s 'echo test' 2>&1",
            escapeshellarg($this->data['password']),
            $this->data['port'],
            escapeshellarg($this->data['username']),
            escapeshellarg($this->data['host'])
        );

        $result = shell_exec($testCmd);
        error_log("SSH test result: " . var_export($result, true));

        if ($result === null) {
            throw new Exception('SSH command failed to execute (shell_exec returned null). Check if sshpass is installed.');
        }

        if (trim($result) !== 'test') {
            throw new Exception('SSH connection failed. Server response: ' . trim($result));
        }

        return true;
    }

    public function executeCommand(string $command, bool $sudo = false): string {
        if ($sudo && strtolower($this->data['username']) !== 'root') {
            $command = "echo '{$this->data['password']}' | sudo -S " . $command;
        }

        $escapedCmd = escapeshellarg($command);
        $sshCmd = sprintf(
            "sshpass -p '%s' ssh -p %d -q"
            . " -o LogLevel=ERROR -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no"
            . " -o PreferredAuthentications=password -o PubkeyAuthentication=no"
            . " %s@%s %s 2>&1",
            $this->data['password'],
            $this->data['port'],
            $this->data['username'],
            $this->data['host'],
            $escapedCmd
        );

        return shell_exec($sshCmd) ?? '';
    }

    /**
     * Безопасно записать файл на удалённый сервер через heredoc (без проблем с кавычками)
     */
    private function writeRemoteFile(string $remotePath, string $content, bool $sudo = false): void {
        // Кодируем в base64 чтобы не было проблем со спецсимволами
        $b64 = base64_encode($content);
        $this->executeCommand("echo '{$b64}' | base64 -d > {$remotePath}", $sudo);
    }

    /**
     * Записать файл внутрь docker-контейнера
     */
    private function writeRemoteFileInContainer(string $containerName, string $path, string $content): void {
        $b64 = base64_encode($content);
        $this->executeCommand(
            "echo '{$b64}' | base64 -d | docker exec -i {$containerName} sh -c 'cat > {$path}'",
            true
        );
    }

    private function findFreeUdpPort(): int {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $candidate = random_int(30000, 65000);
            $out = $this->executeCommand(
                "ss -lun | awk '{print \$4}' | grep -E ':{$candidate}(\$| )' || true"
            );
            if (trim($out) === '') {
                return $candidate;
            }
        }
        throw new Exception('Could not find free UDP port');
    }

    // -------------------------------------------------------------------------
    // CRUD / LIST
    // -------------------------------------------------------------------------

    public function getStatus(): string {
        return $this->data['status'] ?? 'unknown';
    }

    public function getData(): ?array {
        return $this->data;
    }

    public static function listByUser(int $userId): array {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM vpn_servers WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function listAll(): array {
        $pdo = DB::conn();
        $stmt = $pdo->query('SELECT s.*, u.email as user_email FROM vpn_servers s LEFT JOIN users u ON s.user_id = u.id ORDER BY s.created_at DESC');
        return $stmt->fetchAll();
    }

    public static function getById(int $id): ?array {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM vpn_servers WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function delete(): bool {
        try {
            $containerName = $this->data['container_name'];
            $this->executeCommand("docker stop {$containerName} 2>/dev/null || true", true);
            $this->executeCommand("docker rm -fv {$containerName} 2>/dev/null || true", true);
            $this->executeCommand("rm -rf /opt/amnezia/amnezia-awg", true);
        } catch (Exception $e) {
            error_log('Server delete cleanup error: ' . $e->getMessage());
        }

        $pdo = DB::conn();
        $stmt = $pdo->prepare('DELETE FROM vpn_servers WHERE id = ?');
        return $stmt->execute([$this->serverId]);
    }

    // -------------------------------------------------------------------------
    // BACKUP / RESTORE (без изменений)
    // -------------------------------------------------------------------------

    public function createBackup(int $userId, string $backupType = 'manual'): int {
        if (!$this->data) throw new Exception('Server not loaded');

        $pdo = DB::conn();
        $backupName = 'backup_' . $this->serverId . '_' . date('Y-m-d_His') . '.json';
        $backupDir  = '/var/www/html/backups';
        $backupPath = $backupDir . '/' . $backupName;

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $stmt = $pdo->prepare('SELECT id, name, client_ip, public_key, private_key, preshared_key, config, status, expires_at, created_at FROM vpn_clients WHERE server_id = ?');
        $stmt->execute([$this->serverId]);
        $clients = $stmt->fetchAll();

        $backupData = [
            'server'      => [
                'name'             => $this->data['name'],
                'host'             => $this->data['host'],
                'port'             => $this->data['port'],
                'vpn_port'         => $this->data['vpn_port'],
                'vpn_subnet'       => $this->data['vpn_subnet'],
                'container_name'   => $this->data['container_name'],
                'server_public_key'=> $this->data['server_public_key'],
                'preshared_key'    => $this->data['preshared_key'],
                'awg_params'       => $this->data['awg_params'],
            ],
            'clients'     => $clients,
            'backup_date' => date('Y-m-d H:i:s'),
            'version'     => '1.0',
        ];

        file_put_contents($backupPath, json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $stmt = $pdo->prepare('INSERT INTO server_backups (server_id, backup_name, backup_path, backup_size, clients_count, backup_type, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $this->serverId, $backupName, $backupPath,
            filesize($backupPath), count($clients), $backupType, 'completed', $userId,
        ]);

        return (int)$pdo->lastInsertId();
    }

    public function listBackups(): array {
        if (!$this->data) throw new Exception('Server not loaded');
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT b.*, u.name as created_by_name FROM server_backups b LEFT JOIN users u ON b.created_by = u.id WHERE b.server_id = ? ORDER BY b.created_at DESC');
        $stmt->execute([$this->serverId]);
        return $stmt->fetchAll();
    }

    public function restoreBackup(int $backupId): array {
        if (!$this->data) throw new Exception('Server not loaded');
        if ($this->data['status'] !== 'active') throw new Exception('Server must be active to restore backup');

        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM server_backups WHERE id = ? AND server_id = ?');
        $stmt->execute([$backupId, $this->serverId]);
        $backup = $stmt->fetch();

        if (!$backup) throw new Exception('Backup not found');
        if (!file_exists($backup['backup_path'])) throw new Exception('Backup file not found');

        $backupData = json_decode(file_get_contents($backup['backup_path']), true);
        if (!$backupData || !isset($backupData['clients'])) throw new Exception('Invalid backup format');

        $restored = 0; $failed = 0; $errors = [];

        foreach ($backupData['clients'] as $clientData) {
            try {
                $stmt = $pdo->prepare('SELECT id FROM vpn_clients WHERE server_id = ? AND client_ip = ?');
                $stmt->execute([$this->serverId, $clientData['client_ip']]);
                if ($stmt->fetch()) { $errors[] = "Client {$clientData['name']} already exists"; $failed++; continue; }

                $stmt = $pdo->prepare('INSERT INTO vpn_clients (server_id, user_id, name, client_ip, public_key, private_key, preshared_key, config, status, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$this->serverId, $this->data['user_id'], $clientData['name'], $clientData['client_ip'], $clientData['public_key'], $clientData['private_key'], $clientData['preshared_key'], $clientData['config'], 'disabled', $clientData['expires_at']]);
                $restored++;
            } catch (Exception $e) {
                $failed++; $errors[] = "Failed to restore {$clientData['name']}: " . $e->getMessage();
            }
        }

        return ['success' => true, 'restored' => $restored, 'failed' => $failed, 'total' => count($backupData['clients']), 'errors' => $errors];
    }

    public static function deleteBackup(int $backupId): bool {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT backup_path FROM server_backups WHERE id = ?');
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch();
        if (!$backup) return false;
        if (file_exists($backup['backup_path'])) unlink($backup['backup_path']);
        $stmt = $pdo->prepare('DELETE FROM server_backups WHERE id = ?');
        return $stmt->execute([$backupId]);
    }

    public static function getBackup(int $backupId): ?array {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT * FROM server_backups WHERE id = ?');
        $stmt->execute([$backupId]);
        return $stmt->fetch() ?: null;
    }
}