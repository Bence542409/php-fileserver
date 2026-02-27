<?php
session_start();

// --- NOTE: This file expects $baseDir to be defined earlier (same as your original file)
// --- Admin check
$isAdmin = !empty($_SESSION['is_admin']);

// ---- ADMIN ACTION HANDLERS (delete / rename / save_edit / move) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // csak bejelentkezett adminok végezhetik el
    if (!$isAdmin) {
        http_response_code(403);
        exit('Hozzáférés megtagadva.');
    }

    // segédfüggvények
    $normalize = function(string $requested) use (&$baseDir) {
        $requested = rawurldecode($requested);
        $requested = ltrim($requested, "/\\");
        $targetPath = $baseDir . DIRECTORY_SEPARATOR . $requested;
        $realTarget = realpath($targetPath);
        $realBase = realpath($baseDir);
        if ($realTarget === false || strpos($realTarget, $realBase) !== 0) {
            return false;
        }
        return [$realTarget, $realBase];
    };

    $action = $_POST['action'];

    // TÖRLÉS
    if ($action === 'delete' && isset($_POST['path'])) {
        $res = $normalize($_POST['path']);
        if ($res === false) { http_response_code(400); exit('Érvénytelen útvonal.'); }
        [$realTarget, $realBase] = $res;

        // rekurzív törlés mappára
        $deleteRec = function($path) use (&$deleteRec) {
            if (is_dir($path)) {
                $it = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
                $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($files as $file) {
                    if ($file->isDir()) rmdir($file->getRealPath());
                    else unlink($file->getRealPath());
                }
                return rmdir($path);
            } else {
                return unlink($path);
            }
        };

        $ok = $deleteRec($realTarget);
        echo $ok ? 'OK' : 'ERROR';
        exit;
    }

    // ÁTNEVEZÉS
    if ($action === 'rename' && isset($_POST['path']) && isset($_POST['newname'])) {
        $res = $normalize($_POST['path']);
        if ($res === false) { http_response_code(400); exit('Érvénytelen útvonal.'); }
        [$realTarget, $realBase] = $res;

        $newname = basename($_POST['newname']); // nem engedünk mappaszeparátort itt
        if ($newname === '' ) { http_response_code(400); exit('Üres név.'); }

        $dest = dirname($realTarget) . DIRECTORY_SEPARATOR . $newname;
        $realDest = realpath(dirname($realTarget));
        if ($realDest === false || strpos($realDest, $realBase) !== 0) { http_response_code(400); exit('Cél érvénytelen.'); }

        $ok = rename($realTarget, $dest);
        echo $ok ? 'OK' : 'ERROR';
        exit;
    }

    // ÁTHELYEZÉS
    if ($action === 'move' && isset($_POST['path']) && isset($_POST['dest'])) {
        $res = $normalize($_POST['path']);
        if ($res === false) { http_response_code(400); exit('Érvénytelen útvonal.'); }
        [$realTarget, $realBase] = $res;

        $destRel = ltrim(rawurldecode($_POST['dest']), "/\\");
        // cél lehet egy mappa belül a base-ben vagy csak egy új név (ha nincs "/")
        $destPath = $baseDir . DIRECTORY_SEPARATOR . $destRel;
        $realDest = realpath($destPath) ?: null;

        if ($realDest) {
            // ha létező mappa, mozgatjuk oda (fenntartjuk a fájlnév)
            if (!is_dir($realDest)) { http_response_code(400); exit('Cél nem mappa.'); }
            $destFull = rtrim($realDest, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($realTarget);
        } else {
            // ha nem létezik, lehet hogy a felhasználó új névvel adott meg relatív útvonalat ugyanabban a mappában
            $parent = dirname($realTarget);
            $destFull = $parent . DIRECTORY_SEPARATOR . basename($destRel);
        }

        // biztonsági ellenőrzés: legyen a cél is a base alatt
        $realDestFull = realpath(dirname($destFull)) ?: dirname($destFull);
        if (strpos($realDestFull, realpath($baseDir)) !== 0) { http_response_code(400); exit('Cél kívül esik a gyökérmappán.'); }

        $ok = rename($realTarget, $destFull);
        echo $ok ? 'OK' : 'ERROR';
        exit;
    }

    // MENTÉS SZERKESZTŐBŐL
    if ($action === 'save_edit' && isset($_POST['path']) && isset($_POST['content'])) {
        $res = $normalize($_POST['path']);
        if ($res === false) { http_response_code(400); exit('Érvénytelen útvonal.'); }
        [$realTarget, $realBase] = $res;

        if (!is_file($realTarget) || !is_writable($realTarget)) { http_response_code(400); exit('A fájl nem írható vagy nem létezik.'); }

        $content = $_POST['content'];
        $bytes = file_put_contents($realTarget, $content);
        echo $bytes !== false ? 'OK' : 'ERROR';
        exit;
    }
    
    // LÉTREHOZÁS
    if ($action === 'create' && isset($_POST['name']) && isset($_POST['type'])) {
        $res = $normalize($_POST['name']);
        [$realTarget, $realBase] = $res ?? [false, false];
        $name = basename($_POST['name']);
        $type = $_POST['type'];

        $targetPath = $baseDir . DIRECTORY_SEPARATOR . $name;

        if (file_exists($targetPath)) {
            http_response_code(400);
            exit('Már létezik ilyen nevű elem.');
        }

        if ($type === 'folder') {
            $ok = mkdir($targetPath, 0775, true);
        } else {
            $ok = file_put_contents($targetPath, '') !== false;
        }

        echo $ok ? 'OK' : 'ERROR';
        exit;
    }
    
    // FELTÖLTÉS / ZIP kezelése
    if ($action === 'upload' && isset($_FILES['file'])) {
        $upload = $_FILES['file'];
        if ($upload['error'] !== 0) { http_response_code(400); exit('Fájl feltöltési hiba.'); }

        $filename = basename($upload['name']);
        $targetPath = $baseDir . DIRECTORY_SEPARATOR . $filename;

        // Mozgatás feltöltéshez
        if (!move_uploaded_file($upload['tmp_name'], $targetPath)) {
            http_response_code(500);
            exit('Fájl mentése sikertelen.');
        }
        
        // --- CSAK IIS ---
        exec('icacls ' . escapeshellarg($targetPath) . ' /grant "IIS_IUSRS":(F)');
        // -------------------------

        // Ha ZIP, kicsomagolás
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'zip') {
            $zip = new ZipArchive();
            if ($zip->open($targetPath) === true) {
                $zip->extractTo($baseDir); // kicsomagolás a jelenlegi mappába
                $zip->close();
                unlink($targetPath); // opcionális: törli a zip fájlt a kicsomagolás után
            } else {
                http_response_code(500);
                exit('ZIP megnyitása sikertelen.');
            }
        }

        echo 'OK';
        exit;
    }



    // ha nem ismert action
    http_response_code(400);
    exit('Ismeretlen művelet.');
}


