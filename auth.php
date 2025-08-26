<?php
// ========================================
// auth.php - Benutzer-Login & Session
// ========================================

require_once __DIR__ . '/db.php';
session_start();

/**
 * Prüft Login-Daten und setzt Session
 */
function login($username, $password) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && hash('sha256', $password) === $user['password_hash']) {
        $_SESSION['user'] = [
            'id' => $user['id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'role' => $user['role']
        ];
        $_SESSION['last_activity'] = time();
        return true;
    }
    return false;
}

/**
 * Prüft, ob ein Benutzer eingeloggt ist
 */
function check_auth() {
    if (!isset($_SESSION['user'])) {
        header("Location: login.php");
        exit;
    }

    // Session Timeout prüfen
    if (time() - ($_SESSION['last_activity'] ?? 0) > SESSION_TIMEOUT) {
        logout();
        header("Location: login.php?timeout=1");
        exit;
    }
    $_SESSION['last_activity'] = time();
}

/**
 * Gibt eingeloggten Benutzer zurück
 */
function current_user() {
    return $_SESSION['user'] ?? null;
}

/**
 * Loggt den Benutzer aus
 */
function logout() {
    session_unset();
    session_destroy();
}
