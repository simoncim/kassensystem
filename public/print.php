<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';


// Funktion für möglichst viele Zeichenverwendung inkl. Umlaute
if (!function_exists('pdf_txt')) {
  function pdf_txt(string $s): string {
    return iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
  }
}

check_auth();
$user = current_user();

// ---- Wichtig: Output-Buffer starten, um versehentliche Ausgaben/Warnungen abzufangen
if (ob_get_level() === 0) {
    ob_start();
}

// FPDF laden
require_once __DIR__ . '/../lib/fpdf.php';

// Sale-ID abrufen/prüfen
$sale_id = isset($_GET['sale_id']) ? (int)$_GET['sale_id'] : 0;
if ($sale_id <= 0) {
    http_response_code(400);
    exit;
}


// Verkauf inkl. Kundendaten laden
$stmt = $pdo->prepare("
    SELECT 
        s.*,
        u.full_name,
        c.customer_no,
        c.name       AS customer_name,
        c.street,
        c.zip,
        c.city,
        c.phone,
        c.email
    FROM sales s
    JOIN users u         ON s.user_id = u.id
    LEFT JOIN customers c ON s.customer_id = c.id
    WHERE s.id = ?
");
$stmt->execute([$sale_id]);
$sale = $stmt->fetch();

if (!$sale) {
    http_response_code(404);
    exit;
}

// Positionen laden
$stmt = $pdo->prepare("
    SELECT si.*, p.id, p.article_no, p.name
    FROM sale_items si
    JOIN products p ON si.product_id = p.id
    WHERE si.sale_id = ?
");
$stmt->execute([$sale_id]);
$items = $stmt->fetchAll();

// PDF erzeugen
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 14);

// === Margins ===
$lm = 20;   // linker Rand in mm
$tm = 15;   // oberer Rand
$rm = 15;   // rechter Rand
$pdf->SetMargins($lm, $tm, $rm);

// Kopf
$pdf->SetFont('Arial', 'B', 15);

if (!empty($sale['customer_name'])) {
    $pdf->Cell(0, 10, 'Lieferschein', 0, 1, 'C');
    $pdf->Ln(3);
}
else {
    $pdf->Cell(0, 10, 'Bon', 0, 1, 'C');
    $pdf->Ln(3);
}

// ==== Headerblock links + Logo rechts ==== 
$logoPath = __DIR__ . '\images\bauernglueck_logo.png'; // Link zu Logo-Datei
$logoW    = 35;    // Logo-Breite in mm
$gap      = 6;     // Abstand Textblock <-> Logo

$pageW = $pdf->GetPageWidth();
$y0    = $pdf->GetY();

$usableW = $pageW - $lm - $rm;
$textW   = $usableW - $logoW - $gap;

// Logo-Höhe aus Bildverhältnis
[$imgWpx, $imgHpx] = getimagesize($logoPath);
$logoH = $logoW * ($imgHpx / $imgWpx);

// Logo rechts oben
$pdf->Image($logoPath, $pageW - $rm - $logoW, $y0, $logoW);


// Absender-Adresse, linksbündig

$pdf->SetTextColor(103, 126, 124);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 6, pdf_txt('Bauernglück Hofladen'), 0, 1);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, 'Harald Schlarb', 0, 1);
$pdf->Cell(0, 6, pdf_txt('Dorfstraße 35'), 0, 1);
$pdf->Cell(0, 6, '13051 Berlin', 0, 1);
$pdf->Cell(0, 6, 'Tel.: 0049 30 123456', 0, 1);
$pdf->Cell(0, 6, 'info@bauernglueck.de', 0, 1);
$pdf->Cell(0, 6, 'www.bauernglueck.de', 0, 1);
$pdf->Cell(0, 6, 'USt-IdNr.: DE123456789', 0, 1);

// unter den höheren der beiden Blöcke weiterschreiben
$yTextEnd = $pdf->GetY();
$yNext    = max($yTextEnd, $y0 + $logoH);
$pdf->SetY($yNext + 4);

// Datum und Zeit
$now = new DateTime('now', new DateTimeZone('Europe/Berlin'));
$dt  = $now->format('d.m.Y | H:i') . ' Uhr';

$pdf->SetFont('Arial', '', 10);
$pdf->Cell(176, 7, pdf_txt($dt), 0, 1, 'R'); 

// Bon-Nr. (auf 13 Ziffern von hinten gekürzt)
$no13 = $sale['sale_no'] ?? (string)$sale_id; // Fallback auf sale_id, falls mal leer
$fullNo = (string)($sale['sale_no'] ?? $sale_id);
$digits = preg_replace('/\D+/', '', $fullNo); // nur 0–9 behalten
$no13   = substr($digits, -13);

