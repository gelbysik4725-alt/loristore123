<?php
/**
 * CSV SEARCH — Google Drive (large file, streaming)
 * Дизайн в стиле LORI
 */
error_reporting(0);
date_default_timezone_set('Europe/Moscow');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
set_time_limit(300);
ini_set('memory_limit', '256M');

// ========== НАСТРОЙКИ ==========
$DRIVE_ID   = '13zk3qz9juPRXhIlJYPDC1XKPQVxKNMvN';
$CACHE_DIR  = sys_get_temp_dir() . '/csv_search_cache';
$CACHE_FILE = $CACHE_DIR . '/data_' . $DRIVE_ID . '.csv';
$CACHE_TTL  = 3600 * 6; // кэш 6 часов
$MAX_RESULTS = 100;
$DELIMITER  = ','; // если ; — поменяй
// ==============================

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$forceRefresh = isset($_GET['refresh']);

function u8lower($s) {
    $s = strtolower((string)$s);
    return strtr($s, [
        'А'=>'а','Б'=>'б','В'=>'в','Г'=>'г','Д'=>'д','Е'=>'е','Ё'=>'ё','Ж'=>'ж','З'=>'з',
        'И'=>'и','Й'=>'й','К'=>'к','Л'=>'л','М'=>'м','Н'=>'н','О'=>'о','П'=>'п','Р'=>'р',
        'С'=>'с','Т'=>'т','У'=>'у','Ф'=>'ф','Х'=>'х','Ц'=>'ц','Ч'=>'ч','Ш'=>'ш','Щ'=>'щ',
        'Ъ'=>'ъ','Ы'=>'ы','Ь'=>'ь','Э'=>'э','Ю'=>'ю','Я'=>'я'
    ]);
}

/** Скачать большой файл с Google Drive (с confirm для >100MB) */
function downloadDriveFile($fileId, $dest) {
    $dir = dirname($dest);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $url = 'https://drive.google.com/uc?export=download&id=' . urlencode($fileId);

    // 1) Первый запрос — может вернуть HTML с confirm
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CSVSearch/1.0)',
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($resp, 0, $headerSize);
    $body = substr($resp, $headerSize);
    curl_close($ch);

    // Ищем confirm-токен и cookie
    $confirm = null;
    if (preg_match('/confirm=([0-9A-Za-z_-]+)/', $body, $m)) {
        $confirm = $m[1];
    } elseif (preg_match('/name="confirm"\s+value="([^"]+)"/', $body, $m)) {
        $confirm = $m[1];
    }
    // иногда токен в UUID-форме в ссылке download
    if (!$confirm && preg_match('/\/uc\?export=download[^"\']*confirm=([0-9A-Za-z_-]+)/', $body, $m)) {
        $confirm = $m[1];
    }

    $cookie = '';
    if (preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $headers, $cm)) {
        $cookie = implode('; ', $cm[1]);
    }

    // 2) Если файл маленький — body уже CSV
    $trim = ltrim($body);
    if ($trim !== '' && $trim[0] !== '<' && strpos($trim, 'Google Drive') === false) {
        return file_put_contents($dest, $body) !== false;
    }

    // 3) Большой файл — качаем с confirm
    $dlUrl = 'https://drive.google.com/uc?export=download&id=' . urlencode($fileId);
    if ($confirm) $dlUrl .= '&confirm=' . urlencode($confirm);
    // альтернативный путь
    $dlUrl2 = 'https://drive.usercontent.google.com/download?id=' . urlencode($fileId) . '&export=download&confirm=t';

    foreach ([$dlUrl, $dlUrl2] as $tryUrl) {
        $fp = fopen($dest, 'w');
        if (!$fp) return false;
        $ch = curl_init($tryUrl);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CSVSearch/1.0)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_COOKIE => $cookie,
            CURLOPT_HTTPHEADER => ['Accept-Language: en-US,en;q=0.9'],
        ]);
        $ok = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        curl_close($ch);
        fclose($fp);

        // проверка: не HTML-страница ли
        if ($ok && $code >= 200 && $code < 400 && $size > 1000) {
            $head = file_get_contents($dest, false, null, 0, 200);
            if ($head !== false && strpos(ltrim($head), '<') !== 0 && stripos($head, 'DOCTYPE') === false) {
                return true;
            }
        }
        @unlink($dest);
    }
    return false;
}

