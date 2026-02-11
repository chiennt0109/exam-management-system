<?php
require 'core/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (login($_POST['username'], $_POST['password'])) {
        header("Location: index.php");
        exit;
    } else {
        $error = "Sai tài khoản hoặc mật khẩu";
    }
}
?>

<h2>ĐĂNG NHẬP HỆ THỐNG THI</h2>

<form method="post">
    <div>
        <input name="username" placeholder="Tên đăng nhập" required>
    </div>
    <div>
        <input name="password" type="password" placeholder="Mật khẩu" required>
    </div>
    <button type="submit">Đăng nhập</button>
</form>

<p style="color:red"><?= $error ?></p>
