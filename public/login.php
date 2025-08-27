<?php
/**
 * Kassensystem – Loginseite
 * ---------------------------------------------
 * Zweck: Benutzeranmeldung und Weiterleitung zur Verkaufsansicht (sale.php)
 */

require_once __DIR__ . '/../auth.php';      // Auth-Funktionen (current_user(), login(), etc.)
require_once __DIR__ . '/../helpers.php';   // Hilfsfunktionen (z. B. e() zum Escapen)

// Wenn bereits eingeloggt ist, Benutzer direkt zur Verkaufsseite leiten
// (verhindert erneute Anzeige der Loginmaske bei bestehender Session)
if (current_user()) {
    header("Location: sale.php"); // HTTP-Weiterleitung
    exit;                           // Skriptausführung sofort beenden
}

$error = ''; // Container für Fehlermeldung (leer = kein Fehler)

// Wurde das Formular abgesendet? (HTTP POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rohwerte aus dem Formular holen
    // Hinweis: Hier bewusst keine Veränderung (trim/normalize) – die Funktion login()
    //          sollte selbst sicher mit Eingaben umgehen 
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    // Anmeldeversuch: login() sollte intern Passwort-Hash prüfen (password_verify o.ä.)
    if (login($username, $password)) {
        // Erfolgreich: auf die Verkaufsseite umleiten
        header("Location: sale.php");
        exit; // Abbruch nach Redirect
    } else {
        // Fehlgeschlagen: generische Fehlermeldung (keine Details preisgeben)
        $error = "Login fehlgeschlagen! Benutzername oder Passwort falsch.";
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8"> <!-- UTF‑8 für Umlaute/ Sonderzeichen -->
    <title>Kassensystem - Login</title>
    <link rel="stylesheet" href="assets/styles.css"> <!-- zentrales Stylesheet -->
</head>
<body class="login-page"> <!-- Body-Klasse (aktuell rein semantisch, kann im CSS genutzt werden) -->
    <div class="login-box"> <!-- Zentrierte Login-Box (Breite/Abstand in CSS definiert) -->
        <div class="logo-und-ueberschrift">
            <!-- Hinweis: In HTML sind Schrägstriche (/) portabler als Backslashes (\\) -->
            <img src="images\bauernglueck_logo.png"> <!-- Pfad beibehalten wie gewünscht -->
            <h1>Bauernglück Hofladen</h1>
        </div>

        <?php if ($error): ?>
            <!-- Fehlermeldung bei falscher Anmeldung -->
            <div class="error-login"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['timeout'])): ?>
            <!-- Info bei Session-Timeout (z. B. wenn auth.php nach Inaktivität ausloggt) -->
            <div class="info">Sitzung abgelaufen. Bitte erneut anmelden.</div>
        <?php endif; ?>

        <!-- Login-Formular: sendet Benutzername/Passwort via POST an dieselbe Seite -->
        <form method="post">
            <label>UserID</label>
            <input type="text" name="username" required>
            <!-- Hinweis: Optional sinnvoll -> autocomplete="username" -->

            <label>Passwort</label>
            <input type="password" name="password" required>

            <button type="submit">Anmelden</button>
        </form>
    </div>
</body>
</html>
