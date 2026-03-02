<?php
$pdo = new PDO(
    'mysql:host=186.209.113.134;port=3306;dbname=erp_maiscapinhas;charset=utf8mb4',
    'erp_maiscapinhas',
    '*Hssa@kRzsg22g9J'
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== LABELS in cash_closing_lines ===\n";
$stmt = $pdo->query("SELECT label, COUNT(*) as cnt, SUM(system_value) as total_sys, SUM(real_value) as total_real FROM cash_closing_lines GROUP BY label ORDER BY cnt DESC");
foreach ($stmt as $r) {
    echo "  '{$r['label']}' -> {$r['cnt']}x (sys={$r['total_sys']}, real={$r['total_real']})\n";
}
echo "\nDone.\n";
