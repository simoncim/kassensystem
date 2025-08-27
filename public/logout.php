<?php
/**
 * Kassensystem – Logout
 * ---------------------------------------------
 * Zweck: Benutzer abmelden und zurück zur Login-Seite leiten.
 */

require_once __DIR__ . '/../auth.php'; // stellt logout() bereit (Session-Ende, Cookies etc.)

logout();                               // Benutzer abmelden (Session zerstören/invalidieren)

// Nach erfolgreichem Logout auf die Login-Seite umleiten.
// Wichtig: Keine Ausgabe vor Header(), sonst Warning „headers already sent“.
header("Location: login.php");
exit;                                   // Skript beenden 