// --- DOWNLOAD HANDLER ---
if (isset($_GET['download'])) {
    $requested = rawurldecode($_GET['download']);
    if (strpos($requested, '..') !== false) {
        http_response_code(400);
        exit("Hibás kérés.");
    }

    $requested = ltrim($requested, "/\\");
    $targetPath = $baseDir . DIRECTORY_SEPARATOR . $requested;
    $realTarget = realpath($targetPath);
    $realBase = realpath($baseDir);

    if ($realTarget === false || strpos($realTarget, $realBase) !== 0) {
        http_response_code(403);
        exit("Hozzáférés megtagadva.");
    }

    if (is_dir($realTarget)) {
        $zipName = basename($realTarget) . '.zip';
        $tmpZip = tempnam(sys_get_temp_dir(), 'dlzip_');
        $zip = new ZipArchive();
        $zip->open($tmpZip, ZipArchive::OVERWRITE);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($realTarget, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($realTarget) + 1);
            $zip->addFile($filePath, $relativePath);
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . rawurlencode($zipName) . '"');
        header('Content-Length: ' . filesize($tmpZip));
        readfile($tmpZip);
        unlink($tmpZip);
        exit;
    } elseif (is_file($realTarget)) {
        $filename = basename($realTarget);
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . filesize($realTarget));
        readfile($realTarget);
        exit;
    } else {
        http_response_code(404);
        exit("A kért elem nem található.");
    }
}
// --- /DOWNLOAD HANDLER ---


$parentDirName = basename($baseDir);
$allItems = array_diff(scandir($baseDir), ['.', '..', basename(__FILE__)]);

// kizárt rendszerfájlok és ponttal kezdődő fájlok
$excludedSystem = ['$RECYCLE.BIN','System Volume Information','web.config','index.php'];

$items = [];
foreach ($allItems as $item) {
    if (in_array($item, $excludedSystem)) continue;
    if (strpos($item, '.') === 0) continue;

    $fullPath = $baseDir . DIRECTORY_SEPARATOR . $item;

    // --- itt adjuk meg a megjelenített nevet ---
    $displayName = $item;

    // Ha a $baseDir a 'share' mappa vagy annak almappája
    $shareBase = realpath(__DIR__ . '/../share'); // a share mappa abszolút útvonala
    if (strpos($fullPath, $shareBase) === 0 && is_file($fullPath) && strtolower(pathinfo($item, PATHINFO_EXTENSION)) === 'php') {
        $displayName = pathinfo($item, PATHINFO_FILENAME); // levágja a .php-t
    }

    $items[] = [
        'name' => $displayName, // ez a levágott név
        'path' => $item,         // ez marad az eredeti fájlnév, .php-vel
        'is_dir' => is_dir($fullPath),
        'ctime' => filectime($fullPath),
        'mtime' => filemtime($fullPath),
        'size'  => is_file($fullPath) ? filesize($fullPath) : 0
    ];
}

usort($items, fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
$totalItems = count($items);

function formatSize($bytes){
    if ($bytes >= 1024**3) return round($bytes/(1024**3),2).' GB';
    if ($bytes >= 1024**2) return round($bytes/(1024**2),2).' MB';
    if ($bytes >= 1024) return round($bytes/1024,2).' KB';
    return $bytes.' B';
}

function getRelativePath($url, $baseDir = "/server1/", $parentDirName = "server1") {
    // URL útvonal részének kinyerése
    $path = parse_url($url, PHP_URL_PATH);
    
    // Base dir eltávolítása
    $relative = str_replace($baseDir, "", $path);
    
    // Levágjuk a kezdő "/"-t, ha van
    $relative = ltrim($relative, "/");

    // Ha üres, akkor a parentDirName-t adjuk vissza
    if (empty($relative)) {
        return $parentDirName;
    }

    return $relative;
}

// Aktuális URL összeállítása
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$path = $_SERVER['REQUEST_URI'];
$currentUrl = $protocol . $host . $path;

// Relatív út
$relativePath = getRelativePath($currentUrl);
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($parentDirName) ?></title>
<style>

/* Chrome, Edge, Safari */
body::-webkit-scrollbar {
    width: 0;                    /* scrollbar szélesség 0 */
    background: transparent;      /* háttér átlátszó */
}
body{ 
    font-family:Arial,sans-serif;
    padding:20px;
    background:#f9f9f9;
}
.controls {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 20px;
    align-items: center;
    background: white;
    border-radius: 5px;
}

.controls input{flex:1;
    padding:12px;
    border:1px solid #ddd;
    border-radius:5px;
    border: none;
}
table{
    width:100%;
    border-collapse:collapse;
    background:#fff;
    box-shadow:0 2px 6px rgba(0,0,0,0.1);} 
th, td{
    text-align:left;
    padding:8px 10px;
    border-bottom:1px solid #ddd;
}
th{
    background:#007BFF;
    color:#fff;
    position:sticky;
    top:0;
}
a.folder-link{
    color:dodgerblue;
    text-decoration:none;
}
a.folder-link:hover{
    text-decoration:underline;
}
tr.hidden{
    display:none;
}
.header-container {
    display:flex;
    justify-content:
        space-between;
    align-items:center;
    flex-wrap:wrap;
    margin-bottom:20px;
}
.header-stats {
    font-size:0.9em;
    color:#666;
    white-space:nowrap;
    text-align: right;
}

.icon, .download-icon {
    display:inline-block;
    width:18px;
    height:18px;
    vertical-align:middle;
    text-decoration:none;
    opacity:1;
    text-align: center;
}
.icon:hover, .controls a:hover {
    opacity:0.5;
}
    
@media(min-width:768px) {
    .middle {
        text-align: center;
    }
}
    
.folder-row, .folder-row * {
    -webkit-user-select: none; /* Chrome, Safari */
    -moz-user-select: none;    /* Firefox */
    -ms-user-select: none;     /* IE/Edge */
    user-select: none;         /* szabványos */
}
    
a {
    cursor: default;   /* vagy cursor: auto; */
}

#createForm input, #createForm select,
#uploadForm input, #uploadForm select {
    box-sizing: border-box;
}
    
/* Mobil nézet: kártyás lista */
@media(max-width:768px){
    table, thead, tbody, th, td, tr {
        display:block;
        width:100%;
    }
    thead { display:none; }
    
    .folder-row {
        background:#fff;
        margin-bottom:12px;
        padding:8px; /* kisebb padding, hogy kiférjen */
        border-radius:8px;
        box-shadow:0 1px 3px rgba(0,0,0,0.1);
        box-sizing:border-box; /* fontos, hogy a padding ne növelje a szélességet */
        word-break: break-word; /* hosszú nevek tördelése */
        margin-bottom: 15px;
    }

    .folder-row td {
        display:block;
        padding:4px 0;
        border:none;
    }

    .folder-row td a.folder-link {
        font-weight:bold;
        color:#007BFF;
        text-decoration:none;
        display:block;
        margin-bottom:4px;
        word-break: break-word; /* hosszú nevek ne lógjanak ki */
    }

    .folder-row td a.folder-link:hover { text-decoration:underline; }

    .folder-row td::before {
        content: attr(data-label)": ";
        font-weight:bold;
        color:#333;
        display:inline-block;
        margin-right:5px;
    }

    .title { font-size:28px; margin-bottom:10px; }
    .header-stats { display:none; }
    
    .icons {
        display: none;
    }
}

