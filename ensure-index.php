<?php
// ensure-index-browser-with-excludes-and-undo.php
// Böngészőből futtatható verzió kizárólistával és undo funkcióval + egyedi fájlmásolások

session_start();

// --- CONFIG ---
$root = realpath(__DIR__ . '/../');
$source = __DIR__ . '/include/index-dir_include.php';
$dryRun = false;
$verbose = true;

$customCopies = [
    realpath(__DIR__ . '/../ekke/felev1/magprog_gy/csharp-ora/') => __DIR__ . '/include/index-csharp_compiler-include.php',
    realpath(__DIR__ . '/../ekke/felev1/magprog_gy/csharp-sajat/') => __DIR__ . '/include/index-csharp_compiler-include.php',
    realpath(__DIR__ . '/../ekke/felev2/magprog2_gy/csharp-ora/') => __DIR__ . '/include/index-csharp_editor-include.php',
    // ide adhatsz még többet
];

$excludes = [
    '/Microsoft/', '/public/',
    '/test/', '/install_mac/',
    '/install_win/', '/aspnet_client/', '/website/', 'backup'
];
// ----------------------------

if ($root === false || !is_dir($root)) die("Hiba: a megadott root mappa nem létezik: " . var_export($root, true));
if (!is_file($source)) die("Hiba: a megadott source fájl nem található: $source");

if (!isset($_SESSION['copiedFiles'])) $_SESSION['copiedFiles'] = [];

echo "<pre>Root: $root\nSource: $source\n\n";

function normalizePath($p) {
    $r = @realpath($p);
    if ($r !== false) return rtrim($r, DIRECTORY_SEPARATOR);
    $p = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p);
    return rtrim($p, DIRECTORY_SEPARATOR);
}

// --- Exclude normalizálás ---
$normalizedExcludes = [];
foreach ($excludes as $ex) {
    $exTrim = trim($ex);
    if ($exTrim === '') continue;
    $abs = ($exTrim[0] === DIRECTORY_SEPARATOR || preg_match('#^[A-Za-z]:\\\\#', $exTrim))
        ? normalizePath($exTrim)
        : normalizePath($root . DIRECTORY_SEPARATOR . $exTrim);
    $normalizedExcludes[] = ['raw' => $exTrim, 'abs' => $abs, 'pattern' => $exTrim];
}

// --- Exclude ellenőrzés ---
function isExcluded($dirPath, $root, $normalizedExcludes) {
    $normDir = normalizePath($dirPath);
    foreach ($normalizedExcludes as $ex) {
        $exAbs = $ex['abs'];
        if ($exAbs && ($normDir === $exAbs || strpos($normDir . DIRECTORY_SEPARATOR, $exAbs . DIRECTORY_SEPARATOR) === 0))
            return true;

        $rel = ltrim(substr($normDir, strlen(normalizePath($root))), DIRECTORY_SEPARATOR);
        $pattern = $ex['pattern'];
        $patternUnix = str_replace(DIRECTORY_SEPARATOR, '/', $pattern);
        $relUnix = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        $fullUnix = str_replace(DIRECTORY_SEPARATOR, '/', $normDir);
        if ($patternUnix !== '' && (fnmatch($patternUnix, $relUnix) || fnmatch($patternUnix, $fullUnix)))
            return true;
    }
    return false;
}

// --- Undo ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['undo'])) {
    echo "Undo indítása...\n";
    $deleted = 0;
    foreach ($_SESSION['copiedFiles'] as $file) {
        if (file_exists($file) && @unlink($file)) {
            $deleted++;
            echo "Törölve: $file\n";
        }
    }
    $_SESSION['copiedFiles'] = [];
    echo "Undo kész. Összesen törölve: $deleted fájl\n";
    echo "</pre><form method='get'><button type='submit'>Újra futtatás</button></form>";
    exit;
}

// --- Rekurzív bejárás ---
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$copied = $skipped = $errors = 0;

foreach ($it as $item) {
    if (!$item->isDir()) continue;
    $dirPath = $item->getPathname();
    $normDir = normalizePath($dirPath);

    // Ellenőrizzük, hogy egyedi forrás tartozik-e ehhez a mappához
    $activeSource = $source;
    $isCustomTarget = false;

    foreach ($customCopies as $targetSubpath => $customFile) {
        $fullTarget = normalizePath($targetSubpath);

        // === MÓDOSÍTÁS: csak akkor aktiváljuk a custom forrást, ha az aktuális mappa KÖZVETLENÜL a target mappa ALATTI almappa ===
        // azaz: a jelenlegi mappa szülője pontosan a fullTarget
        $parentOfCurrent = normalizePath(dirname($normDir));
        if ($parentOfCurrent === $fullTarget && is_file($customFile)) {
            $activeSource = $customFile;
            $isCustomTarget = true;
            break;
        }
        // === /MÓDOSÍTÁS ===

    }

    // Ha kizárt mappa, de egyedi fájl másolása vonatkozik rá → mégis fusson
    if (isExcluded($dirPath, $root, $normalizedExcludes) && !$isCustomTarget) {
        if ($verbose) // echo "Blocked -> $dirPath\n";
        continue;
    }

    $indexPath = $dirPath . DIRECTORY_SEPARATOR . 'index.php';

    if (!file_exists($indexPath)) {
        if ($dryRun) {
            if ($verbose) echo "[dry-run] Would copy to: $indexPath (source: $activeSource)\n";
            $copied++;
            continue;
        }
        if (@copy($activeSource, $indexPath)) {
            @chmod($indexPath, fileperms($activeSource) & 0777);
            $_SESSION['copiedFiles'][] = $indexPath;
            $copied++;
            if ($verbose) echo "Copied -> $indexPath (source: $activeSource)\n";
        } else {
            $errors++;
            echo "Error copying to: $indexPath\n";
        }
    } else {
        $skipped++;
        // if ($verbose) echo "Exists -> $indexPath\n";
    }
}

echo "\nDone - Copied: $copied, Exists: $skipped, Errors: $errors\n";
echo "</pre>";

if ($copied > 0): ?>
    <form method="post" id="undoForm" style="display:inline;">
        <input type="hidden" name="undo" value="1">
        <button type="submit" id="undoBtn">Visszavonás (10s)</button>
    </form>
    <script>
    (function(){
        var countdown = 10;
        var btn = document.getElementById('undoBtn');
        var form = document.getElementById('undoForm');

        btn.textContent = 'Visszavonás (' + countdown + 's)';

        var timer = setInterval(function(){
            countdown--;
            if (countdown <= 0) {
                clearInterval(timer);
                // window.location.href = '../php/';
            } else {
                btn.textContent = 'Visszavonás (' + countdown + 's)';
            }
        }, 1000);

        form.addEventListener('submit', function(event){
            event.preventDefault();
            clearInterval(timer);
            btn.disabled = true;
            btn.textContent = 'Visszavonás indítva…';
            form.submit();
        });

        // --- ÚJ: automatikus scroll a lap aljára ---
        window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
    })();
    </script>
<?php endif; ?>