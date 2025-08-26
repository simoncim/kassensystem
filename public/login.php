<?php
require_once __DIR__ . '/../auth.php';

// Wenn bereits eingeloggt → weiterleiten
if (current_user()) {
    header("Location: sale.php");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (login($username, $password)) {
        header("Location: sale.php");
        exit;
    } else {
        $error = "Login fehlgeschlagen! Benutzername oder Passwort falsch.";
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Kassensystem - Login</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="login-page">
    <div class="login-box">
        <h1>Bauernglück Hofladen</h1>

        <?php if ($error): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['timeout'])): ?>
            <div class="info">Sitzung abgelaufen. Bitte erneut anmelden.</div>
        <?php endif; ?>

        <form method="post">
            <label>UserID</label>
            <input type="text" name="username" required>

            <label>Passwort</label>
            <input type="password" name="password" required>

            <button type="submit">Anmelden</button>
        </form>
    </div>
</body>
</html>