</style>
</head>
<body>
<div class="header-container">
    <h1 class="title"><?php echo htmlspecialchars($relativePath); ?></h1>
    <div class="header-stats">
        Elemek: <?= $totalItems ?>
    </div>
</div>
    
<?php
// Editor view (ha edit param van és admin)
if ($isAdmin && isset($_GET['edit'])):
    $editRel = ltrim(rawurldecode($_GET['edit']), "/\\");
    $editFull = realpath($baseDir . DIRECTORY_SEPARATOR . $editRel);
    $okEdit = $editFull && strpos($editFull, realpath($baseDir)) === 0 && is_file($editFull);
    if ($okEdit) {
        $content = file_get_contents($editFull);
        $editPathEsc = htmlspecialchars($editRel, ENT_QUOTES);
    ?>
    <div class="editor-box" style="margin-bottom: 30px">
        <h3>Szerkesztés: <?= htmlspecialchars(basename($editRel)) ?></h3>
        <form id="editForm" method="post" onsubmit="return saveEdit(event)">
            <input type="hidden" name="action" value="save_edit">
            <input type="hidden" name="path" value="<?= $editPathEsc ?>">
            <textarea name="content" id="editorContent" style="width:100%;height:320px;font-family:monospace;"><?= htmlspecialchars($content) ?></textarea>
            <div style="margin-top:8px;">
                <button type="submit" class="action-btn btn-edit" style="background:#28a745;color:white;padding:6px 12px;border:none;border-radius:4px;">Mentés</button>
                <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>"><button type="button" class="action-btn" style="background:red;color:white;padding:6px 12px;border:none;border-radius:4px;">Mégse</button></a>
            </div>
        </form>
    </div>
    <?php
    } else {
        // ha a fájl nem létezik vagy nem engedélyezett
        echo '<div style="background:#ffecec;padding:10px;border-radius:6px;margin-bottom:12px;">Szerkesztés nem lehetséges: fájl nem található vagy nem szerkeszthető.</div>';
    }
endif;
?>

    
<div class="controls">
    <input type="text" id="search" placeholder="Keresés...">
    <!-- Vissza gomb -->
    <a type="button" title="Vissza" class="icons" onclick="history.back()">
        <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="m313-440 224 224-57 56-320-320 320-320 57 56-224 224h487v80H313Z"/></svg>
    </a>
    <!-- Feljebb gomb -->
    <a type="button" title="Feljebb egy mappát" class="icons" onclick="goUpOneDir()">
        <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M440-160v-487L216-423l-56-57 320-320 320 320-56 57-224-224v487h-80Z"/></svg>
    </a>
    <a href="/server1/php/search.php?q=<?php echo htmlspecialchars($relativePath); ?>" title="Keresés az összes almappában" style="text-decoration:none; color:inherit;" class="icons">
        <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M784-120 532-372q-30 24-69 38t-83 14q-109 0-184.5-75.5T120-580q0-109 75.5-184.5T380-840q109 0 184.5 75.5T640-580q0 44-14 83t-38 69l252 252-56 56ZM380-400q75 0 127.5-52.5T560-580q0-75-52.5-127.5T380-760q-75 0-127.5 52.5T200-580q0 75 52.5 127.5T380-400Z"/></svg>
    </a>
    <a href="/server1/php/download.php?<?php echo htmlspecialchars($relativePath); ?>" title="Mappa letöltése" style="text-decoration:none; color:inherit;" class="icons">
        <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M480-320 280-520l56-58 104 104v-326h80v326l104-104 56 58-200 200ZM240-160q-33 0-56.5-23.5T160-240v-120h80v120h480v-120h80v120q0 33-23.5 56.5T720-160H240Z"/></svg>
    </a>
    <a id="copyBtn" title="Megosztás" style="text-decoration:none; color:inherit;" class="icons">
        <svg xmlns="http://www.w3.org/2000/svg" height="23px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M680-80q-50 0-85-35t-35-85q0-6 3-28L282-392q-16 15-37 23.5t-45 8.5q-50 0-85-35t-35-85q0-50 35-85t85-35q24 0 45 8.5t37 23.5l281-164q-2-7-2.5-13.5T560-760q0-50 35-85t85-35q50 0 85 35t35 85q0 50-35 85t-85 35q-24 0-45-8.5T598-672L317-508q2 7 2.5 13.5t.5 14.5q0 8-.5 14.5T317-452l281 164q16-15 37-23.5t45-8.5q50 0 85 35t35 85q0 50-35 85t-85 35Zm0-80q17 0 28.5-11.5T720-200q0-17-11.5-28.5T680-240q-17 0-28.5 11.5T640-200q0 17 11.5 28.5T680-160ZM200-440q17 0 28.5-11.5T240-480q0-17-11.5-28.5T200-520q-17 0-28.5 11.5T160-480q0 17 11.5 28.5T200-440Zm480-280q17 0 28.5-11.5T720-760q0-17-11.5-28.5T680-800q-17 0-28.5 11.5T640-760q0 17 11.5 28.5T680-720Zm0 520ZM200-480Zm480-280Z"/></svg>
    </a>
    <script>
        document.getElementById("copyBtn").addEventListener("click", e => {
            e.preventDefault();
            
            const text = "https://server.nemeth-bence.com/<?php echo addslashes($relativePath); ?>";
            
            const temp = document.createElement("textarea");
            temp.value = text;
            document.body.appendChild(temp);
            temp.select();
            document.execCommand("copy");
            document.body.removeChild(temp);
        });
        
