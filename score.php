<?php
header('Content-Type: application/json; charset=utf-8');

define('DB_HOST', 'mysql');
define('DB_USER', 'kenboy669');
define('DB_PASS', 'kenpass');
define('DB_NAME', 'gamedb');
define('DB_TABLE', 'taball_rank');

/**
 * 建立資料庫連線物件
 * @return PDO
 */
function getDbConnection(): PDO {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => '資料庫連線失敗: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    return $pdo;
}

/**
 * 確保排行榜資料表存在，若不存在則建立
 *
 * @param PDO $pdo 資料庫連線物件
 */
function ensureTableExists(PDO $pdo): void {
    $sql = "CREATE TABLE IF NOT EXISTS `" . DB_TABLE . "` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(100) NOT NULL,
        `score` INT NOT NULL DEFAULT 0,
        `time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => '無法建立或存取排行榜資料表: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * 取得排行榜前 N 名的分數資料
 * @param PDO $pdo 資料庫連線物件
 * @param int $limit 限制顯示的排名數量
 * @return array<int, array{id: int, name: string, score: int, time: string}>
 */
function fetchTopScores(PDO $pdo, int $limit = 10): array {
    $stmt = $pdo->prepare("SELECT `id`, `name`, `score`, `time` FROM `" . DB_TABLE . "` ORDER BY `score` DESC, `time` ASC LIMIT :limit");

    try {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => '查詢失敗: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }

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

/**
 * 儲存玩家分數
 * @param PDO $pdo 資料庫連線物件
 * @param string $name 玩家名稱
 * @param int $score 分數
 */
function insertScore(PDO $pdo, string $name, int $score): void {
    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $stmt = $pdo->prepare("INSERT INTO `" . DB_TABLE . "` (`name`, `score`) VALUES (:name, :score)");

    try {
        $stmt->execute([
            ':name' => $safeName,
            ':score' => $score,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => '分數儲存失敗: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * 清空排行榜
 * @param PDO $pdo 資料庫連線物件
 * @return void
 */
function clearScores(PDO $pdo): void {
    $sql = "TRUNCATE TABLE `" . DB_TABLE . "`";

    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => '清空排行榜失敗: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$pdo = getDbConnection();
ensureTableExists($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $scores = fetchTopScores($pdo);
    echo json_encode(['scores' => $scores], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'clear') {
    clearScores($pdo);
    echo json_encode(['message' => '排行榜已清空', 'scores' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$name = trim((string)($payload['name'] ?? ''));
$score = (int)($payload['score'] ?? 0);

if ($name === '' || mb_strlen($name) > 20) {
    http_response_code(422);
    echo json_encode(['message' => '玩家名稱必填且不可超過 20 字'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($score < 0) {
    http_response_code(422);
    echo json_encode(['message' => '分數格式錯誤'], JSON_UNESCAPED_UNICODE);
    exit;
}

insertScore($pdo, $name, $score);
$scores = fetchTopScores($pdo);

echo json_encode(['message' => '分數已儲存', 'scores' => $scores], JSON_UNESCAPED_UNICODE);
