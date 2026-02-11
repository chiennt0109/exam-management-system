<?php
header('Content-Type: application/json');

$xmlFile = "TCTHI.XML";
if (!file_exists($xmlFile)) {
    echo json_encode(["error" => "File TCTHI.XML không tồn tại."]);
    exit;
}

$xml = simplexml_load_file($xmlFile);
$subjectStats = [];

// Duyệt từng thí sinh
foreach ($xml->ThiSinh as $thiSinh) {
    $maMon = (string) $thiSinh->MaMon;
    $diem = trim((string) $thiSinh->Diem); // Xử lý điểm dạng chuỗi

    if ($diem === "" || !is_numeric($diem)) continue; // Bỏ qua nếu không có điểm hợp lệ
    $diem = (float) $diem;

    if (!isset($subjectStats[$maMon])) {
        $subjectStats[$maMon] = [
            "total" => 0, "0-2" => 0, "2-4" => 0, "4-6" => 0, "6-8" => 0, "8-10" => 0
        ];
    }

    $subjectStats[$maMon]["total"]++; // Tăng tổng số thí sinh có điểm hợp lệ

    if ($diem < 2) {
        $subjectStats[$maMon]["0-2"]++;
    } elseif ($diem < 4) {
        $subjectStats[$maMon]["2-4"]++;
    } elseif ($diem < 6) {
        $subjectStats[$maMon]["4-6"]++;
    } elseif ($diem < 8) {
        $subjectStats[$maMon]["6-8"]++;
    } else {
        $subjectStats[$maMon]["8-10"]++;
    }
}

// Chuyển sang định dạng JSON
$output = [];
foreach ($subjectStats as $maMon => $data) {
    if ($data["total"] == 0) continue; // Bỏ qua môn học không có dữ liệu hợp lệ

    $output[] = [
        "MaMon" => $maMon,
        "0-2"   => round(($data["0-2"] / $data["total"]) * 100, 2),
        "2-4"   => round(($data["2-4"] / $data["total"]) * 100, 2),
        "4-6"   => round(($data["4-6"] / $data["total"]) * 100, 2),
        "6-8"   => round(($data["6-8"] / $data["total"]) * 100, 2),
        "8-10"  => round(($data["8-10"] / $data["total"]) * 100, 2)
    ];
}

echo json_encode($output);
?>