function goUpOneDir() {
    let path = window.location.pathname;
    // Vágjuk le az utolsó '/' utáni részt
    path = path.replace(/\/[^\/]*\/?$/, '/');
    window.location.href = path;
}
</script>
    <a href="/server1/php/share.php" title="Link készítése" target="_blank" style="text-decoration:none; color:inherit;" class="icons">
        <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M432-288H288q-79.68 0-135.84-56.23Q96-400.45 96-480.23 96-560 152.16-616q56.16-56 135.84-56h144v72H288q-50 0-85 35t-35 85q0 50 35 85t85 35h144v72Zm-96-156v-72h288v72H336Zm192 156v-72h144q50 0 85-35t35-85q0-50-35-85t-85-35H528v-72h144q79.68 0 135.84 56.23 56.16 56.22 56.16 136Q864-400 807.84-344 751.68-288 672-288H528Z"/></svg>
    </a>
    <a href="/server1/php/login.php?redirect=../<?php echo htmlspecialchars($relativePath); ?>" title="Bejelentkezés" style="text-decoration:none; color:inherit; margin-right: 10px" class="icons">
        <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M480-120v-80h280v-560H480v-80h280q33 0 56.5 23.5T840-760v560q0 33-23.5 56.5T760-120H480Zm-80-160-55-58 102-102H120v-80h327L345-622l55-58 200 200-200 200Z"/></svg>
    </a>
    
    <?php if ($isAdmin): ?>
            <a href="/server1/php/logout.php/" title="Kijelentkezés" style="text-decoration:none; color:inherit; margin-left: -10px" class="icons">
                <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"/></svg>
            </a>
    <!-- Új fájl/mappa gomb -->
            <a id="createBtn" title="Fájl létrehozása" style="text-decoration:none; color:inherit;" class="icons">
                <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M440-120v-320H120v-80h320v-320h80v320h320v80H520v320h-80Z"/></svg>
            </a>
    
            <a id="uploadBtn" title="Fájl feltöltése" style="text-decoration:none; color:inherit; margin-right: 10px" class="icons">
                <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#1f1f1f"><path d="M440-320v-326L336-542l-56-58 200-200 200 200-56 58-104-104v326h-80ZM240-160q-33 0-56.5-23.5T160-240v-120h80v120h480v-120h80v120q0 33-23.5 56.5T720-160H240Z"/></svg>
            </a>
<?php endif; ?>
    
</div>

<table id="folderTable">
<thead>
<tr>
    <th>Fájl</th>
    <th>Létrehozva</th>
    <th>Módosítva</th>
    <th>Méret</th>
    <th>Típus</th>
    <th style="padding: 0" class="middle">Műveletek</th>
</tr>
</thead>
<tbody>
<?php foreach($items as $item):
    $link = $item['is_dir'] ? $item['name'] . '/' : $item['name'];

    // fájl kiterjesztés
    $ext = strtolower(pathinfo($item['name'], PATHINFO_EXTENSION));
    $isImage = in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg']);
    $isVideo = in_array($ext, ['mp4','avi','mkv','mov','wmv','flv','webm']);

    // típus címke (egyszerű, "fájl" szó nélkül)
    if ($item['is_dir']) {
        $typeLabel = 'Mappa';
    } else {
        $typeLabel = $ext ? strtoupper($ext) : 'Ismeretlen';
    }
    
    
?>

<tr class="folder-row">
    <td data-label="Fájl">
        <a 
            href="<?= htmlspecialchars($item['path']) ?>" 
            class="folder-link"
            <?php if(!$item['is_dir'] && !$isImage && !$isVideo && $ext !== 'pdf' && $ext !== 'php' && $ext !== 'html' && $ext !== 'txt'): ?> download <?php endif; ?>>
            <?= htmlspecialchars($item['name']) ?>
        </a>
    </td>
    <td data-label="Létrehozva"><?= date('Y-m-d H:i:s',$item['ctime']) ?></td>
    <td data-label="Módosítva"><?= date('Y-m-d H:i:s',$item['mtime']) ?></td>
    <td data-label="Méret"><?= $item['is_dir'] ? '&mdash;' : formatSize($item['size']) ?></td>
    <td data-label="Típus"><?= htmlspecialchars($typeLabel) ?></td>
    <?php
    $shareBase = realpath(__DIR__ . '/../share');
    $fullPath = $baseDir . DIRECTORY_SEPARATOR . $item['path'];

    // Alapértelmezett link: normál fájl/mappa
    $dlHref = "?download=" . rawurlencode($item['path']);

    // Ha nem mappa és share mappa + PHP fájl
    if (is_file($fullPath) && strpos($fullPath, $shareBase) === 0 && strtolower(pathinfo($fullPath, PATHINFO_EXTENSION)) === 'php') {
        $content = file_get_contents($fullPath);
        if (preg_match('/header\(\'Location:\s*(.+?)\'/', $content, $matches)) {
            // ../ eltávolítása
            $downloadLink = ltrim(str_replace(['../','..\\'], '', $matches[1]), '/\\');
            $dlHref = "/server1/php/download.php?" . rawurlencode($downloadLink);
        }
    } elseif (is_file($fullPath)) {
        // Ha sima fájl, de nem share mappa, akkor is a download.php-t használjuk
        $dlHref = "?download=" . rawurlencode($item['path']);
    }
    ?>
    <td data-label="Műveletek" class="middle">
        <!-- letöltés -->
        <a class="download-icon" title="Letöltés" href="<?= $dlHref ?>">
            <svg xmlns="http://www.w3.org/2000/svg" height="20" viewBox="0 -960 960 960" width="20" fill="#1f1f1f"><path d="M280-280h400v-80H280v80Zm200-120 160-160-56-56-64 62v-166h-80v166l-64-62-56 56 160 160Zm0 320q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z"/></svg>
        </a>
        <a class="icon share-row" data-file="<?= htmlspecialchars($item['path'], ENT_QUOTES) ?>" title="Megosztás">
            <svg xmlns="http://www.w3.org/2000/svg" height="20" viewBox="0 -960 960 960" width="20" fill="#1f1f1f"><path d="m356-300 204-204v90h80v-226H414v80h89L300-357l56 57ZM480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z"/></svg>
        </a>
        
        <?php if ($isAdmin): ?>
            <!-- szerkesztés -->
            <a class="icon" title="Szerkesztés" onclick="location.href='?edit=<?= rawurlencode($item['path']) ?>'">
                <svg xmlns="http://www.w3.org/2000/svg" height="20" viewBox="0 -960 960 960" width="20" fill="#1f1f1f"><path d="M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93ZM320-320v-123l221-220q9-9 20-13t22-4q12 0 23 4.5t20 13.5l37 37q8 9 12.5 20t4.5 22q0 11-4 22.5T663-540L443-320H320Zm300-263-37-37 37 37ZM380-380h38l121-122-18-19-19-18-122 121v38Zm141-141-19-18 37 37-18-19Z"/></svg>
            </a>
        
            <!-- átnevezés -->
            <a class="icon" title="Átnevezés" onclick="doRename('<?= rawurlencode($item['path']) ?>')">
                <svg xmlns="http://www.w3.org/2000/svg" height="20" viewBox="0 -960 960 960" width="20" fill="#1f1f1f"><path d="M280-420q25 0 42.5-17.5T340-480q0-25-17.5-42.5T280-540q-25 0-42.5 17.5T220-480q0 25 17.5 42.5T280-420Zm200 0q25 0 42.5-17.5T540-480q0-25-17.5-42.5T480-540q-25 0-42.5 17.5T420-480q0 25 17.5 42.5T480-420Zm200 0q25 0 42.5-17.5T740-480q0-25-17.5-42.5T680-540q-25 0-42.5 17.5T620-480q0 25 17.5 42.5T680-420ZM480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z"/></svg>
            </a>
        
            <!-- áthelyezés -->
            <a class="icon" title="Áthelyezés" onclick="doMove('<?= rawurlencode($item['path']) ?>')">
                <svg xmlns="http://www.w3.org/2000/svg" height="20" viewBox="0 -960 960 960" width="20" fill="#1f1f1f"><path d="m480-220 160-160-56-56-64 64v-216l64 64 56-56-160-160-160 160 56 56 64-64v216l-64-64-56 56 160 160Zm0 140q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q133 0 226.5-93.5T800-480q0-133-93.5-226.5T480-800q-133 0-226.5 93.5T160-480q0 133 93.5 226.5T480-160Zm0-320Z"/></svg>
            </a>
        
            <!-- törlés -->
            <a class="icon" title="Törlés" onclick="doDelete('<?= rawurlencode($item['path']) ?>')">
                <svg xmlns="http://www.w3.org/2000/svg" height="20" viewBox="0 -960 960 960" width="20" fill="#1f1f1f"><path d="M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q54 0 104-17.5t92-50.5L228-676q-33 42-50.5 92T160-480q0 134 93 227t227 93Zm252-124q33-42 50.5-92T800-480q0-134-93-227t-227-93q-54 0-104 17.5T284-732l448 448ZM480-480Z"/></svg>
            </a>
        <?php endif; ?>
    </td>
