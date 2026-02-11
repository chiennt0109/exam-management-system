<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: text/plain");

$data = json_decode(file_get_contents("php://input"), true);
if (!$data || !isset($data["xmlData"])) {
    http_response_code(400);
    echo "Không có dữ liệu hợp lệ để lưu.";
    exit;
}

$input = $data["xmlData"];
$file = "TCTHI.XML";

if (is_string($input) && strpos($input, "<ThiSinh") !== false) {
    // Trường hợp import nhiều dòng
    $new = simplexml_load_string($input);
    $xml = file_exists($file) ? simplexml_load_file($file) : new SimpleXMLElement("<TCTHI></TCTHI>");

    foreach ($new->ThiSinh as $ts) {
        $node = $xml->addChild("ThiSinh");
        $node->addChild("MaKyThi", $ts->MaKyThi);
        $node->addChild("MaHS", $ts->MaHS);
        $node->addChild("SBD", $ts->SBD);
        $node->addChild("MaMon", $ts->MaMon);
        $node->addChild("Diem", $ts->Diem);
    }

    $xml->asXML($file);
    echo "Đã thêm dữ liệu import vào TCTHI.XML";
    exit;
}

// Trường hợp nhập tay
if (is_array($input)) {
    $xml = file_exists($file) ? simplexml_load_file($file) : new SimpleXMLElement("<TCTHI></TCTHI>");
    $node = $xml->addChild("ThiSinh");
    $node->addChild("MaKyThi", $input["MaKyThi"]);
    $node->addChild("MaHS", $input["MaHS"]);
    $node->addChild("SBD", $input["SBD"]);
    $node->addChild("MaMon", $input["MaMon"]);
    $node->addChild("Diem", $input["Diem"]);
    $xml->asXML($file);
    echo "Đã lưu 1 dòng dữ liệu thủ công.";
    exit;
}

http_response_code(400);
echo "Dữ liệu không hợp lệ.";
?>
