<?php
require_once __DIR__ . '/../inc/db.inc.php';

$stmt = $pdo->prepare("SELECT id, question_type, question_text, option_a, option_b, option_c, option_d, correct_answer FROM questions WHERE paper_id = 13 ORDER BY id ASC");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Total DB Questions: " . count($rows) . "\n";
$type_counts = [];
foreach ($rows as $idx => $row) {
    $type = $row['question_type'];
    $type_counts[$type] = ($type_counts[$type] ?? 0) + 1;
    printf("[%d] ID: %d | Type: %s | Ans: %s | Text: %s\n", 
        $idx + 1, 
        $row['id'], 
        $type, 
        $row['correct_answer'], 
        mb_substr($row['question_text'], 0, 50)
    );
    if ($row['option_a']) {
        printf("   A: %s | B: %s | C: %s | D: %s\n", $row['option_a'], $row['option_b'], $row['option_c'], $row['option_d']);
    }
}
echo "\nType counts:\n";
print_r($type_counts);
