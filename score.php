<?php
header('Content-Type: application/json; charset=utf-8');

class RankService
{
    private PDO $pdo;
    private string $table;
    private string $clientId = "501494712724-dquh9309iefpd8f03r0eaakcgpeurq23.apps.googleusercontent.com";

    // 建構子：初始化資料庫連線並確認排行榜資料表存在。
    public function __construct()
    {
        $host  = getenv('DB_HOST') ?: 'localhost';
        $db    = getenv('DB_DATABASE') ?: 'gamedb';
        $user  = getenv('DB_USER') ?: 'kenboy669';
        $pass  = getenv('DB_PASS') ?: 'kenpass';
        $this->table = getenv('DB_TABLE') ?: 'taball_rank';

        $dsn = 'mysql:host=' . $host . ';dbname=' . $db . ';charset=utf8mb4';
        // var_dump("dsn:$dsn");
        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            $this->fail(500, '資料庫連線失敗: ' . $e->getMessage());
        }

        $this->ensureTableExists();
    }

    // 確保排行榜資料表存在，若不存在則嘗試建立。
    private function ensureTableExists(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->table}` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            `mail` VARCHAR(255) NOT NULL,
            `score` INT NOT NULL DEFAULT 0,
            `time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            $this->fail(500, '無法建立或存取排行榜資料表: ' . $e->getMessage());
        }
    }

    // 依照 HTTP 方法處理請求：GET 讀取排行榜、POST 儲存分數或清空排行榜。
    public function handleRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            if (($_GET['action'] ?? '') === 'user') {
                $tokenInfo = $this->requireGoogleAuth((string)($_GET['idToken'] ?? ''));
                $name = $this->ensureUserRecord($tokenInfo);
                echo json_encode(['name' => $name], JSON_UNESCAPED_UNICODE);
                return;
            }

            $scores = $this->fetchTopScores();
            echo json_encode(['scores' => $scores], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->fail(405, 'Method Not Allowed');
        }

        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        if (($_GET['action'] ?? '') === 'clear') {
            $this->requireGoogleAuth((string)($payload['idToken'] ?? ''));
            $this->clearScores();
            echo json_encode(['message' => '排行榜已清空', 'scores' => []], JSON_UNESCAPED_UNICODE);
            return;
        }

        $name = trim((string)($payload['name'] ?? ''));
        $score = (int)($payload['score'] ?? 0);
        $idToken = (string)($payload['idToken'] ?? '');

        if ($name === '' || mb_strlen($name) > 20) {
            $this->fail(422, '玩家名稱必填且不可超過 20 字');
        }

        if ($score < 0) {
            $this->fail(422, '分數格式錯誤');
        }

        $tokenInfo = $this->requireGoogleAuth($idToken);
        $email = (string)($tokenInfo['email'] ?? '');
        $this->insertScore($name, $score, $email);
        $scores = $this->fetchTopScores();

        echo json_encode(['message' => '分數已儲存', 'scores' => $scores], JSON_UNESCAPED_UNICODE);
    }
    
    // 取得前 N 名排行榜資料，預設限制 10 筆。
    private function fetchTopScores(int $limit = 10): array
    {
        $sql = "SELECT `id`, `name`, `score`, `time`
                FROM `{$this->table}`
                WHERE `score` > 0
                ORDER BY `score` DESC, `time` ASC
                LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $scores = [];
        while ($row = $stmt->fetch()) {
            $scores[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'score' => (int)$row['score'],
                'time' => $row['time'],
            ];
        }

        return $scores;
    }

    private function fetchNameByEmail(string $email): string
    {
        $sql = "SELECT `name`
                FROM `{$this->table}`
                WHERE `mail` = :email
                ORDER BY `time` DESC
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':email' => $email]);

        $row = $stmt->fetch();
        return $row['name'] ?? '';
    }

    private function ensureUserRecord(array $tokenInfo): string
    {
        $email = (string)($tokenInfo['email'] ?? '');
        if ($email === '') {
            return '';
        }

        $name = $this->fetchNameByEmail($email);
        if ($name !== '') {
            return $name;
        }

        $name = trim((string)($tokenInfo['name'] ?? $tokenInfo['given_name'] ?? $email));
        if ($name === '') {
            $name = $email;
        }

        $this->insertUser($name, $email);
        return $name;
    }

    private function insertUser(string $name, string $email): void
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeEmail = filter_var($email, FILTER_SANITIZE_EMAIL);
        $sql = "INSERT INTO `{$this->table}` (`name`, `mail`, `score`) VALUES (:name, :mail, 0)";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':name' => $safeName,
                ':mail' => $safeEmail,
            ]);
        } catch (PDOException $e) {
            // 若已存在相同 email 則忽略這個錯誤
            if ($e->getCode() !== '23000') {
                $this->fail(500, '建立使用者失敗: ' . $e->getMessage());
            }
        }
    }

    // 驗證 Google idToken 是否存在且有效，若驗證成功則回傳 token 內容。
    private function requireGoogleAuth(string $idToken): array
    {
        if ($idToken === '') {
            $this->fail(401, '請先使用 Google 帳號登入。');
        }

        $tokenInfo = $this->getTokenInfo($idToken);
        if (!is_array($tokenInfo)) {
            $this->fail(401, 'Google 登入驗證失敗，請重新登入。');
        }

        return $tokenInfo;
    }

    private function getTokenInfo(string $idToken): ?array
    {
        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return null;
        }

        if ((($data['aud'] ?? '') !== $this->clientId) || empty($data['email'])) {
            return null;
        }

        return $data;
    }

    // 儲存玩家分數到排行榜資料表，並做基本字串過濾。
    private function insertScore(string $name, int $score, string $email): void
    {
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeEmail = filter_var($email, FILTER_SANITIZE_EMAIL);
        $sql = "INSERT INTO `{$this->table}` (`name`, `mail`, `score`) VALUES (:name, :mail, :score)";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':name' => $safeName,
                ':mail' => $safeEmail,
                ':score' => $score,
            ]);
        } catch (PDOException $e) {
            $this->fail(500, '分數儲存失敗: ' . $e->getMessage());
        }
    }

    // 清空排行榜資料表中的所有資料。
    private function clearScores(): void
    {
        $sql = "TRUNCATE TABLE `{$this->table}`";

        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            $this->fail(500, '清空排行榜失敗: ' . $e->getMessage());
        }
    }

    // 發送錯誤回應並結束請求。
    private function fail(int $code, string $message): void
    {
        http_response_code($code);
        echo json_encode(['message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$app = new RankService();
$app->handleRequest();