</tr>

<?php endforeach; ?>
</tbody>
</table>
    
<?php if (empty($items)): ?>
    <div style="padding:8px;background:#ffecec;color:#a33;margin-bottom:12px;text-align:center;">
        Nincsenek megjeleníthető fájlok
    </div>
<?php endif; ?>
    
<script>
    const BASE_URL = "https://server.nemeth-bence.com/<?= addslashes($relativePath) ?>";
    const BASE_DIR = "<?= addslashes($baseDir) ?>";

    // ---- Egy fájl másolása ----
    function copySingle(file){
        const text = BASE_URL.replace(/\/?$/, '/') + file;

        const ta = document.createElement("textarea");
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand("copy");
        document.body.removeChild(ta);
    }

    // ---- Több fájl -> share.php redirect ----
    function shareMultiple(files){
        let url = "/server1/php/share.php?";
        files.forEach(f=>{
            url += "path=" + encodeURIComponent(BASE_DIR + "/" + f) + "&";
        });
        location.href = url.slice(0,-1);
    }

    // ---- Soronkénti ikon kezelése ----
    document.querySelectorAll(".share-row").forEach(btn=>{
        btn.addEventListener("click", e=>{
            e.stopPropagation();
            const file = btn.dataset.file;
            copySingle(file);
        });
    });

    // ---- Felső „Megosztás” gomb átírása ----
    document.getElementById("copyBtn").addEventListener("click", e=>{
        e.preventDefault();

        if(selectedRows.size === 0) return;

        const files = [];
        selectedRows.forEach(i=>{
            const el = allRows[i].querySelector(".share-row");
            if(el) files.push(el.dataset.file);
        });

        if(files.length === 1){
            copySingle(files[0]);
        } else {
            shareMultiple(files);
        }
    });
</script>
    
<!-- Modal HTML -->
            <div id="createModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);justify-content:center;align-items:center;">
                <div style="background:white;padding:20px;border-radius:8px;max-width:400px;width:90%;">
                    <h3>Új elem létrehozása</h3>
                    <form id="createForm">
                        <label>Név:</label>
                        <input type="text" name="name" style="width:100%;padding:8px;margin:8px 0;border:1px solid #ddd;border-radius:4px;" required>
                        <label>Típus:</label>
                        <select name="type" style="width:100%;padding:8px;margin:8px 0;border:1px solid #ddd;border-radius:4px;">
                            <option value="file">Fájl</option>
                            <option value="folder">Mappa</option>
                        </select>
                        <div style="text-align:right;margin-top:10px;">
                            <button type="button" id="closeModal" style="background:red;color:white;padding:6px 12px;border:none;border-radius:4px;">Mégse</button>
                            <button type="submit" style="background:#28a745;color:white;padding:6px 12px;border:none;border-radius:4px;">Létrehozás</button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
            // Modal kezelése
            const createBtn = document.getElementById('createBtn');
            const modal = document.getElementById('createModal');
            const closeBtn = document.getElementById('closeModal');
            const createForm = document.getElementById('createForm');

            createBtn.addEventListener('click', () => {
                modal.style.display = 'flex';
            });

            closeBtn.addEventListener('click', () => {
                modal.style.display = 'none';
            });

            window.addEventListener('click', (e) => {
                if (e.target === modal) modal.style.display = 'none';
            });

            // Form beküldése
            createForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(createForm);
                const name = formData.get('name').trim();
                const type = formData.get('type');

                if (!name) return alert('Adj meg egy nevet!');

                const data = { action: 'create', name, type };

                const res = await fetch(location.pathname, {
                    method: 'POST',
                    body: new URLSearchParams(data)
                });
                const text = await res.text();
                if (res.ok && text.trim() === 'OK') {
                    alert('Sikeres létrehozás!');
                    location.reload();
                } else {
                    alert('Hiba: ' + text);
                }
            });
            </script>
    
    <div id="uploadModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);justify-content:center;align-items:center;">
        <div style="background:white;padding:20px;border-radius:8px;max-width:400px;width:90%;">
            <h3>Fájl feltöltése</h3>
            <form id="uploadForm" enctype="multipart/form-data">
                <input type="file" name="file" required style="width:100%;padding:8px;margin:8px 0;border:1px solid #ddd;border-radius:4px;">
                <div style="text-align:right;margin-top:10px;">
                    <button type="button" id="closeUploadModal" style="background:red;color:white;padding:6px 12px;border:none;border-radius:4px;">Mégse</button>
                    <button type="submit" style="background:#28a745;color:white;padding:6px 12px;border:none;border-radius:4px;">Feltöltés</button>
                </div>
            </form>
        </div>
    </div>

<script>
    const uploadBtn = document.getElementById('uploadBtn');
    const uploadModal = document.getElementById('uploadModal');
    const closeUploadModal = document.getElementById('closeUploadModal');
    const uploadForm = document.getElementById('uploadForm');

    uploadBtn.addEventListener('click', () => uploadModal.style.display = 'flex');
    closeUploadModal.addEventListener('click', () => uploadModal.style.display = 'none');
    window.addEventListener('click', (e) => { if(e.target === uploadModal) uploadModal.style.display = 'none'; });

    uploadForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(uploadForm);
        formData.append('action', 'upload');

        const res = await fetch(location.pathname, { method: 'POST', body: formData });
        const text = await res.text();
        if (res.ok && text.trim() === 'OK') {
            // Megnézzük a feltöltött fájl nevét
            const fileInput = uploadForm.querySelector('input[type="file"]');
            const fileName = fileInput.files[0]?.name || '';
            const ext = fileName.split('.').pop().toLowerCase();

            if (ext === 'zip') {
                // ZIP esetén 3 másodperc múlva átirányítjuk
                setTimeout(() => {
                    window.location.href = '/server1/php/ensure-index.php';
                }, 3000);
            } else {
                // Más fájl esetén csak reload
                location.reload();
            }
        } else {
            alert('Hiba: ' + text);
        }
    });


