<?php
declare(strict_types=1);

function classes_ensure_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS classes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        class_name TEXT NOT NULL UNIQUE,
        specialized_subject_id INTEGER NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
}

function classes_normalize_text(string $value): string
{
    $value = trim(mb_strtoupper($value, 'UTF-8'));
    $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($trans !== false) {
        $value = strtoupper($trans);
    }
    return preg_replace('/\s+/', ' ', $value) ?? $value;
}

function classes_detect_specialized_subject_id(string $className, array $subjectNameToId): ?int
{
    $normalized = classes_normalize_text($className);
    $rules = [
        ['keywords' => [' VAN ', ' VĂN ', 'VAN', 'VĂN'], 'subject' => 'NGU VAN'],
        ['keywords' => [' ANH ' , 'ANH'], 'subject' => 'TIENG ANH'],
        ['keywords' => [' LY ', ' LÝ ', 'LY', 'LÝ'], 'subject' => 'VAT LY'],
    ];

    foreach ($rules as $rule) {
        foreach ($rule['keywords'] as $kw) {
            $kwNorm = classes_normalize_text((string) $kw);
            if (str_contains(' ' . $normalized . ' ', ' ' . trim($kwNorm) . ' ')) {
                foreach ($subjectNameToId as $nameNorm => $id) {
                    if (str_contains($nameNorm, (string) $rule['subject'])) {
                        return (int) $id;
                    }
                }
            }
        }
    }

    return null;
}

function classes_sync_from_students(PDO $pdo): array
{
    classes_ensure_schema($pdo);

    $subjects = $pdo->query('SELECT id, ten_mon FROM subjects')->fetchAll(PDO::FETCH_ASSOC);
    $subjectNameToId = [];
    foreach ($subjects as $s) {
        $subjectNameToId[classes_normalize_text((string) ($s['ten_mon'] ?? ''))] = (int) ($s['id'] ?? 0);
    }

    $existing = [];
    foreach ($pdo->query('SELECT class_name FROM classes')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existing[trim((string) ($r['class_name'] ?? ''))] = true;
    }

    $rows = $pdo->query('SELECT DISTINCT trim(lop) AS class_name FROM students WHERE trim(coalesce(lop, "")) <> "" ORDER BY class_name')->fetchAll(PDO::FETCH_ASSOC);

    $insertStmt = $pdo->prepare('INSERT INTO classes (class_name, specialized_subject_id) VALUES (:class_name, :specialized_subject_id)');
    $created = [];
    foreach ($rows as $r) {
        $className = trim((string) ($r['class_name'] ?? ''));
        if ($className === '' || isset($existing[$className])) {
            continue;
        }
        $subjectId = classes_detect_specialized_subject_id($className, $subjectNameToId);
        $insertStmt->execute([
            ':class_name' => $className,
            ':specialized_subject_id' => $subjectId,
        ]);
        $existing[$className] = true;
        $created[] = $className;
    }

    return ['created_count' => count($created), 'created_classes' => $created];
}
