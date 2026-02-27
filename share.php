<?php
// share.php - Relatív input és feljebb lépő redirect
$shareBase = realpath(__DIR__ . '/../share');
if ($shareBase === false) {
    if (!mkdir(__DIR__ . '/../share', 0775, true)) die("Hiba: ../share mappa nem elérhető.");
    $shareBase = realpath(__DIR__ . '/../share');
}

function makeRandomDirName($base) {
    $tries = 0;
    do {
        $name = str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $full = $base . DIRECTORY_SEPARATOR . $name;
        $tries++;
        if ($tries > 200) return false;
    } while (file_exists($full));
    return $name;
}

$messages = [];
$createdFiles = [];

$baseDir = realpath(__DIR__ . '/..'); // inputhoz képest, innen indul a relatív út

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = $_POST['paths'] ?? '';
    $lines = preg_split('/\r\n|\r|\n|,/', $raw);
    $lines = array_map('trim', $lines);
    $lines = array_filter($lines, fn($v) => $v !== '');

    if (!$lines) $messages[] = "Nem adtál meg elérési utat.";
    else {
        $userDirName = trim($_POST['dirname'] ?? ''); // új input a felhasználói névnek

    if ($userDirName !== '') {
        // biztonságos név: csak betű, szám, pont, aláhúzás, kötőjel
        $safeDirName = preg_replace('/[^A-Za-z0-9._-]/', '_', $userDirName);
        $newDir = $shareBase . DIRECTORY_SEPARATOR . $safeDirName;
        $attempt = 0;
        while (file_exists($newDir)) {
            $attempt++;
            $newDir = $shareBase . DIRECTORY_SEPARATOR . ($safeDirName . '_' . $attempt);
            if ($attempt > 1000) { $messages[] = "Nem sikerült létrehozni a mappát."; break; }
        }
    } else {
        $randName = makeRandomDirName($shareBase);
        if ($randName === false) $messages[] = "Nem sikerült új mappát létrehozni.";
        else $newDir = $shareBase . DIRECTORY_SEPARATOR . $randName;
    }

    // ha a mappa még mindig nem létezik, próbáljuk létrehozni
    if (!isset($newDir) || !mkdir($newDir, 0775) && !is_dir($newDir)) {
        $messages[] = "Nem sikerült létrehozni a megosztó mappát.";
    }

    foreach ($lines as $rawPath) {
        // --- Külső URL esetén ---
        if (preg_match('#^https?://#i', $rawPath)) {
            $urlParts = parse_url($rawPath);
            $host = $urlParts['host'] ?? 'redirect'; // ha nincs host, fallback 'redirect'

            // biztonságos fájlnév a domainből
            $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', $host);
            $targetName = $safeBase . '.php';

            // ha már létezik, sorszámozás
            $attempt = 0;
            while (file_exists($newDir . DIRECTORY_SEPARATOR . $targetName)) {
                $attempt++;
                $targetName = $safeBase . '_' . $attempt . '.php';
                if ($attempt > 1000) break;
            }

            // PHP átirányító fájl létrehozása
            $fpath = $newDir . DIRECTORY_SEPARATOR . $targetName;
            $phpContent = "<?php\n";
            $phpContent .= "// Autogenerált átirányító külső URL-re\n";
            $phpContent .= "header('Location: " . addslashes($rawPath) . "', true, 302);\n";
            $phpContent .= "exit;\n";
            $phpContent .= "?>\n";

            if (file_put_contents($fpath, $phpContent) !== false) $createdFiles[] = $targetName;
            else $messages[] = "Nem sikerült írni: " . htmlspecialchars($targetName);

            continue; // továbblépünk a következő sorra
        }
        $rawPath = preg_replace('/%20/i', ' ', $rawPath);
        $rawPath = str_replace('+', ' ', $rawPath);
        $rawPath = trim($rawPath);
        $real = realpath($baseDir . '/' . ltrim($rawPath, '/'));
        if ($real === false) {
            $messages[] = "Nem létezik vagy nem elérhető a fájl/mappa: " . htmlspecialchars($rawPath);
            continue;
        }
        $origBase = basename($real);
        $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', $origBase);
        $targetName = $safeBase . '.php';
        $attempt = 0;
        while (file_exists($newDir . DIRECTORY_SEPARATOR . $targetName)) {
            $attempt++;
            $targetName = $safeBase . '_' . $attempt . '.php';
            if ($attempt > 1000) break;
        }
        $fpath = $newDir . DIRECTORY_SEPARATOR . $targetName;
        $relPath = str_replace($baseDir, '', $real);
        $relPath = str_replace('\\', '/', $relPath);
        if (substr($relPath,0,1) === '/') $relPath = substr($relPath,1);
        $pathParts = explode('/', $relPath);
        $lastPart = array_pop($pathParts);
        $lastPartEncoded = rawurlencode($lastPart);
        $relPathEncoded = implode('/', $pathParts);
        if ($relPathEncoded !== '') $relPathEncoded .= '/';
        $relPathEncoded .= $lastPartEncoded;
        $phpContent = "<?php\n";
        $phpContent .= "// Autogenerált átirányító\n";
        $phpContent .= "header('Location: ../../" . $relPathEncoded . "', true, 302);\n";
        $phpContent .= "exit;\n";
        $phpContent .= "?>\n";
        if (file_put_contents($fpath, $phpContent) !== false) $createdFiles[] = $targetName;
        else $messages[] = "Nem sikerült írni: " . htmlspecialchars($targetName);
    }

    if ($createdFiles) {
        $messages[] = "Sikeresen létrehozva " . count($createdFiles) . " redirect fájl a(z) " . htmlspecialchars(basename($newDir)) . " mappában.";
        $messages[] = "A redirect fájlok elérhetők a ../share/" . htmlspecialchars(basename($newDir)) . " mappában.";
        ?>
        <script>
        setTimeout(function(){
            window.location.href = "ensure-index.php";
        }, 3000);
        </script>
        <?php
    } else $messages[] = "Nem jött létre egyetlen redirect fájl sem.";

        }
    }
?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<title>Fájlmegosztás</title>
<style>
body { font-family: Arial, sans-serif; max-width: 900px; margin: 2rem auto; line-height: 1.4; }
textarea { width: 100%; height: 160px; font-family: monospace; }
input[type=submit] { padding: 0.5rem 1rem; }
.notice { background: #f7f7f7; padding: 0.6rem; margin-bottom: 1rem; border-radius: 4px; }
.msg { background: #eef; padding: 0.5rem; margin: .4rem 0; border-radius:4px; }
</style>
</head>
<body>
<h1>Megosztható link létrehozása</h1>

<?php if (!empty($messages)): ?>
    <div>
    <?php foreach ($messages as $m): ?>
        <div class="msg"><?php echo $m; ?></div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" action="">
    <label for="paths">Elérési utak (egy sor = egy fájl vagy mappa):</label><br>
    <textarea name="paths" id="paths" placeholder="ekke/webprog_gy/20250923-2_forraskod/index.html&#10;ekke/webprog_gy/valami_mappa"></textarea><br><br>
    <label for="dirname">Mappa neve (üresen hagyva véletlenszerű számos név):</label><br>
    <input type="text" name="dirname" id="dirname" placeholder="pl.: sajat_mappa"><br><br>
    <input type="submit" value="Létrehozás">
</form>
</body>
</html>
