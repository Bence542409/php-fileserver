<?php
// download.php
// Usage: /php/download.php?ekke/bevinf -> serves file ../ekke/bevinf

session_start();

// --- CONFIG ------------------------------------------------------------
$baseDir = realpath(__DIR__ . '/..');
if ($baseDir === false) {
    http_response_code(500);
    echo "Server configuration error.";
    exit;
}

// Engedélyezett gyökérmappák relatívan a baseDir-hez
$ALLOWED_DIRS = [
    'ekke',
    'public',
    'share',
    'install_win',
    'install_mac'
];

// ----------------- segédfüggvények ------------------------------------
function is_in_allowed_dirs($target, $baseDir, $allowedDirs) {
    foreach ($allowedDirs as $dir) {
        $dirPath = realpath($baseDir . DIRECTORY_SEPARATOR . $dir);
        if ($dirPath && strpos($target, $dirPath) === 0) {
            return true;
        }
    }
    return false;
}

function get_allowed_dirs_display($baseDir, $allowedDirs) {
    $out = [];
    foreach ($allowedDirs as $dir) {
        $abs = realpath($baseDir . DIRECTORY_SEPARATOR . $dir);
        if ($abs) {
            $out[] = [
                'name' => $dir,
                'abs'  => $abs,
                'url'  => htmlspecialchars($dir, ENT_QUOTES | ENT_SUBSTITUTE)
            ];
        }
    }
    return $out;
}

// ----------------- MAIN -------------------------------------------------
$raw = $_SERVER['QUERY_STRING'] ?? '';
if ($raw === '') {
    // Nincs megadva fájl -> HTML lista az engedélyezett mappákról és usage
    header('Content-Type: text/html; charset=utf-8');
    $allowedDisplay = get_allowed_dirs_display($baseDir, $ALLOWED_DIRS);
    echo "<!doctype html><html><head><meta charset='utf-8'><title>No file specified</title></head><body>";
    echo "<h2>No file specified.</h2>";
    echo "<p>Usage: <code>download.php?ekke/test.jpg</code></p>";
    echo "<h3>Engedélyezett mappák:</h3><ul>";
    foreach ($allowedDisplay as $d) {
        echo "<li><strong>" . htmlspecialchars($d['name']) . "</strong> — " . htmlspecialchars($d['abs']) . "</li>";
    }
    echo "</ul></body></html>";
    exit;
}

// Decode query string repeatedly to handle double-encoding like %2520 -> %20 -> space
$path = $raw;

// replace '+' with space (common in url-encoded queries)
$path = str_replace('+', ' ', $path);

// repeatedly rawurldecode until stable (handles %25 => % sequences)
$prev = null;
$maxLoops = 5; // safety cap
$loops = 0;
while ($path !== $prev && $loops < $maxLoops) {
    $prev = $path;
    $path = rawurldecode($path);
    $loops++;
}

// normalize leading slashes
$path = ltrim($path, "/\\");

// Basic sanitation (keep strict checks)
if (strpos($path, "\0") !== false || preg_match('/[\x00-\x1F]/', $path)) {
    http_response_code(400);
    echo "Invalid path.";
    exit;
}
if (strpos($path, '..') !== false) {
    http_response_code(400);
    echo "Path traversal not allowed.";
    exit;
}

// Allow more characters: spaces, parentheses and typical safe filename chars.
// We explicitly allow: letters, digits, underscore, dash, dot, slash, spaces and parentheses.
// Use u-flag for unicode safety if filenames might contain UTF-8 (you can expand if needed).
if (!preg_match('/^[A-Za-z0-9_\-\.\s\/()]+$/u', $path)) {
    http_response_code(400);
    echo "Invalid characters in path.";
    exit;
}


$target = realpath($baseDir . DIRECTORY_SEPARATOR . $path);
if ($target === false) {
    http_response_code(404);
    echo "File or directory not found.";
    exit;
}
if (strpos($target, $baseDir) !== 0) {
    http_response_code(403);
    echo "Access denied.";
    exit;
}

// --- LOGIN REDIRECT IF NOT ALLOWED ------------------------------------
if (!is_in_allowed_dirs($target, $baseDir, $ALLOWED_DIRS) && empty($_SESSION['is_admin'])) {
    // Csak a fájl neve + query
    $fileName = basename(__FILE__); // download.php
    $query = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
    $redirectUrl = urlencode($fileName . $query);
    header("Location: login.php?redirect=$redirectUrl");
    exit;
}

// --- SEND FILE OR DIRECTORY --------------------------------------------

// If target is a directory -> create zip and send
if (is_dir($target)) {
    $zipName = basename($target) ?: 'archive';
    $tmpZip = tempnam(sys_get_temp_dir(), 'dlzip_');

    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        echo "Failed to create zip file.";
        exit;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $file) {
        if (!$file->isFile()) continue;
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen($target) + 1);
        $zip->addFile($filePath, $relativePath);
    }

    $zip->close();

    header('Content-Description: File Transfer');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($zipName) . '.zip"');
    header('Content-Length: ' . filesize($tmpZip));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    if (ob_get_level()) ob_end_clean();
    readfile($tmpZip);
    @unlink($tmpZip);
    exit;
}

// If it's a file -> send it
if (is_file($target) && is_readable($target)) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $target) : 'application/octet-stream';
    if ($finfo) finfo_close($finfo);

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . basename($target) . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($target));

    if (ob_get_level()) ob_end_clean();

    $chunkSize = 8 * 1024 * 1024;
    $handle = fopen($target, 'rb');
    if ($handle === false) {
        http_response_code(500);
        echo "Unable to open file.";
        exit;
    }
    while (!feof($handle)) {
        echo fread($handle, $chunkSize);
        flush();
    }
    fclose($handle);
    exit;
}

http_response_code(404);
echo "Not found.";
exit;
