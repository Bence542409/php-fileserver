<?php
$baseDir = realpath(__DIR__ . '/..');
if ($baseDir === false) {
    http_response_code(500);
    exit("Hiba: a baseDir nem található.");
}

// alapértelmezett keresési mappa
$ALLOWED_DIRS = ['ekke', 'public' , 'share', 'install_win', 'install_mac'];

session_start();

// admin engedélyezett mappák
if (!empty($_SESSION['is_admin'])) {
    $ALLOWED_DIRS = ['.'];
}

function safeRelativePath($fullPath, $baseDir) {
    $real = realpath($fullPath);
    if ($real === false) return false;
    $baseNorm = str_replace('\\', '/', $baseDir);
    $realNorm = str_replace('\\', '/', $real);
    if (strncmp($realNorm, $baseNorm, strlen($baseNorm)) !== 0) return false;
    $rel = substr($realNorm, strlen($baseNorm));
    return ltrim($rel, '/');
}

function searchFilesRecursive($dir, $baseDir, $query, &$out, $depth = 0, $allowedDirs = []) {
    $items = @scandir($dir);
    if ($items === false) return;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;

        $full = $dir . DIRECTORY_SEPARATOR . $item;
        $rel = safeRelativePath($full, $baseDir);
        if ($rel === false) continue;

        $isAllowed = false;
        foreach ($allowedDirs as $ad) {
            if ($ad === '.' || $rel === $ad || str_starts_with($rel, $ad . '/')) {
                $isAllowed = true;
                break;
            }
        }
        if (!$isAllowed) continue;

        $match = ($query !== '' && stripos(strtolower($rel), strtolower($query)) !== false);

        if (is_dir($full)) {
            if ($match || ($query === '' && isset($_GET['q']))) {
                $out[] = ['type' => 'dir', 'full' => $full, 'rel' => $rel, 'depth' => $depth];
            }
            searchFilesRecursive($full, $baseDir, $query, $out, $depth + 1, $allowedDirs);
        } else {
            if ($match || ($query === '' && isset($_GET['q']))) {
                $out[] = ['type' => 'file', 'full' => $full, 'rel' => $rel, 'depth' => $depth];
            }
        }
    }
}

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$results = [];

if (isset($_GET['q'])) {
    searchFilesRecursive($baseDir, $baseDir, $query, $results, 0, $ALLOWED_DIRS);
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<title>Fájlkereső</title>
<style>
body { font-family: Arial, sans-serif; background:#fafafa; padding:20px; }
.card { background:#fff; padding:20px; border-radius:10px; max-width:1000px; margin:auto; }
input[type=text] { width:70%; padding:8px; border-radius:4px; border:1px solid #ccc; font-size:14px; }
button { padding:8px 12px; border-radius:4px; border:none; background:#007bff; color:#fff; cursor:pointer; }
ul { list-style:none; padding-left:0; margin-top:10px; }
li { display:flex; gap:10px; align-items:center; padding:6px 0; border-bottom:1px solid #eee; word-break:break-all; }
.icon { width:24px; text-align:center; }
.path { flex:1; font-family:monospace; }
a { color:#007bff; text-decoration:none; }
.depth { display:inline-block; width:calc(var(--depth) * 20px); }
.hidden { display:none; }
.admin-btn { display: inline-block; margin-top:10px; padding:6px 10px; background:#28a745; color:#fff; border:none; border-radius:4px; cursor:pointer;}
.logout-btn { background:#dc3545; }
.small { font-size:13px; color:#555; }
.error { color:red; font-weight:bold; margin-top:8px; }
</style>
</head>
<body>
<div class="card">
    <h2>Fájlkereső</h2>
    <p class="small">Engedélyezett útvonalak: <br />
        <?php 
        foreach ($ALLOWED_DIRS as $dir) {
            echo htmlspecialchars($baseDir . DIRECTORY_SEPARATOR . $dir) . '<br>';
        }
        ?>
    </p>

    <form method="get" style="margin-bottom:10px;">
        <input type="text" id="search" name="q" placeholder="Keresés név vagy útvonal alapján..." value="<?php echo htmlspecialchars($query); ?>" autofocus>
        <button type="submit">Keresés</button>
    </form>

    <?php if (empty($_SESSION['is_admin'])): ?>
        <!-- Admin bejelentkezés gomb, átirányít a login.php-ra -->
        <a class="admin-btn" href="login.php?redirect=<?php echo urlencode(basename($_SERVER['PHP_SELF'])); ?>">Admin bejelentkezés</a>
    <?php else: ?>
        <!-- Kijelentkezés link a login.php-ra -->
        <a class="admin-btn logout-btn" href="login.php?logout=1">Admin kijelentkezés</a>
        <div class="small" style="display:inline-block; margin-left:12px;"></div>
    <?php endif; ?>

    <?php if (isset($_GET['q'])): ?>
        <?php if (empty($results)): ?>
            <p class="error" style="margin-top:30px;">Nincs találat, vagy nincs engedélye az útvonalhoz.</p>
        <?php else: ?>
            <h3 style="margin-top:30px;">Találatok: <?php echo count($results); ?></h3>
            <ul>
                <?php foreach ($results as $r): ?>
                    <li class="folder-row" style="--depth: <?php echo $r['depth']; ?>;">
                        <span class="depth"></span>
                        <span class="icon"><?php echo $r['type']==='dir'?'📁':'📄'; ?></span>
                        <span class="path"><a class="folder-link" href="../<?php echo htmlspecialchars($r['rel']); ?>" target="_blank"><?php echo htmlspecialchars($r['rel']); ?></a></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