</script>

<script>
    
// ===== Admin operations via fetch =====
async function postAction(data) {
    const form = new URLSearchParams(data);
    const res = await fetch(location.pathname, { method: 'POST', body: form });
    const txt = await res.text();
    return { ok: res.ok, text: txt };
}

async function doDelete(path) {
    if (!confirm('Biztosan törlöd ezt az elemet?')) return;
    const r = await postAction({ action: 'delete', path });
    if (r.ok && r.text.trim() === 'OK') location.reload();
    else alert('Törlés sikertelen: ' + r.text);
}

async function doRename(path) {
    const newname = prompt('Új név (csak fájlnév, mappa esetén is):', decodeURIComponent(path));
    if (!newname) return;
    const r = await postAction({ action: 'rename', path, newname });
    if (r.ok && r.text.trim() === 'OK') location.reload();
    else alert('Átnevezés sikertelen: ' + r.text);
}

async function doMove(path) {
    const dest = prompt('Áthelyezés ide (relatív út a gyökérhez vagy meglévő mappára mutató relatív út):', '');
    if (!dest) return;
    const r = await postAction({ action: 'move', path, dest });
    if (r.ok && r.text.trim() === 'OK') location.reload();
    else alert('Áthelyezés sikertelen: ' + r.text);
}

async function saveEdit(e) {
    e.preventDefault();
    const form = document.getElementById('editForm');
    const data = new URLSearchParams(new FormData(form));
    const res = await fetch(location.pathname, { method: 'POST', body: data });
    const txt = await res.text();
    if (res.ok && txt.trim() === 'OK') { alert('Mentés sikeres'); location.href = location.pathname; }
    else alert('Mentés sikertelen: ' + txt);
}

// ===== Keresés (filter) =====
const searchInput = document.getElementById('search');
searchInput.addEventListener('input', () => {
    const filter = searchInput.value.trim().toLowerCase();
    document.querySelectorAll('.folder-row').forEach(row => {
        const text = row.querySelector('td[data-label="Fájl"]').innerText.toLowerCase();
        row.classList.toggle('hidden', !text.includes(filter));
    });
});

// ===== Több kijelölés kezelése =====
const allRows = Array.from(document.querySelectorAll('.folder-row'));
let selectedRows = new Set(); // tárolja a kijelölt sor indexeket
let lastClickedIndex = null;   // shift tartomány alapja

function updateSelectionVisuals() {
    allRows.forEach((row, i) => {
        if (selectedRows.has(i)) {
            row.style.background = '#cce5ff';
            row.scrollIntoView({ block: 'nearest' });
        } else {
            row.style.background = '';
        }
    });
}

// kattintás - sorokra
allRows.forEach((row, index) => {
    row.addEventListener('click', (e) => {
        // ha inputban kattintottunk, ne zavarjuk
        if (document.activeElement === searchInput) return;

        if (!e.ctrlKey && !e.shiftKey) {
            // sima kattintás -> csak ez marad kijelölve
            selectedRows.clear();
            selectedRows.add(index);
            lastClickedIndex = index;
        } else if (e.ctrlKey) {
            // ctrl hozzáad/töröl
            if (selectedRows.has(index)) selectedRows.delete(index);
            else selectedRows.add(index);
            lastClickedIndex = index;
        } else if (e.shiftKey) {
            // shift tartomány
            if (lastClickedIndex === null) lastClickedIndex = index;
            const start = Math.min(lastClickedIndex, index);
            const end = Math.max(lastClickedIndex, index);
            selectedRows.clear();
            for (let i = start; i <= end; i++) selectedRows.add(i);
        }
        updateSelectionVisuals();
    });
});
    
// ===== Billentyűzet kezelése =====
    
function isEditable(el) {
    return el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable;
}
    
