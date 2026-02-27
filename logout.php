<?php
session_start();

// Kiléptetés
unset($_SESSION['is_admin']);

// Előző oldal meghatározása
$redirect = $_SERVER['HTTP_REFERER'] ?? 'login.php';

// Ha a referrer maga a logout vagy a login, akkor inkább a login.php legyen
$self   = basename($_SERVER['PHP_SELF']);   // logout.php
$login  = 'login.php';

if (
    strpos($redirect, $self) !== false || 
    strpos($redirect, $login) !== false
) {
    $redirect = $login;
}

header("Location: $redirect");
exit;