function ensureCache($driveId, $cacheFile, $ttl, $force = false) {
    $need = $force || !file_exists($cacheFile) || (time() - filemtime($cacheFile) > $ttl) || filesize($cacheFile) < 100;
    if (!$need) return ['ok' => true, 'cached' => true, 'size' => filesize($cacheFile)];

    $ok = downloadDriveFile($driveId, $cacheFile);
    if (!$ok || !file_exists($cacheFile) || filesize($cacheFile) < 100) {
        return ['ok' => false, 'error' => 'Не удалось скачать файл с Google Drive. Проверь, что доступ «Все, у кого есть ссылка».'];
    }
    return ['ok' => true, 'cached' => false, 'size' => filesize($cacheFile)];
}

function searchCsv($path, $query, $delimiter, $maxResults) {
    $results = [];
    $headers = null;
    $scanned = 0;
    $fh = @fopen($path, 'r');
    if (!$fh) return [null, [], 0];

    // BOM
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);

    $headers = fgetcsv($fh, 0, $delimiter);
    if (!$headers) { fclose($fh); return [null, [], 0]; }
    $headers = array_map('trim', $headers);

    $q = $query !== '' ? u8lower($query) : '';

    while (($data = fgetcsv($fh, 0, $delimiter)) !== false) {
        $scanned++;
        if (count($data) === 1 && ($data[0] === null || $data[0] === '')) continue;

        $row = [];
        foreach ($headers as $i => $h) {
            $row[$h] = isset($data[$i]) ? trim((string)$data[$i]) : '';
        }

        if ($q === '') {
            // без запроса — только первые N
            if (count($results) < $maxResults) $results[] = $row;
            continue;
        }

        $hay = u8lower(implode(' ', array_values($row)));
        if (strpos($hay, $q) !== false) {
            $results[] = $row;
            if (count($results) >= $maxResults) break;
        }
    }
    fclose($fh);
    return [$headers, $results, $scanned];
}

// --- логика ---
$status = ensureCache($DRIVE_ID, $CACHE_FILE, $CACHE_TTL, $forceRefresh);
$error = null;
$headers = [];
$results = [];
$scanned = 0;
$fileSize = 0;

if (!$status['ok']) {
    $error = $status['error'];
} else {
    $fileSize = $status['size'];
    list($headers, $results, $scanned) = searchCsv($CACHE_FILE, $query, $DELIMITER, $MAX_RESULTS);
    if ($headers === null) $error = 'Не удалось прочитать CSV. Возможно, другой разделитель (попробуй ;).';
}

