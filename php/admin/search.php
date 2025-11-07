<?php
$baseDir = realpath(__DIR__ . '/../..');
if ($baseDir === false) {
    http_response_code(500);
    exit("Hiba: a baseDir nem található.");
}

function safeRelativePath($fullPath, $baseDir) {
    $real = realpath($fullPath);
    if ($real === false) return false;
    if (strncmp($real, $baseDir, strlen($baseDir)) !== 0) return false;
    $rel = substr($real, strlen($baseDir));
    return ltrim(str_replace('\\', '/', $rel), '/');
}

// rekurzív keresés hierarchiával, depth a behúzás miatt
function searchFilesRecursive($dir, $baseDir, $query, &$out, $depth = 0) {
    $items = @scandir($dir);
    if ($items === false) return;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;

        $full = $dir . DIRECTORY_SEPARATOR . $item;
        $rel = safeRelativePath($full, $baseDir);
        if ($rel === false) continue;

        $match = ($query !== '' && stripos(strtolower($rel), strtolower($query)) !== false);

        if (is_dir($full)) {
            if ($match || ($query === '' && isset($_GET['q']))) {
                $out[] = ['type' => 'dir', 'full' => $full, 'rel' => $rel, 'depth' => $depth];
            }
            searchFilesRecursive($full, $baseDir, $query, $out, $depth + 1);
        } else {
            if ($match || ($query === '' && isset($_GET['q']))) {
                $out[] = ['type' => 'file', 'full' => $full, 'rel' => $rel, 'depth' => $depth];
            }
        }
    }
}

// UI
$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$results = [];

if (isset($_GET['q'])) {
    searchFilesRecursive($baseDir, $baseDir, $query, $results);
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<title>Fájl kereső</title>
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
a:hover { text-decoration:underline; }
.depth { display:inline-block; width:calc(var(--depth) * 20px); }
.hidden { display:none; }
</style>
</head>
<body>
<div class="card">
    <h2>Fájlkereső</h2>
    <p style="font-size:13px; color:#555;">Útvonal: <code><?php echo htmlspecialchars($baseDir); ?></code></p>

    <form method="get">
        <input type="text" id="search" name="q" placeholder="Keresés név vagy útvonal alapján..." value="<?php echo htmlspecialchars($query); ?>" autofocus>
        <button>Keresés</button>
    </form>

    <?php if (isset($_GET['q'])): ?>
        <?php if (empty($results)): ?>
            <p style="font-size:13px; color:#555; margin-top:10px;">Nincs találat.</p>
        <?php else: ?>
            <h3 style="margin-top:15px;">Találatok: <?php echo count($results); ?></h3>
            <ul>
                <?php foreach ($results as $r): ?>
                    <li class="folder-row" style="--depth: <?php echo $r['depth']; ?>;">
                        <span class="depth"></span>
                        <span class="icon"><?php echo $r['type']==='dir'?'📁':'📄'; ?></span>
                        <span class="path"><a class="folder-link" href="../../<?php echo htmlspecialchars($r['rel']); ?>" target="_blank"><?php echo htmlspecialchars($r['rel']); ?></a></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
