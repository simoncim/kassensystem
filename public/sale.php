<?php
/**
 * Kassensystem – Verkauf (sale.php)
 * ---------------------------------------------------------
 * Zweck: Artikelsuche/-übernahme, Kundenauswahl,
 *        Checkout (Bon/Lieferschein) inkl. Rabatt.
 */

require_once __DIR__ . '/../auth.php';     // Auth-Utilities: check_auth(), current_user()
require_once __DIR__ . '/../helpers.php';  // Helpers: e(), format_price(), generate_sale_no(), $pdo etc.

check_auth();                 // Zugriff nur für eingeloggte Benutzer zulassen
$user = current_user();       // Aktueller Benutzer (für Belegspeicherung etc.)

// --------------------------------------------------
// Verkaufskontext initialisieren (Session)
// --------------------------------------------------
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];   // Array von Positionen: id, article_no, name, price, quantity
}
if (!isset($_SESSION['sale_ctx'])) {
    $_SESSION['sale_ctx'] = [
        'selected_customer_id' => null  // gewählter Kunde (nur für Lieferschein nötig)
    ];
}

$message = ""; // Meldung/Fehlertexte für UI

// --------------------------------------------------
// Artikel entfernen (per GET ?remove=<index>)
// -> Entfernt Position 
// --------------------------------------------------
if (isset($_GET['remove'])) {
    $idx = (int)$_GET['remove'];
    if (isset($_SESSION['cart'][$idx])) {
        unset($_SESSION['cart'][$idx]);            // Element löschen
        $_SESSION['cart'] = array_values($_SESSION['cart']); // Indizes neu ordnen
    }
}

// --------------------------------------------------
// Artikel per Suche übernehmen (POST) + Menge
// -> Fügt gewählten Artikel hinzu
// -> Redirect zurück auf sale.php mit evtl. Suchparametern
// --------------------------------------------------
if (isset($_POST['add_product_id'])) {
    $pid = (int)$_POST['add_product_id'];
    $qty = max(1, (int)($_POST['qty'] ?? 1));      // Mindestmenge = 1

    $st = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $st->execute([$pid]);
    if ($prod = $st->fetch()) {
        $_SESSION['cart'][] = [
            'id'         => $prod['id'],
            'article_no' => $prod['article_no'],
            'name'       => $prod['name'],
            'price'      => (float)$prod['price'],
            'quantity'   => $qty
        ];
    }
    // Zurück zur Suche (damit die Ergebnisliste erhalten bleibt)
    $qs = [];
    if (!empty($_GET['q_product'])) { $qs['q_product'] = $_GET['q_product']; }
    if (!empty($_GET['q_customer'])) { $qs['q_customer'] = $_GET['q_customer']; }
    $redirect = 'sale.php' . (empty($qs) ? '' : ('?' . http_build_query($qs)));
    header("Location: " . $redirect);
    exit; // Redirect sofort beenden
}

// --------------------------------------------------
// Kunde per Suchergebnis übernehmen (GET set_customer_id)
// -> Speichert die Kunden-ID im Verkaufskontext
// --------------------------------------------------
if (isset($_GET['set_customer_id'])) {
    $cid = (int)$_GET['set_customer_id'];
    $st = $pdo->prepare("SELECT id FROM customers WHERE id = ?");
    $st->execute([$cid]);
    if ($st->fetch()) {
        $_SESSION['sale_ctx']['selected_customer_id'] = $cid; // Kunde setzen
    }
    header("Location: sale.php"); // Seite neu laden (saubere URL ohne set_customer_id)
    exit;
}

// Kunde entfernen (GET clear_customer=1)
if (isset($_GET['clear_customer'])) {
    $_SESSION['sale_ctx']['selected_customer_id'] = null; // Auswahl zurücksetzen
    header("Location: sale.php");
    exit;
}

