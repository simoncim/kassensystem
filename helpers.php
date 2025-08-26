<?php
// ========================================
// helpers.php - Hilfsfunktionen für POS
// ========================================

/**
 * Formatiert Preise mit 2 Nachkommastellen + Währung
 */
function format_price($amount) {
    return number_format($amount, 2, ',', '.') . ' ' . CURRENCY;
}

/**
 * Erzeugt eine fortlaufende Bon- oder Lieferschein-Nummer
 * Beispiel: BON-20250820-0001
 */
function generate_sale_no($type = 'BON') {
    global $pdo;

    $date = date('Ymd');
    $prefix = strtoupper($type) . '-' . $date . '-';

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE sale_no LIKE ?");
    $stmt->execute([$prefix . '%']);
    $count = $stmt->fetchColumn() + 1;

    return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
}

/**
 * Einfaches Escaping für HTML-Ausgabe
 */
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}
