<?php
header("Content-Type: application/json");
$xmlFile = "MON.XML";

if (!file_exists($xmlFile)) {
    echo json_encode([]);
    exit;
}

$xml = simplexml_load_file($xmlFile);
$result = [];

foreach ($xml->Mon as $mon) {
    $maMon = (string)$mon->MaMon;
    $tenMon = (string)$mon->TenMon;
    $result[$maMon] = $tenMon;
}

echo json_encode($result);
?>
