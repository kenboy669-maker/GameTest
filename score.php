<?php
header('Content-Type: application/json; charset=utf-8');

define('DB_HOST', '127.0.0.1');
define('DB_USER', 'kenboy669');
define('DB_PASS', 'kenpass');
define('DB_NAME', 'gamedb');
define('DB_TABLE', 'taball_rank');

function getDbConnection(): mysqli {
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($mysqli->connect_errno) {
        http_response_code(500);
        echo json_encode(['message' => '資料庫連線失敗: ' . $mysqli->connect_error], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$mysqli->set_charset('utf8mb4')) {
        http_response_code(500);
        echo json_encode(['message' => '無法設定資料庫編碼'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $mysqli;
}

function ensureTableExists(mysqli $mysqli): void {
    $sql = "CREATE TABLE IF NOT EXISTS `" . DB_TABLE . "` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(100) NOT NULL,
        `score` INT NOT NULL DEFAULT 0,
        `time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$mysqli->query($sql)) {
        http_response_code(500);
        echo json_encode(['message' => '無法建立或存取排行榜資料表: ' . $mysqli->error], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function fetchTopScores(mysqli $mysqli, int $limit = 10): array {
    $stmt = $mysqli->prepare("SELECT `id`, `name`, `score`, `time` FROM `" . DB_TABLE . "` ORDER BY `score` DESC, `time` ASC LIMIT ?");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['message' => '查詢失敗: ' . $mysqli->error], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $scores = [];

    while ($row = $result->fetch_assoc()) {
        $scores[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'score' => (int)$row['score'],
            'time' => $row['time'],
        ];
    }

    $stmt->close();
    return $scores;
}

function insertScore(mysqli $mysqli, string $name, int $score): void {
    $stmt = $mysqli->prepare("INSERT INTO `" . DB_TABLE . "` (`name`, `score`) VALUES (?, ?)");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['message' => '分數儲存失敗: ' . $mysqli->error], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $name = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $stmt->bind_param('si', $name, $score);
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['message' => '分數儲存失敗: ' . $stmt->error], JSON_UNESCAPED_UNICODE);
        $stmt->close();
        exit;
    }
    $stmt->close();
}

function clearScores(mysqli $mysqli): void {
    $sql = "TRUNCATE TABLE `" . DB_TABLE . "`";
    if (!$mysqli->query($sql)) {
        http_response_code(500);
        echo json_encode(['message' => '清空排行榜失敗: ' . $mysqli->error], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$mysqli = getDbConnection();
ensureTableExists($mysqli);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $scores = fetchTopScores($mysqli);
    echo json_encode(['scores' => $scores], JSON_UNESCAPED_UNICODE);
    $mysqli->close();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'clear') {
    clearScores($mysqli);
    echo json_encode(['message' => '排行榜已清空', 'scores' => []], JSON_UNESCAPED_UNICODE);
    $mysqli->close();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    $mysqli->close();
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$name = trim((string)($payload['name'] ?? ''));
$score = (int)($payload['score'] ?? 0);

if ($name === '' || mb_strlen($name) > 20) {
    http_response_code(422);
    echo json_encode(['message' => '玩家名稱必填且不可超過 20 字'], JSON_UNESCAPED_UNICODE);
    $mysqli->close();
    exit;
}

if ($score < 0) {
    http_response_code(422);
    echo json_encode(['message' => '分數格式錯誤'], JSON_UNESCAPED_UNICODE);
    $mysqli->close();
    exit;
}

insertScore($mysqli, $name, $score);
$scores = fetchTopScores($mysqli);

echo json_encode(['message' => '分數已儲存', 'scores' => $scores], JSON_UNESCAPED_UNICODE);
$mysqli->close();