// --------------------------------------------------
// Verkauf abschließen (POST checkout)
// -> Erzeugt Beleg (sales + sale_items) und leitet zur Druckansicht weiter
// --------------------------------------------------
if (isset($_POST['checkout'])) {
    $payment  = $_POST['payment'] ?? 'cash';

    // Rabatt robust parsen (akzeptiert z. B. "1,50" und "1.50"; filtert Fremdzeichen)
    $discount_raw = $_POST['discount'] ?? '0';
    $discount = (float) str_replace(',', '.', preg_replace('/[^\d,\.\-]/', '', $discount_raw));

    // Kunde bestimmen (nur bei Lieferschein)
    $customer_id = null;
    if ($payment === 'invoice') {
        if (!empty($_SESSION['sale_ctx']['selected_customer_id'])) {
            $customer_id = (int)$_SESSION['sale_ctx']['selected_customer_id'];
        } else {
            $message = "Bitte Kunde über die Suche auswählen (für Lieferschein).";
        }
    }

    if (!$message) {
        if (count($_SESSION['cart']) > 0) {
            $sale_no = generate_sale_no("BON"); // z. B. Prefix BON + laufende Nummer/Zeitstempel

            // Zwischensumme bilden
            $subtotal = 0.0;
            foreach ($_SESSION['cart'] as $item) {
                $subtotal += ((float)$item['price'] * (int)$item['quantity']);
            }

            // Rabatt deckeln: mind. 0, höchstens Zwischensumme
            $discount = max(0.0, min($discount, $subtotal));

            // Endbetrag (Brutto, wenn Preise brutto sind)
            $total = $subtotal - $discount;

            // Verkauf (Kopf) speichern
            $stmt = $pdo->prepare("INSERT INTO sales 
                (sale_no, user_id, customer_id, payment_method, discount, total) 
                VALUES (?,?,?,?,?,?)");
            $stmt->execute([
                $sale_no,
                $user['id'],
                $customer_id,
                $payment,
                $discount,
                $total
            ]);
            $sale_id = $pdo->lastInsertId(); // Primärschlüssel des Belegs

            // Positionen speichern
            $stmt_item = $pdo->prepare("INSERT INTO sale_items 
                (sale_id, product_id, quantity, price) VALUES (?,?,?,?)");
            foreach ($_SESSION['cart'] as $item) {
                $stmt_item->execute([
                    $sale_id,
                    $item['id'],
                    $item['quantity'],
                    $item['price']
                ]);
            }

            // Artikelübersicht leeren und zur Druckseite weiterleiten
            $_SESSION['cart'] = [];
            header("Location: print.php?sale_id=" . $sale_id);
            exit;
        } else {
            $message = "Bitte Artikel auswählen!"; // Wird angezeigt, wenn keine Artikel ausgewählt ist
        }
    }
}

// --------------------------------------------------
// Summen für Anzeige (nur UI, unabhängig von 'total' oben)
// --------------------------------------------------
$sum = 0.0;
foreach ($_SESSION['cart'] as $it) {
    $sum += ((float)$it['price'] * (int)$it['quantity']);
}

// --------------------------------------------------
// Suche: Kunden (GET q_customer)
// -> Einfache LIKE-Suche nach Kundennummer oder Name
// --------------------------------------------------
$search_customer = trim($_GET['q_customer'] ?? '');
$found_customers = [];
if ($search_customer !== '') {
    $like = '%' . $search_customer . '%';
    $st = $pdo->prepare("
        SELECT id, customer_no, name, street, zip, city 
        FROM customers
        WHERE customer_no LIKE ? OR name LIKE ?
        ORDER BY name LIMIT 25
    ");
    $st->execute([$like, $like]);
    $found_customers = $st->fetchAll();
}
// bereits ausgewählten Kunden (falls vorhanden) vollständig laden
$selected_customer = null;
if (!empty($_SESSION['sale_ctx']['selected_customer_id'])) {
    $st = $pdo->prepare("SELECT id, customer_no, name, street, zip, city FROM customers WHERE id = ?");
    $st->execute([$_SESSION['sale_ctx']['selected_customer_id']]);
    $selected_customer = $st->fetch();
}

// --------------------------------------------------
// Suche: Artikel (GET q_product)
// -> LIKE auf Artikelnummer/Bezeichnung, limitierte Trefferliste
// --------------------------------------------------
$search_product = trim($_GET['q_product'] ?? '');
$found_products = [];
if ($search_product !== '') {
    $like = '%' . $search_product . '%';
    $st = $pdo->prepare("
        SELECT id, article_no, name, price 
        FROM products
        WHERE article_no LIKE ? OR name LIKE ?
        ORDER BY name LIMIT 25
    ");
    $st->execute([$like, $like]);
    $found_products = $st->fetchAll();
}

// Für Formular-Default: eingegebener Rabatt beibehalten
$discount_input = $_POST['discount'] ?? '0';
?>


<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Verkauf - Kassensystem</title>
    <link rel="stylesheet" href="assets/styles.css"> <!-- Zentrales Stylesheet -->
</head>
<body>

<?php if ($message): ?>
    <!-- UI-Fehlermeldung (z. B. Kunde fehlt bei Lieferschein) -->
    <div class="error"><?= e($message) ?></div>
<?php endif; ?>

<!-- Kopfbereich mit Menü-Icon (Linien) und Logo -->
<section class="menu-und-logo">
    <div class="menu">
        <div class="line"></div>
        <div class="line"></div>
        <div class="line"></div>
    </div>
    <img src="images/bauernglueck_logo.png"> <!-- Logo (Pfad beibehalten) -->
</section>


<!-- Artikelsuche & Übernahme -->
<section class="add-product">
   
    <!-- Suchformular: Lupe als integrierter Submit-Button, kein separater Button -->
    <form method="get" class="search-form" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap">
        <div class="searchbar-wrapper infield-icon">
        <button type="submit" class="search-icon" aria-label="Suchen" title="Suchen">
        <img src="images\lupe.png"> <!-- Lupe als Bild -->
      
        </button>
        <input type="text" name="q_product"
           value="<?= e($search_product) ?>"
           placeholder="Artikelnummer oder Artikelname"
           class="searchbar search-input" style="min-width:600px">
        </div>
    </form>

    <?php if ($search_product !== ''): ?>
        <?php if (empty($found_products)): ?>
            <p class="muted">Keine Artikel gefunden.</p>
        <?php else: ?>
            <table>
                <tr>
                    <th>Nr.</th>
                    <th>Bezeichnung</th>
                    <th>Preis</th>
                    <th>Menge</th>
                    <th></th>
                </tr>
                <?php foreach ($found_products as $p): ?>
                    <tr>
                        <td><?= e($p['article_no']) ?></td>
                        <td><?= e($p['name']) ?></td>
                        <td><?= format_price($p['price']) ?></td>
                        <td class="right">
                            <!-- Übernahme eines Treffers in die Artikelübersicht (POST) -->
                            <form method="post" action="sale.php<?= $search_product ? ('?'.http_build_query(['q_product'=>$search_product] + (!empty($search_customer)?['q_customer'=>$search_customer]:[]))) : '' ?>" style="display:flex; gap:6px; justify-content:flex-end; align-items:center">
                                <input type="hidden" name="add_product_id" value="<?= (int)$p['id'] ?>">
                                <input type="number" name="qty" min="1" step="1" value="1" style="max-width:90px">
                                <button type="submit">Übernehmen</button>
                            </form>
                        </td>
                        <td></td>
                    </tr>
                <?php endforeach; ?>
            </table>

        <?php endif; ?>
    <?php endif; ?>

    

</section>

<!-- Artikelübersicht -->
<section class="cart">
  
    <table>
        <tr>
            <th>Art.-Nr.</th>
            <th>Artikelname</th>
            <th class="right">Menge</th>
            <th class="right">Preis</th>
            <th class="right">Summe</th>
            <th></th>
        </tr>
        <?php foreach ($_SESSION['cart'] as $i => $item): 
            $line = (float)$item['price'] * (int)$item['quantity']; ?>
            <tr>
                <td><?= e($item['article_no']) ?></td>
                <td><?= e($item['name']) ?></td>
                <td class="right"><?= e($item['quantity']) ?></td>
                <td class="right"><?= format_price((float)$item['price']) ?></td>
                <td class="right"><?= format_price($line) ?></td>
                <td>
                    <!-- Artikel entfernen als Button (GET) mit Erhalt der Filter -->
                    <form method="get" action="sale.php" style="display:inline">
                            <input type="hidden" name="remove" value="<?= $i ?>">
                        <?php if ($search_product !== ''): ?>
                            <input type="hidden" name="q_product" value="<?= e($search_product) ?>">
                        <?php endif; ?>
                        <?php if (!empty($search_category)): ?>
                            <input type="hidden" name="q_category" value="<?= e($search_category) ?>">
                        <?php endif; ?>
                        <?php if ($search_customer !== ''): ?>
                            <input type="hidden" name="q_customer" value="<?= e($search_customer) ?>">
                        <?php endif; ?>
                            <button type="submit" class="btn btn-danger btn-sm">Artikel entfernen</button>
                    </form>
                </td>

            </tr>
        <?php endforeach; ?>
        <tr class="table-line">
            <td colspan="4" style="text-align:left padding:10px"><strong>Gesamt</strong></td>
            <td class="right"><strong><?= format_price($sum) ?></strong></td>
            <td></td>
        </tr>
    </table>
</section>

<!-- TASTEN / KEYPAD: Nummern, Kategorien, Alphabet -->
<section class="tasten">
  <!-- Linkes Nummern-Pad -->
  <div class="keypad">
    <button class="key" data-key=".">.</button>
    <button class="key" data-key="7">7</button>
    <button class="key" data-key="8">8</button>
    <button class="key" data-key="9">9</button>
    <button class="key" data-key="+">+</button>

    <button class="key" data-key=".">.</button>
    <button class="key" data-key="4">4</button>
    <button class="key" data-key="5">5</button>
    <button class="key" data-key="6">6</button>
    <button class="key" data-key="-">-</button>

    <button class="key" data-key="CL">CL</button>
    <button class="key" data-key="1">1</button>
    <button class="key" data-key="2">2</button>
    <button class="key" data-key="3">3</button>
    <button class="key" data-key=".">.</button>

    <button class="key" data-key="ESC">ESC</button>
    <button class="key" data-key="0">0</button>
    <button class="key" data-key="00">00</button>
    <button class="key" data-key="," >,</button>
    <button class="key" data-key=".">.</button>
  </div>

  <!-- Rechte Kategorie-Spalte -->
  <div class="keypad-side">
    <button class="cat-btn" data-key="cat-kom">Kommissionsware</button>
    <button class="cat-btn" data-key="cat-eig">Eigenprodukte</button>
    <button class="cat-btn" data-key="cat-zuk">Zukaufprodukte</button>
    <button class="cat-btn cat-btn--muted" data-key="storno">Storno</button>
  </div>

  <!-- Alphabetisches Feld (DE-Layout: Q W E R T Z ...) -->
<div class="alpha-pad">
  <div class="alpha-row">
    <button class="key alpha-key" data-key="Q">Q</button>
    <button class="key alpha-key" data-key="W">W</button>
    <button class="key alpha-key" data-key="E">E</button>
    <button class="key alpha-key" data-key="R">R</button>
    <button class="key alpha-key" data-key="T">T</button>
    <button class="key alpha-key" data-key="Z">Z</button>
    <button class="key alpha-key" data-key="U">U</button>
    <button class="key alpha-key" data-key="I">I</button>
    <button class="key alpha-key" data-key="O">O</button>
    <button class="key alpha-key" data-key="P">P</button>
  </div>
  <div class="alpha-row">
    <button class="key alpha-key" data-key="A">A</button>
    <button class="key alpha-key" data-key="S">S</button>
    <button class="key alpha-key" data-key="D">D</button>
    <button class="key alpha-key" data-key="F">F</button>
    <button class="key alpha-key" data-key="G">G</button>
    <button class="key alpha-key" data-key="H">H</button>
    <button class="key alpha-key" data-key="J">J</button>
    <button class="key alpha-key" data-key="K">K</button>
    <button class="key alpha-key" data-key="L">L</button>
    <button class="key alpha-key" data-key="Ü">Ü</button>
  </div>
  <div class="alpha-row">
    <button class="key alpha-key" data-key="Y">Y</button>
    <button class="key alpha-key" data-key="X">X</button>
    <button class="key alpha-key" data-key="C">C</button>
    <button class="key alpha-key" data-key="V">V</button>
    <button class="key alpha-key" data-key="B">B</button>
    <button class="key alpha-key" data-key="N">N</button>
    <button class="key alpha-key" data-key="M">M</button>
    <button class="key alpha-key" data-key="Ö">Ö</button>
    <button class="key alpha-key" data-key="Ä">Ä</button>
    <button class="key alpha-key" data-key="ß">ß</button>
  </div>
</div>
<!-- Leerzeichen / Backspace -->
<div class="alpha-row alpha-row--special">

  <!-- breiter Space -->
  <button class="key alpha-key alpha-space" data-key="SPACE">Leerzeichen</button>

  <!-- Backspace -->
  <button class="key alpha-key alpha-back" data-key="BACK">⌫</button>
</div>


</section>

<!-- Kunde wählen / anzeigen -->
<section class="customer">

    <?php if ($selected_customer): ?>
        <!-- Anzeige des gewählten Kunden + Button zum Entfernen -->
        <p>
            <strong><?= e($selected_customer['customer_no']) ?></strong> — 
            <?= e($selected_customer['name']) ?><br>
            <?= e($selected_customer['street']) ?>, 
            <?= e($selected_customer['zip']) ?> <?= e($selected_customer['city']) ?>
        </p>
        <form method="get" action="sale.php" style="display:inline">
            <input type="hidden" name="clear_customer" value="1">
            <?php if ($search_product !== ''): ?>
                <input type="hidden" name="q_product" value="<?= e($search_product) ?>">
            <?php endif; ?>
            <?php if (!empty($search_category)): ?>
                <input type="hidden" name="q_category" value="<?= e($search_category) ?>">
            <?php endif; ?>
            <?php if ($search_customer !== ''): ?>
                <input type="hidden" name="q_customer" value="<?= e($search_customer) ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-danger btn-sm">Kunde entfernen</button>
        </form>

    <?php else: ?>

        <!-- Kundensuche mit integrierter Lupe (Submit) -->
        <form method="get" class="search-form customer-search" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap">
  <div>
    <div class="searchbar-wrapper infield-icon">
      <button type="submit" class="search-icon" aria-label="Suchen" title="Suchen">
        <img src="images\lupe.png">
      </button>

      <input type="text"
             name="q_customer"
             value="<?= e($search_customer) ?>"
             placeholder="Kundennummer oder Kundenname"
             class="searchbar search-input"
             style="min-width:600px">
    </div>
  </div>
</form>


        <?php if ($search_customer !== ''): ?>
            <?php if (empty($found_customers)): ?>
                <p class="muted">Keine Kunden gefunden.</p>
            <?php else: ?>
                <table>
                    <tr>
                        <th>Nr.</th>
                        <th>Name</th>
                        <th>Adresse</th>
                        <th></th>
                    </tr>
                    <?php foreach ($found_customers as $c): ?>
                        <tr>
                            <td><?= e($c['customer_no']) ?></td>
                            <td><?= e($c['name']) ?></td>
                            <td><?= e($c['street']) ?>, <?= e($c['zip']) ?> <?= e($c['city']) ?></td>
                            <td>
                            <!-- Kundenübernahme als Button (GET) mit Erhalt aktueller Filter -->
                            <form method="get" action="sale.php" style="display:inline">
                                <input type="hidden" name="set_customer_id" value="<?= (int)$c['id'] ?>">

                                <?php if ($search_product !== ''): ?>
                                <input type="hidden" name="q_product" value="<?= e($search_product) ?>">
                                <?php endif; ?>
                                <?php if (!empty($search_category)): ?>
                                <input type="hidden" name="q_category" value="<?= e($search_category) ?>">
                                <?php endif; ?>
                                <?php if ($search_customer !== ''): ?>
                                <input type="hidden" name="q_customer" value="<?= e($search_customer) ?>">
                                <?php endif; ?>

                                <button type="submit" class="btn btn-primary btn-sm">Übernehmen</button>
                            </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>



<!-- Checkout (Rabatt, Zahlungsart, Abschluss) -->
<section class="checkout">


    
    <form method="post">
        <label>Rabatt (in Euro)</label>
        <input type="number" step="0.01" inputmode="decimal" name="discount" value="<?= e($discount_input) ?>">

        <?php $selected_payment = $_POST['payment'] ?? 'cash'; ?>
<fieldset class="payment-group">
  

  <label class="radio">
    <input type="radio" name="payment" id="pay-cash" value="cash"
      <?= $selected_payment === 'cash' ? 'checked' : '' ?>>
    <span>Bar</span>
  </label>

  <label class="radio">
    <input type="radio" name="payment" id="pay-ec" value="ec"
      <?= $selected_payment === 'ec' ? 'checked' : '' ?>>
    <span>EC-Card</span>
  </label>

  <label class="radio">
    <input type="radio" name="payment" id="pay-credit" value="credit"
      <?= $selected_payment === 'credit' ? 'checked' : '' ?>>
    <span>Credit-Card</span>
  </label>

  <label class="radio">
    <input type="radio" name="payment" id="pay-invoice" value="invoice"
      <?= $selected_payment === 'invoice' ? 'checked' : '' ?>>
    <span>Lieferschein</span>
  </label>
</fieldset>

        <button type="submit" name="checkout">Bon / Lieferschein drucken</button>
    </form>
</section>


<!-- Datum/Uhrzeit-Anzeige unten -->
<section class="date">
    <?php
        echo ' ';
        echo date('d.m.Y | H:i') . ' Uhr'; // einfache Laufzeitanzeige im UI
    ?>
</section>

<!-- Footer/Statusleiste mit Benutzer und Logout -->
<header>
    <div>Angemeldet als: <?= e($user['full_name']) ?> | <a href="logout.php">Logout</a></div>
</header>

<!-- On-Screen-Keyboard Logik (Nummern, Buchstaben, Steuer, Kategorie-Filter) -->
<script>
(function(){
  // ===== DOM-Referenzen =====
  const addProductSection = document.querySelector('.add-product');
  const searchForm  = addProductSection ? addProductSection.querySelector('form[method="get"]') : null;
  const qInput      = searchForm ? searchForm.querySelector('input[name="q_product"]') : null;
  const catSelect   = searchForm ? searchForm.querySelector('select[name="q_category"]') : null;

  // Kunde wählen – Formular + Feld
  const customerSection = document.querySelector('.customer');
  const customerForm    = customerSection ? customerSection.querySelector('form[method="get"]') : null;
  const qCustomer       = customerForm ? customerForm.querySelector('input[name="q_customer"]') : null;

  const tasten          = document.querySelector('.tasten');
  const discountInput   = document.querySelector('.checkout form input[name="discount"]');

  // Aktives Eingabefeld (Priorität: Artikelsuche -> Kundensuche -> Rabatt)
  let activeInput = qInput || qCustomer || discountInput || null;

  // ===== Fokusziele binden =====
  function bindFocusTargets(){
    if (qInput)     qInput.addEventListener('focus',     () => activeInput = qInput);
    if (qCustomer)  qCustomer.addEventListener('focus',  () => activeInput = qCustomer);
    if (discountInput) discountInput.addEventListener('focus', () => activeInput = discountInput);
    document.querySelectorAll('input[name="qty"]').forEach(inp=>{
      inp.addEventListener('focus', () => activeInput = inp);
    });
  }
  bindFocusTargets();

  // ===== Helpers =====
  function insertText(el, txt){
    if (!el) return;
    if (el.type === 'number') {              // number: Komma->Punkt, andere Zeichen blocken
      if (txt === ',') txt = '.';
      if (/[^0-9.\-]/.test(txt)) return;
    }
    const start = el.selectionStart ?? el.value.length;
    const end   = el.selectionEnd ?? el.value.length;
    const val   = el.value || '';
    el.value = val.slice(0, start) + txt + val.slice(end);
    const pos = start + txt.length;
    if (el.setSelectionRange) el.setSelectionRange(pos, pos);
    el.dispatchEvent(new Event('input', {bubbles:true}));
    el.focus();
  }

  function backspace(el){
    if (!el) return;
    const start = el.selectionStart ?? el.value.length;
    const end   = el.selectionEnd ?? el.value.length;
    const val   = el.value || '';
    if (start !== end) {
      el.value = val.slice(0, start) + val.slice(end);
      if (el.setSelectionRange) el.setSelectionRange(start, start);
    } else if (start > 0) {
      el.value = val.slice(0, start - 1) + val.slice(end);
      const pos = start - 1;
      if (el.setSelectionRange) el.setSelectionRange(pos, pos);
    }
    el.dispatchEvent(new Event('input', {bubbles:true}));
    el.focus();
  }

  function submitWithCategory(cat){
    if (catSelect) {
      catSelect.value = cat;
      if (searchForm) searchForm.submit();
      return;
    }
    const url = new URL(location.href);
    if (cat) url.searchParams.set('q_category', cat); else url.searchParams.delete('q_category');
    location.href = url.toString();
  }

  function submitFormOf(el){
    const form = el ? el.closest('form') : null;
    if (form) form.submit();
  }

  function adjustDiscount(delta){
    if (!discountInput) return;
    const step = parseFloat(discountInput.step || '1') || 1;
    const cur  = parseFloat((discountInput.value || '0').replace(',', '.')) || 0;
    const next = Math.max(0, cur + delta * step);
    discountInput.value = next.toFixed(2);
    discountInput.dispatchEvent(new Event('input', {bubbles:true}));
    discountInput.focus();
    activeInput = discountInput;
  }

  // ===== Click-Handling =====
  tasten?.addEventListener('click', (e)=>{
    const btn = e.target.closest('[data-key]');
    if (!btn) return;
    const key = btn.getAttribute('data-key');

    switch (key) {
      // Steuer
      case 'ESC':
        if (activeInput) {
          activeInput.value = '';
          activeInput.dispatchEvent(new Event('input', {bubbles:true}));
          activeInput.focus();
        }
        break;
      case 'ENTER': // ENTER-Taste
        submitFormOf(activeInput);
        break;
      case ',':
        insertText(activeInput, ','); // number -> wird zu "."
        break;

      // Rabatt +/- nur wenn Rabatt-Feld aktiv
      case '+':
        if (activeInput?.name === 'discount') { adjustDiscount(+1); }
        break;
      case '-':
        if (activeInput?.name === 'discount') { adjustDiscount(-1); }
        break;

      // Ziffern
      case '00':
      case '0': case '1': case '2': case '3': case '4':
      case '5': case '6': case '7': case '8': case '9':
        insertText(activeInput, key);
        break;

      // Kategorien (betrifft Artikelsuche)
      case 'cat-kom': submitWithCategory('Kommissionsware'); break;
      case 'cat-eig': submitWithCategory('Eigenprodukte');   break;
      case 'cat-zuk': submitWithCategory('Zukaufprodukte');  break;

      // Storno: je nach Fokus passende Suche leeren + absenden
      case 'storno':
        if (activeInput === qCustomer) {
          if (qCustomer) qCustomer.value = '';
          submitFormOf(qCustomer);
        } else {
          if (qInput) qInput.value = '';
          submitWithCategory('');
          if (searchForm) searchForm.submit();
        }
        break;

      // Zusatztasten
      case 'SPACE': insertText(activeInput, ' '); break;
      case 'BACK':  backspace(activeInput); break;

      // Buchstaben inkl. Umlaute
      default:
        if (/^[A-Za-zÄÖÜäöüß]$/.test(key)) {
          insertText(activeInput, key);
        }
        break;
    }
  });

  // Nach neu gerenderten Tabellen ggf. qty-Felder neu binden (Delegation)
  document.addEventListener('click', (e)=>{
    if (e.target.closest('table')) bindFocusTargets();
  });
})();
</script>



</body>
</html>