$count = count($results);
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function highlight($text, $q) {
    if ($q === '' || $text === '') return h($text);
    return preg_replace('/('.preg_quote($q, '/').')/iu', '<mark>$1</mark>', h($text));
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CSV SEARCH · Drive</title>
<style>
@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap");
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#030303;--surface:rgba(12,12,15,.72);--border:rgba(255,255,255,.08);--accent:#22c55e;--accent-dim:#16a34a;--text:#e5e5e5;--muted:#55555f;--soft:#a1a1aa}
body{min-height:100vh;background:var(--bg);font-family:Inter,system-ui,sans-serif;color:var(--text);-webkit-font-smoothing:antialiased;line-height:1.5}
body::before{content:"";position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(ellipse at 20% 10%,rgba(34,197,94,.12),transparent 55%),radial-gradient(ellipse at 85% 90%,rgba(34,197,94,.07),transparent 50%)}
.wrap{max-width:900px;margin:0 auto;padding:48px 20px 80px}
.logo{text-align:center;font-size:11px;letter-spacing:10px;color:var(--accent);font-weight:600;margin-bottom:8px}
.sub{text-align:center;font-size:9.5px;color:var(--muted);letter-spacing:3px;text-transform:uppercase;margin-bottom:28px}
.search-wrap{position:relative;margin-bottom:16px}
input[type=search]{width:100%;padding:15px 50px 15px 18px;background:rgba(0,0,0,.4);border:1px solid var(--border);border-radius:13px;color:#fff;font-family:inherit;font-size:14.5px;outline:none;transition:.18s}
input[type=search]::placeholder{color:var(--muted)}
input[type=search]:focus{border-color:rgba(34,197,94,.6);box-shadow:0 0 0 3px rgba(34,197,94,.13)}
.btn-go{position:absolute;right:7px;top:50%;transform:translateY(-50%);width:36px;height:36px;border:none;border-radius:10px;background:linear-gradient(135deg,var(--accent),var(--accent-dim));color:#04120a;cursor:pointer;display:flex;align-items:center;justify-content:center}
.btn-go:hover{filter:brightness(1.08)}
.btn-go svg{width:16px;height:16px}
.meta{font-size:12px;color:var(--muted);margin-bottom:14px;display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center}
.meta b{color:var(--accent);font-weight:600}
.meta a{color:var(--soft);font-size:11px}
.panel{border:1px solid var(--border);border-radius:22px;overflow:hidden;background:var(--surface);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);box-shadow:0 30px 70px -30px #000,0 0 50px rgba(34,197,94,.07);animation:in .35s cubic-bezier(.2,.8,.2,1)}
@keyframes in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.row{padding:14px 18px;border-bottom:1px solid var(--border)}
.row:last-child{border-bottom:none}
.row:hover{background:rgba(34,197,94,.04)}
.cells{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px 14px}
.cell-label{font-size:10px;color:var(--muted);letter-spacing:.04em;margin-bottom:2px}
.cell-val{font-size:13px;color:var(--text);word-break:break-word}
.cell-val mark{background:rgba(34,197,94,.28);color:#fff;border-radius:3px;padding:0 2px}
.empty{text-align:center;padding:48px 20px;color:var(--muted);font-size:13px}
.empty b{display:block;color:var(--soft);font-size:15px;margin-bottom:8px}
.err{background:rgba(190,40,40,.14);border:1px solid rgba(248,113,113,.28);color:#fca5a5;padding:14px 16px;border-radius:13px;font-size:13px;margin-bottom:16px;line-height:1.5}
.foot{margin-top:40px;text-align:center;font-size:10px;letter-spacing:3px;text-transform:uppercase;color:var(--muted)}
.foot span{color:var(--accent-dim)}
::selection{background:rgba(34,197,94,.3);color:#fff}
</style>
</head>
<body>
<div class="wrap">
  <div class="logo">CSV SEARCH</div>
  <div class="sub">Google Drive · streaming</div>

  <?php if ($error): ?>
    <div class="err"><?= h($error) ?><br><br>
      Проверь доступ к файлу: «Все, у кого есть ссылка» → Читатель.<br>
      <a href="?refresh=1" style="color:#22c55e">Повторить загрузку</a>
    </div>
  <?php endif; ?>

  <form class="search-wrap" method="get">
    <input type="search" name="q" placeholder="Поиск по базе…" value="<?= h($query) ?>" autofocus autocomplete="off">
    <button class="btn-go" type="submit" aria-label="Искать">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
    </button>
  </form>

  <div class="meta">
    <?php if (!$error): ?>
      <span>Найдено <b><?= $count ?></b><?= $count >= $MAX_RESULTS ? ' (лимит)' : '' ?></span>
      <span>Просмотрено строк: <b><?= number_format($scanned, 0, '', ' ') ?></b></span>
      <span>Файл: <b><?= round($fileSize / 1048576, 1) ?> МБ</b></span>
      <a href="?refresh=1<?= $query !== '' ? '&q='.urlencode($query) : '' ?>">Обновить с Drive</a>
    <?php endif; ?>
  </div>

  <?php if (!$error && $count === 0): ?>
    <div class="panel empty"><b>Ничего не найдено</b>Измените запрос</div>
  <?php elseif (!$error): ?>
    <div class="panel">
      <?php foreach ($results as $row): ?>
        <div class="row">
          <div class="cells">
            <?php foreach ($headers as $col): ?>
              <div>
                <div class="cell-label"><?= h($col) ?></div>
                <div class="cell-val"><?= highlight($row[$col] ?? '', $query) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="foot">CSV Search · <span>Drive</span></div>
</div>
</body>
</html>