document.addEventListener('keydown', (e) => {
    
    const active = document.activeElement;

// Ha textarea-ban vagy contenteditable mezőben vagyunk
if (active.tagName === 'TEXTAREA' || active.isContentEditable) {
    // TAB -> beszúr tab karaktert
    if (e.key === 'Tab') {
        e.preventDefault();
        const start = active.selectionStart;
        const end = active.selectionEnd;
        const value = active.value;
        active.value = value.substring(0, start) + '\t' + value.substring(end);
        active.selectionStart = active.selectionEnd = start + 1;
    }

    // ENTER -> hagyjuk, hogy új sort kezdjen
    if (e.key === 'Enter') {
        // semmit sem csinálunk, hagyja az alapértelmezettet
        return;
    }

    // kilépünk, a további globális keydown logika ne fusson
    return;
}
    
    // TAB -> törli a keresőt
    if (e.key === 'Tab') {
        e.preventDefault();
        searchInput.value = '';
        searchInput.dispatchEvent(new Event('input'));
        return;
    }

    // BACKSPACE -> visszalépés ha nem inputban
    if (e.key === 'Backspace' && !isEditable(document.activeElement)) {
        e.preventDefault();
        history.back();
        return;
    }

    // ESC -> clear selection vagy visszalépés
    if (e.key === 'Escape') {
        e.preventDefault();
        if (document.activeElement === searchInput || selectedRows.size > 0) {
            selectedRows.clear();
            updateSelectionVisuals();
            if (document.activeElement === searchInput) searchInput.blur();
        } else {
            window.location.href = '../';
        }
        return;
    }

    // ENTER -> ha több kijelölt: új lapokon megnyit, ha egy: navigál
    if (e.key === 'Enter') {
        if (selectedRows.size > 1) {
            e.preventDefault();
            // megnyitjuk mindet új lapon
            selectedRows.forEach(i => {
                const link = allRows[i].querySelector('a.folder-link');
                if (link) window.open(link.href, '_blank');
            });
            return;
        }

        if (selectedRows.size === 1) {
            e.preventDefault();
            const i = [...selectedRows][0];
            const link = allRows[i].querySelector('a.folder-link');
            if (link) window.location.href = link.href;
            return;
        }

        // ha nincs kijelölés, akkor ha inputban vagyunk -> első látható elem
        if (document.activeElement === searchInput) {
            const visible = allRows.filter(r => !r.classList.contains('hidden'));
            if (visible.length > 0) {
                const link = visible[0].querySelector('a.folder-link');
                if (link) window.location.href = link.href;
            }
            return;
        }

        // ha nincs semmi -> fókusz a keresőre
        searchInput.focus();
        return;
    }

    // Nyilak (fel/le) - mozgatás a látható elemek között
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        const visible = allRows.filter(r => !r.classList.contains('hidden'));
        if (visible.length === 0) return;
        e.preventDefault();

        // ha nincs kijelölés -> első látható
        if (selectedRows.size === 0) {
            const idx = allRows.indexOf(visible[0]);
            selectedRows.add(idx);
            lastClickedIndex = idx;
            updateSelectionVisuals();
            return;
        }

        // van kijelölés -> lépés a jelenlegi alapján
        // vesszük az utolsó clicked indexet, egyébként az első kiválasztottat
        let current = lastClickedIndex;
        if (current === null) current = [...selectedRows][0];
        let targetIdx = null;
        if (e.key === 'ArrowDown') {
            // következő visible, amelyik nagyobb index
            let next = visible.find(r => allRows.indexOf(r) > current);
            if (next) targetIdx = allRows.indexOf(next);
        } else {
            // ArrowUp
            let prevs = visible.filter(r => allRows.indexOf(r) < current);
            if (prevs.length) targetIdx = allRows.indexOf(prevs[prevs.length - 1]);
        }
        if (targetIdx !== null) {
            selectedRows.clear();
            selectedRows.add(targetIdx);
            lastClickedIndex = targetIdx;
            updateSelectionVisuals();
        }
        return;
    }

    // Számgombok 0-9 (ha nem inputban vagyunk)
    if (!isEditable(document.activeElement) && /^[0-9]$/.test(e.key)) {
        const visibleRows = Array.from(document.querySelectorAll('.folder-row')).filter(r => !r.classList.contains('hidden'));
        if (e.key === '0') {
            e.preventDefault();
            window.location.href = '../';
            return;
        }
        const idx = parseInt(e.key, 10) - 1;
        if (idx >= 0 && idx < visibleRows.length) {
            e.preventDefault();
            const link = visibleRows[idx].querySelector('a.folder-link');
            if (link) window.location.href = link.href;
        }
        return;
    }      

    // gyorsbillentyűk (ha nem inputban vagyunk)
    if (!isEditable(document.activeElement)) {
        if (e.key.toLowerCase() === 's') {
            e.preventDefault();
            const searchIcon = document.querySelector('.controls a[title="Keresés az összes almappában"]');
            if (searchIcon) window.location.href = searchIcon.href;
            return;
        }

        if (e.key.toLowerCase() === 'd') {
            e.preventDefault();

            // ha több kijelölt -> letöltések sorban, késleltetéssel
            if (selectedRows.size > 1) {
                // gyűjtsük össze a letöltési hrefeket
                const hrefs = [];
                selectedRows.forEach(i => {
                    const dl = allRows[i].querySelector('.download-icon');
                    if (dl && dl.href) hrefs.push(dl.href);
                });

                // ha nincs semmi, fallback a felső mappára
                if (hrefs.length === 0) {
                    const downloadIcon = document.querySelector('.controls a[title="Mappa letöltése"]');
                    if (downloadIcon) window.location.href = downloadIcon.href;
                    return;
                }

                const delayMs = 300; // módosítható késleltetés ms-ben

                // sorban indítjuk a letöltéseket (popup-ok elkerülésére érdemes kisebb számú egyidejű open használat)
                (function downloadSeq(i){
                    if (i >= hrefs.length) return;
                    // új ablakban nyitjuk meg a letöltést (browser szabályok miatt)
                    const w = window.open(hrefs[i], '_blank');
                    // ha popup blokkolva lett, próbáljunk meg anchor click-et helyette
                    if (!w) {
                        const a = document.createElement('a');
                        a.href = hrefs[i];
                        a.target = '_blank';
                        document.body.appendChild(a);
                        a.click();
                        a.remove();
                    }
                    setTimeout(() => downloadSeq(i+1), delayMs);
                })(0);

                return;
            }

            // egy kijelölt -> normál letöltés
            if (selectedRows.size === 1) {
                const i = [...selectedRows][0];
                const dl = allRows[i].querySelector('.download-icon');
                if (dl && dl.href) window.location.href = dl.href;
                return;
            }

            // semmi -> felső mappa letöltés
            const downloadIcon = document.querySelector('.controls a[title="Mappa letöltése"]');
            if (downloadIcon) window.location.href = downloadIcon.href;
            return;
        }

        if (e.key.toLowerCase() === 'l') {
            e.preventDefault();
            const adminIcon = document.querySelector('.controls a[title="Bejelentkezés"]');
            if (adminIcon) window.location.href = adminIcon.href;
            return;
        }
        
        if (e.key.toLowerCase() === 'q') {
            e.preventDefault();
            const logoutIcon = document.querySelector('.controls a[title="Kijelentkezés"]');
            if (logoutIcon) window.location.href = logoutIcon.href;
            return;
        }
        
        <?php if ($isAdmin): ?>
        // if (e.ctrlKey && e.key.toLowerCase() === 'n') {
        if (e.key.toLowerCase() === 'n') {
        e.preventDefault(); // ne nyissa meg a böngésző új ablakát
        modal.style.display = 'flex';
        createForm.querySelector('input[name="name"]').focus();
        }
        <?php endif; ?>
        
        <?php if ($isAdmin): ?>
        if (e.key.toLowerCase() === 'u') {
        e.preventDefault(); // böngésző alapértelmezett tiltása
        uploadModal.style.display = 'flex';
        }
        <?php endif; ?>
    }
    
});

// ===== Gyorsgombok: E (edit), R (rename), M (move), Delete (delete) =====
// Beillesztés: a meglévő <script> végére (vagy a meglévő keydown-handlerbe integrálva).

