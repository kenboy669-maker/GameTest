<?php
header('Content-Type: application/json; charset=utf-8');

class RankService
{
    private PDO $pdo;
    private string $table;

    public function __construct()
    {
        $host  = getenv('DB_HOST') ?: 'localhost';
        $db    = getenv('DB_DATABASE') ?: 'gamedb';
        $user  = getenv('DB_USER') ?: 'kenboy669';
        $pass  = getenv('DB_PASS') ?: 'kenpass';
        $this->table = getenv('DB_TABLE') ?: 'taball_rank';

        $dsn = 'mysql:host=' . $host . ';dbname=' . $db . ';charset=utf8mb4';

        // try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        // } 
        // catch (PDOException $e) {
        //     $this->fail(500, '資料庫連線失敗: ' . $e->getMessage());
        // }
        var_dump("host:$host, db:$db, user:$user, pass:$pass");
    }
}
$app = new RankService();