if (!empty($sale['customer_name'])) {
    $pdf->Cell(176, 7, pdf_txt('Lieferschein-Nr: ' . $no13), 0, 0, 'R');
    $pdf->Ln(2);
} else {
    $pdf->Cell(176, 7, pdf_txt('Bon-Nr: ' . $no13), 0, 0, 'R');
    $pdf->Ln(10);
}


// Kunde (falls Lieferschein)
if (!empty($sale['customer_name'])) {
    $pdf->Ln(2);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 6, 'Kunde:', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    

    $custLines = [];
    $line1 = trim(($sale['customer_no'] ? 'Kundennummer: ' . $sale['customer_no'] . "\n" : '') . ($sale['customer_name'] ?? ''));
    if ($line1 !== '') { $custLines[] = $line1; }
    if (!empty($sale['street'])) { $custLines[] = $sale['street']; }
    $line2 = trim(($sale['zip'] ?? '') . ' ' . ($sale['city'] ?? ''));
    if ($line2 !== '') { $custLines[] = $line2; }
    if (!empty($sale['phone'])) { $custLines[] = 'Tel.: ' . $sale['phone']; }
    if (!empty($sale['email'])) { $custLines[] = 'E-Mail: ' . $sale['email'] . "\n\n"; }

    $pdf->MultiCell(0, 6, mb_convert_encoding(implode("\n", $custLines), 'ISO-8859-1', 'UTF-8'));
    $pdf->Ln(2);
}

// Tabelle Kopf
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(10, 7, 'Pos.', 0);
$pdf->Cell(25, 7, 'Art.-Nr.', 0);
$pdf->Cell(70, 7, 'Artikelname', 0);
$pdf->Cell(15, 7, 'Menge', 0, 0, 'R');
$pdf->Cell(25, 7, 'Einzelpreis', 0, 0, 'R');
$pdf->Cell(30, 7, 'Summe', 0, 1, 'R');

// Tabelle Inhalt
$pdf->SetFont('Arial', '', 10);
$total = 0.0;
$pos = 0;

foreach ($items as $it) {
    $pos++; // laufende Positionsnummer
    $line = (float)$it['price'] * (float)$it['quantity'];
    $total += $line;

    $pdf->Cell(10, 6, $pos, 0); 
    $pdf->Cell(25, 6, mb_convert_encoding($it['article_no'], 'ISO-8859-1', 'UTF-8'), 0);
    $pdf->Cell(70, 6, mb_convert_encoding($it['name'],       'ISO-8859-1', 'UTF-8'), 0);
    $pdf->Cell(15, 6, number_format((float)$it['quantity'], 2, ',', '.'), 0, 0, 'R');
    $pdf->Cell(25, 6, number_format((float)$it['price'],    2, ',', '.') . ' Euro', 0, 0, 'R');
    $pdf->Cell(30, 6, number_format($line,                  2, ',', '.') . ' Euro', 0, 1, 'R');
}

// Rabatt
if (!empty($sale['discount']) && (float)$sale['discount'] > 0) {
    $pdf->Cell(145, 6, 'Rabatt', 0);
    $pdf->Cell(30, 6, '-' . number_format((float)$sale['discount'], 2, ',', '.') . ' Euro', 0, 1, 'R');
    $total -= (float)$sale['discount'];
    if ($total < 0) $total = 0.0;
}

// Gesamtbetrag
// --- Brutto -> Netto + enthaltener 7% ---
$vatRate = 0.07;
$net     = round($total / (1 + $vatRate), 2);
$vat     = round($total - $net, 2);

// Netto
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(145, 7, 'Zwischensumme (netto)', 0);
$pdf->Cell(30,  7, number_format($net, 2, ',', '.') . ' Euro', 0, 1, 'R');

// Enthaltene 7% MwSt.
$pdf->Cell(145, 7, '7% MwSt.', 0);
$pdf->Cell(30,  7, number_format($vat, 2, ',', '.') . ' Euro', 0, 1, 'R');

// Linie
$lm = 20; $rm = 15;
$y  = $pdf->GetY() + 2; // leicht unter aktueller Zeile
$x1 = $lm;
$x2 = $pdf->GetPageWidth() - $rm;
$pdf->SetDrawColor(203,230,218);
$pdf->Line($x1, $y, $x2, $y);
$pdf->Ln(4); // kleiner Abstand nach der Linie

// Brutto-Gesamt
$pdf->SetTextColor(103, 126, 124);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(145, 7, 'Gesamtbetrag (brutto)', 0);
$pdf->Cell(30,  7, number_format($total, 2, ',', '.') . ' Euro', 0, 1, 'R');

$pdf->Ln(6);

$pdf->MultiCell(0, 6, mb_convert_encoding($hinweis, 'ISO-8859-1', 'UTF-8'));

// ---- Ganz am Ende: Output-Buffer leeren, dann PDF senden
if (ob_get_length()) {
    ob_end_clean();
}
$pdf->Output('I', 'Beleg_' . ($sale['sale_no'] ?? 'unbekannt') . '.pdf');
exit;

