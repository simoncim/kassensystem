<?php
// ========================================
// config.php - zentrale Einstellungen
// ========================================

// --- MySQL Zugangsdaten (XAMPP Standard: root ohne PW) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'posdb');
define('DB_USER', 'root');
define('DB_PASS', '');

// --- Unternehmensdaten für Bon/Lieferschein (§33 UStDV Mindestangaben) ---
define('COMPANY_NAME', 'Bauernglück Hofladen');
define('COMPANY_STREET', 'Hauptstraße 12');
define('COMPANY_ZIPCITY', '12345 Musterstadt');
define('COMPANY_PHONE', '+49 (0)123 456789');
define('COMPANY_TAXID', 'DE123456789'); // USt-IdNr.

// --- Allgemeine Einstellungen ---
define('CURRENCY', '€');
define('VAT_RATE', 0.07); // 7% z.B. für Lebensmittel
define('SESSION_TIMEOUT', 3600); // Session 1h gültig

// --- Fehleranzeige (für Entwicklung aktivieren, später ausschalten) ---
error_reporting(E_ALL);
ini_set('display_errors', 1);