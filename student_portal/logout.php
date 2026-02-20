<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

unset($_SESSION['student_id'], $_SESSION['student_name'], $_SESSION['student_identifier'], $_SESSION['student_exam_default'], $_SESSION['student_portal_csrf']);
header('Location: ' . BASE_URL . '/student_portal/login.php');
exit;
