<?php
// ========================================
// config.php - zentrale Einstellungen
// ========================================

// --- MySQL Zugangsdaten (XAMPP Standard: root ohne PW) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'posdb');
define('DB_USER', 'root');
define('DB_PASS', '');

// --- Allgemeine Einstellungen ---
define('CURRENCY', '€');
define('VAT_RATE', 0.07); // 7% z.B. für Lebensmittel
define('SESSION_TIMEOUT', 3600); // Session 1h gültig

// --- Fehleranzeige (für Entwicklung aktivieren, später ausschalten) ---
error_reporting(E_ALL);
ini_set('display_errors', 1);