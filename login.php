<?php
session_start();

// Admin jelszó
$ADMIN_PASSWORD = 'admin';

// Kijelentkezés kezelése
if (isset($_GET['logout'])) {
    unset($_SESSION['is_admin']);
    // Ha van előző oldal, oda megyünk, különben a login.php marad
    $redirect = $_SERVER['HTTP_REFERER'] ?? 'login.php';
    // Ha a referrer a login.php, ne legyen végtelen redirect
    if (strpos($redirect, basename($_SERVER['PHP_SELF'])) !== false) {
        $redirect = 'login.php';
    }
    header("Location: $redirect");
    exit;
}

// Hová irányítson vissza login után, ha van
$redirect = $_GET['redirect'] ?? '';

// Ellenőrzés: be van-e jelentkezve
$logged_in = !empty($_SESSION['is_admin']);

$error = '';

// Bejelentkezés kezelése
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pw'])) {
    $pw = $_POST['pw'];
    if ($pw === $ADMIN_PASSWORD) {
        $_SESSION['is_admin'] = true;
        $logged_in = true;

        // Ha van redirect, oda megyünk, különben marad a választóképernyő
        if (!empty($_POST['redirect'])) {
            $redirect = $_POST['redirect'];
            header("Location: $redirect");
            exit;
        }
    } else {
        $error = 'Hibás jelszó!';
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<title>Admin bejelentkezés</title>
<style>
body { font-family: Arial, sans-serif; background:#fafafa; padding:50px; }
.card { background:#fff; padding:20px; border-radius:10px; max-width:400px; margin:auto; text-align:center; box-shadow:0 0 10px rgba(0,0,0,0.1);}
input[type=password] { width:80%; padding:8px; margin:10px 0; border-radius:4px; border:1px solid #ccc; }
button { padding:10px 20px; border-radius:4px; border:none; background:#28a745; color:#fff; cursor:pointer; margin:5px; }
.error { color:red; font-weight:bold; margin-top:10px; }
</style>
</head>
<body>
<div class="card">
<?php if (!$logged_in): ?>
    <h2>Admin bejelentkezés</h2>
    <?php if ($error): ?>
        <div class="error"><?=htmlspecialchars($error)?></div>
    <?php endif; ?>
    <form method="post">
        <input type="password" name="pw" placeholder="Jelszó" required autofocus><br>
        <input type="hidden" name="redirect" value="<?=htmlspecialchars($redirect)?>">
        <button type="submit">Bejelentkezés</button>
    </form>
<?php else: ?>
    <?php if (!empty($redirect)): ?>
        <p>Bejelentkezve - Átirányítás</p>
        <script>setTimeout(()=>{window.location.href='<?=htmlspecialchars($redirect)?>';},1500);</script>
    <?php else: ?>
        <h2>Sikeres bejelentkezés</h2>
        <p>Válassz a következő lehetőségek közül:</p>
        <a href="search.php"><button>Search</button></a>
        <a href="download.php"><button>Download</button></a>
        <a href="admin.php"><button>Admin</button></a>
        <p style="margin-top:20px;"><a href="?logout=1"><button style="background:#dc3545;">Kijelentkezés</button></a></p>
    <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
