<?php
require_once __DIR__ . '/../bootstrap.php';

$dbFile = BASE_PATH . '/data/exam.db';

try {
    $pdo = new PDO("sqlite:$dbFile");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("DB error: " . $e->getMessage());
}
