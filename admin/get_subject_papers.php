<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();

header('Content-Type: application/json; charset=utf-8');

$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

if ($subject_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM subject_papers WHERE subject_id = ? ORDER BY id DESC");
        $stmt->execute([$subject_id]);
        $papers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'papers' => $papers]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => '数据库查询失败']);
    }
} else {
    echo json_encode(['success' => true, 'papers' => []]);
}
