<?php
require_once __DIR__ . '/../bootstrap.php';

$dbFile = BASE_PATH . '/data/exam.db';

try {
    $pdo = new PDO("sqlite:$dbFile");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("DB error: " . $e->getMessage());
}


function ensure_core_schema(PDO $pdo): void {
    try {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='exams'")->fetchColumn();
        if (!$tables) {
            return;
        }

        $examColumns = array_column($pdo->query('PRAGMA table_info(exams)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('is_default', $examColumns, true)) {
            $pdo->exec('ALTER TABLE exams ADD COLUMN is_default INTEGER DEFAULT 0');
        }
        if (!in_array('deleted_at', $examColumns, true)) {
            $pdo->exec('ALTER TABLE exams ADD COLUMN deleted_at TEXT');
        }
    } catch (Throwable $e) {
        // Keep DB bootstrap resilient in mixed-version environments.
    }
}

ensure_core_schema($pdo);