(function(){
    // segédfüggvény: szerkeszthető-e az aktuális fókusz
    function isEditableEl(el) {
        return el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable);
    }

    // lekéri a kijelölt sorok indexeit (Set-ből -> tömb)
    function getSelectedIndexes() {
        return Array.from(selectedRows); // selectedRows a meglévő Set-ed
    }

    // adott sorhoz megkeresi az adott action-ikon anchor-t (title alapján)
    function findActionAnchorForRowIndex(rowIndex, titleText) {
        const row = allRows[rowIndex];
        if (!row) return null;
        // keresünk anchor-t a soron belül a megadott title-lel
        return row.querySelector('a[title="' + titleText + '"]') || null;
    }

    // Egyedi: ha nincs semmi kijelölés, próbálja a jelenleg fölött lévő (hover/focus) sort használni
    function fallbackSingleIndex() {
        const sel = getSelectedIndexes();
        if (sel.length === 1) return sel[0];
        // próbáljuk az első látható sort
        const visible = allRows.filter(r => !r.classList.contains('hidden'));
        if (visible.length) return allRows.indexOf(visible[0]);
        return null;
    }

    // Szerkesztés (E) - csak ha pontosan egy elem kijelölt
    async function hotEdit() {
        const sel = getSelectedIndexes();
        if (sel.length === 0) {
            // fallback: ha nincs kijelölés, vegyük az első láthatót
            const idx = fallbackSingleIndex();
            if (idx === null) return;
            const editA = findActionAnchorForRowIndex(idx, 'Szerkesztés');
            if (editA) editA.click();
            return;
        }
        if (sel.length > 1) {
            alert('Szerkesztés csak egy elemre lehetséges.');
            return;
        }
        const idx = sel[0];
        const editA = findActionAnchorForRowIndex(idx, 'Szerkesztés');
        if (editA) editA.click();
    }

    // Átnevezés (R) - csak egy elemre
    async function hotRename() {
        const sel = getSelectedIndexes();
        if (sel.length === 0) {
            const idx = fallbackSingleIndex();
            if (idx === null) return;
            const a = findActionAnchorForRowIndex(idx, 'Átnevezés');
            if (a) a.click();
            return;
        }
        if (sel.length > 1) {
            alert('Átnevezés csak egy elemre lehetséges.');
            return;
        }
        const idx = sel[0];
        const a = findActionAnchorForRowIndex(idx, 'Átnevezés');
        if (a) a.click();
    }

    // Áthelyezés (M) - csak egy elemre
    async function hotMove() {
        const sel = getSelectedIndexes();
        if (sel.length === 0) {
            const idx = fallbackSingleIndex();
            if (idx === null) return;
            const a = findActionAnchorForRowIndex(idx, 'Áthelyezés');
            if (a) a.click();
            return;
        }
        if (sel.length > 1) {
            alert('Áthelyezés csak egy elemre lehetséges (több elemet jelenleg nem támogat).');
            return;
        }
        const idx = sel[0];
        const a = findActionAnchorForRowIndex(idx, 'Áthelyezés');
        if (a) a.click();
    }
    
    // ---- Billentyűparancs: S a megosztáshoz ----
    document.addEventListener("keydown", function(event) {
        // Ne reagáljon input/textarea/contenteditable mezőben
        const active = document.activeElement;
        if (active.tagName === "INPUT" || active.tagName === "TEXTAREA" || active.isContentEditable) return;

        if (event.key.toLowerCase() === "c") {
            event.preventDefault();

            // Ellenőrizzük a kiválasztott sorokat
            if (typeof selectedRows !== "undefined" && selectedRows.size > 0) {
                const files = [];
                selectedRows.forEach(i => {
                    const el = allRows[i].querySelector(".share-row");
                    if(el) files.push(el.dataset.file);
                });

                if(files.length === 1){
                    copySingle(files[0]);
                } else if (files.length > 1){
                    shareMultiple(files);
                }
            } else {
                // Nincs kiválasztott sor: csak BASE_URL másolása
                const ta = document.createElement("textarea");
                ta.value = BASE_URL;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand("copy");
                document.body.removeChild(ta);
            }
        }
    });


    // Törlés (Delete) - több elemre is működik; egyszeri megerősítés, majd sorban POST
    async function hotDelete() {
        const sel = getSelectedIndexes();
        if (sel.length === 0) {
            const idx = fallbackSingleIndex();
            if (idx === null) return;
            const a = findActionAnchorForRowIndex(idx, 'Törlés');
            if (a) a.click();
            return;
        }

        // bekérjük a confirmot egyszer
        if (!confirm(`Biztosan törlöd a kiválasztott ${sel.length} elemet?`)) return;

        // kigyűjtjük a path-okat: a soron belüli Törlés anchor onclick-jéből kinyerjük az argumentumot
        const paths = [];
        for (const idx of sel) {
            const delA = findActionAnchorForRowIndex(idx, 'Törlés');
            if (!delA) continue;
            const onclick = delA.getAttribute('onclick') || '';
            const m = onclick.match(/doDelete\(\s*'([^']+)'\s*\)/);
            if (m && m[1]) paths.push(m[1]);
            else {
                // ha nincs onclick (vagy más formátum), próbáljuk a data-download-ot vagy href-et
                const link = delA.getAttribute('href') || '';
                if (link) paths.push(link);
            }
        }

        if (paths.length === 0) {
            alert('Nem található törlésre alkalmas útvonal a kijelölt sorokból.');
            return;
        }

        // végrehajtjuk sorban a postAction hívásokat (postAction a meglévő függvényed)
        for (let i = 0; i < paths.length; i++) {
            const path = paths[i];
            try {
                // ha doDelete inline paramként van URL-encodedként, küldjük úgy tovább
                const result = await postAction({ action: 'delete', path });
                if (!result.ok || result.text.trim() !== 'OK') {
                    // ha bármelyik hibát ad, jelezzük, de folytatjuk a többi törléssel
                    console.warn('Törlés hiba:', path, result.text);
                }
            } catch (err) {
                console.error('Hálózati hiba törlés közben:', err);
            }
        }

        // végén frissítünk
        location.reload();
    }

    // kulcsfigyelés
    document.addEventListener('keydown', async (e) => {
        // ha input/textarea fókuszban, ne írjuk felül (kivéve ha explicit engedjük)
        if (isEditableEl(document.activeElement)) return;

        // E -> szerkesztés
        if (!e.ctrlKey && !e.metaKey && !e.altKey && e.key.toLowerCase() === 'e') {
            e.preventDefault();
            await hotEdit();
            return;
        }

        // R -> átnevezés
        if (!e.ctrlKey && !e.metaKey && !e.altKey && e.key.toLowerCase() === 'r') {
            e.preventDefault();
            await hotRename();
            return;
        }

        // M -> áthelyezés
        if (!e.ctrlKey && !e.metaKey && !e.altKey && e.key.toLowerCase() === 'm') {
            e.preventDefault();
            await hotMove();
            return;
        }

        // Delete -> törlés (és Backspace is, ha szeretnéd ugyanazt a hatást)
        if (e.key === 'Delete' || e.key === 'Del') {
            e.preventDefault();
            await hotDelete();
            return;
        }
    });

    // --- biztosítsuk, hogy az allRows és selectedRows változók elérhetők ---
    // (A te meglévő kódod már definiálja őket; ha nem, próbáljuk betölteni őket a DOM-ból.)
    if (typeof allRows === 'undefined') {
        window.allRows = Array.from(document.querySelectorAll('.folder-row'));
    }
    if (typeof selectedRows === 'undefined') {
        // ha nincs, létrehozunk egy üreset (de ekkor a vizuális kiválasztást nem fogja tükrözni)
        window.selectedRows = new Set();
    }
})();

    
</script>

<footer style="margin-top:40px;text-align:center;font-size:0.9em;color:#666;">
    Server is powered by: 
    <a href="https://nemeth-bence.com" target="_blank" style="color:dodgerblue;text-decoration:none;">
        Németh Bence
    </a>
</footer>
</body>
</html>
