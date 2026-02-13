<?php
require 'db.php';

/* ====== USERS ====== */
$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT UNIQUE,
  password TEXT,
  role TEXT,
  active INTEGER DEFAULT 1
)");

/* ====== STUDENTS ====== */
$pdo->exec("
CREATE TABLE IF NOT EXISTS students (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  sbd TEXT UNIQUE,
  hoten TEXT,
  ngaysinh TEXT,
  lop TEXT,
  truong TEXT
)");

/* ====== SUBJECTS ====== */
$pdo->exec("
CREATE TABLE IF NOT EXISTS subjects (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ma_mon TEXT UNIQUE,
  ten_mon TEXT,
  he_so REAL
)");

/* ====== EXAMS ====== */
$pdo->exec("
CREATE TABLE IF NOT EXISTS exams (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ten_ky_thi TEXT,
  nam INTEGER,
  ngay_thi TEXT,
  distribution_locked INTEGER DEFAULT 0,
  rooms_locked INTEGER DEFAULT 0,
  exam_locked INTEGER DEFAULT 0
)");

/* ====== SCORES ====== */
$pdo->exec("
CREATE TABLE IF NOT EXISTS scores (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  exam_id INTEGER,
  student_id INTEGER,
  subject_id INTEGER,
  diem REAL,
  scorer_id INTEGER,
  updated_at TEXT
)");

echo "✅ Tables created<br>";

/* ====== IMPORT XML ====== */
function importXML($file, $callback) {
    if (!file_exists($file)) return;
    $xml = simplexml_load_file($file);
    foreach ($xml->children() as $node) {
        $callback($node);
    }
}

/* STUDENTS */
importXML("../xml/hocsinh.xml", function($hs) use ($pdo) {
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO students VALUES(NULL,?,?,?,?,?)");
    $stmt->execute([
        (string)$hs->SBD,
        (string)$hs->HOTEN,
        (string)$hs->NGAYSINH,
        (string)$hs->LOP,
        (string)$hs->TRUONG
    ]);
});

/* SUBJECTS */
importXML("../xml/MON.xml", function($m) use ($pdo) {
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO subjects VALUES(NULL,?,?,?)");
    $stmt->execute([
        (string)$m->MA_MON,
        (string)$m->TEN_MON,
        (float)$m->HE_SO
    ]);
});

echo "✅ XML imported";


$pdo->exec("
CREATE TABLE IF NOT EXISTS score_assignments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  exam_id INTEGER,
  subject_id INTEGER,
  khoi TEXT,
  room_id INTEGER NULL,
  user_id INTEGER
)");
