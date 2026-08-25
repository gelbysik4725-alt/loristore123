<?php
/**
 * CSV SEARCH — Google Drive / local file
 * Без обязательного curl, потоковый поиск
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Europe/Moscow');
header('X-Content-Type-Options: nosniff');
set_time_limit(300);
ini_set('memory_limit', '256M');

// ===== НАСТРОЙКИ =====
$DRIVE_ID    = '13zk3qz9juPRXhIlJYPDC1XKPQVxKNMvN';
$LOCAL_CSV   = __DIR__ . '/data.csv';           // если положишь файл рядом — возьмёт его
$CACHE_DIR   = __DIR__ . '/cache';
$CACHE_FILE  = $CACHE_DIR . '/drive_data.csv';
$CACHE_TTL   = 6 * 3600;
$MAX_RESULTS = 80;
$DELIMITER   = ','; // или ';'
// =====================

$query = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$refresh = isset($_GET['refresh']);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function u8lower($s) {
    $s = strtolower((string)$s);
    return strtr($s, [
        'А'=>'а','Б'=>'б','В'=>'в','Г'=>'г','Д'=>'д','Е'=>'е','Ё'=>'ё','Ж'=>'ж','З'=>'з',
        'И'=>'и','Й'=>'й','К'=>'к','Л'=>'л','М'=>'м','Н'=>'н','О'=>'о','П'=>'п','Р'=>'р',
        'С'=>'с','Т'=>'т','У'=>'у','Ф'=>'ф','Х'=>'х','Ц'=>'ц','Ч'=>'ч','Ш'=>'ш','Щ'=>'щ',
        'Ъ'=>'ъ','Ы'=>'ы','Ь'=>'ь','Э'=>'э','Ю'=>'ю','Я'=>'я'
    ]);
}

function httpGet($url, $maxBytes = 0) {
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\nAccept: */*\r\n",
            'timeout' => 120,
            'follow_location' => 1,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ];
    if ($maxBytes > 0) {
        // partial not easy with file_get_contents; full get
    }
    $ctx = stream_context_create($opts);
    $data = @file_get_contents($url, false, $ctx);
    return $data === false ? null : $data;
}

function downloadDriveTo($fileId, $dest) {
    if (!is_dir(dirname($dest))) {
        @mkdir(dirname($dest), 0775, true);
    }

    $urls = [
        'https://drive.usercontent.google.com/download?id=' . rawurlencode($fileId) . '&export=download&confirm=t',
        'https://drive.google.com/uc?export=download&id=' . rawurlencode($fileId) . '&confirm=t',
        'https://drive.google.com/uc?export=download&id=' . rawurlencode($fileId),
    ];

    foreach ($urls as $url) {
        // потоковая запись
        $in = @fopen($url, 'r', false, stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Mozilla/5.0\r\n",
                'timeout' => 0,
                'follow_location' => 1,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]));
        if (!$in) continue;

        $out = @fopen($dest, 'w');
        if (!$out) { fclose($in); continue; }

        $written = 0;
        while (!feof($in)) {
            $chunk = fread($in, 1024 * 256);
            if ($chunk === false || $chunk === '') break;
            // если в начале HTML — отмена
            if ($written === 0 && (isset($chunk[0]) && $chunk[0] === '<' || stripos($chunk, '<!DOCTYPE') !== false || stripos($chunk, '<html') !== false)) {
                fclose($in); fclose($out); @unlink($dest); break;
            }
            fwrite($out, $chunk);
            $written += strlen($chunk);
        }
        fclose($in);
        fclose($out);

        if ($written > 500 && file_exists($dest)) {
            return true;
        }
        @unlink($dest);
    }
    return false;
}

function resolveCsvPath($local, $cache, $driveId, $ttl, $refresh) {
    // 1) локальный файл рядом со скриптом
    if (is_file($local) && filesize($local) > 100) {
        return ['path' => $local, 'source' => 'local', 'size' => filesize($local)];
    }
    // 2) кэш
    if (!$refresh && is_file($cache) && filesize($cache) > 100 && (time() - filemtime($cache) < $ttl)) {
        return ['path' => $cache, 'source' => 'cache', 'size' => filesize($cache)];
    }
    // 3) скачать с Drive
    if (downloadDriveTo($driveId, $cache)) {
        return ['path' => $cache, 'source' => 'drive', 'size' => filesize($cache)];
    }
    return null;
}

function searchCsv($path, $query, $delimiter, $maxResults) {
    $fh = @fopen($path, 'r');
    if (!$fh) return [null, [], 0, 'Не открыть файл'];

    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);

    $headers = fgetcsv($fh, 0, $delimiter);
    if (!$headers) { fclose($fh); return [null, [], 0, 'Пустой CSV или неверный разделитель']; }
    $headers = array_map('trim', $headers);

    // авто-детект ; если одна колонка и есть ;
    if (count($headers) === 1 && strpos($headers[0], ';') !== false) {
        rewind($fh);
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($fh);
        $delimiter = ';';
        $headers = fgetcsv($fh, 0, $delimiter);
        $headers = array_map('trim', $headers);
    }

    $q = $query !== '' ? u8lower($query) : '';
    $results = [];
    $scanned = 0;

    while (($data = fgetcsv($fh, 0, $delimiter)) !== false) {
        $scanned++;
        if ($data === [null] || $data === false) continue;

        $row = [];
        foreach ($headers as $i => $name) {
            $row[$name] = isset($data[$i]) ? trim((string)$data[$i]) : '';
        }

        if ($q === '') {
            if (count($results) < min(20, $maxResults)) $results[] = $row;
            continue;
        }

        $hay = u8lower(implode(' ', $row));
        if (strpos($hay, $q) !== false) {
            $results[] = $row;
            if (count($results) >= $maxResults) break;
        }
    }
    fclose($fh);
    return [$headers, $results, $scanned, null];
}

$err = null;
$info = resolveCsvPath($LOCAL_CSV, $CACHE_FILE, $DRIVE_ID, $CACHE_TTL, $refresh);

if (!$info) {
    $err = 'Не удалось получить CSV. Варианты: 1) Положи data.csv рядом с index.php  2) Открой доступ к файлу на Drive: «Все, у кого есть ссылка».';
    $headers = []; $results = []; $scanned = 0; $fileSize = 0; $source = '-';
} else {
    $fileSize = $info['size'];
    $source = $info['source'];
    list($headers, $results, $scanned, $readErr) = searchCsv($info['path'], $query, $DELIMITER, $MAX_RESULTS);
    if ($readErr) $err = $readErr;
}

$count = count($results);
function hl($text, $q) {
    if ($q === '' || $text === '') return h($text);
    return preg_replace('/('.preg_quote($q,'/').')/iu', '<mark>$1</mark>', h($text));
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CSV SEARCH</title>
<style>
@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap");
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;background:#030303;font-family:Inter,system-ui,sans-serif;color:#e5e5e5;-webkit-font-smoothing:antialiased}
body::before{content:"";position:fixed;inset:0;z-index:-1;background:radial-gradient(ellipse at 20% 10%,rgba(34,197,94,.12),transparent 55%),radial-gradient(ellipse at 85% 90%,rgba(34,197,94,.07),transparent 50%)}
.wrap{max-width:900px;margin:0 auto;padding:48px 20px 70px}
.logo{text-align:center;font-size:11px;letter-spacing:10px;color:#22c55e;font-weight:600;margin-bottom:8px}
.sub{text-align:center;font-size:9.5px;color:#55555f;letter-spacing:3px;text-transform:uppercase;margin-bottom:28px}
.search-wrap{position:relative;margin-bottom:14px}
input[type=search]{width:100%;padding:15px 50px 15px 18px;background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.08);border-radius:13px;color:#fff;font:inherit;font-size:14.5px;outline:none}
input[type=search]:focus{border-color:rgba(34,197,94,.6);box-shadow:0 0 0 3px rgba(34,197,94,.13)}
input[type=search]::placeholder{color:#55555f}
.btn-go{position:absolute;right:7px;top:50%;transform:translateY(-50%);width:36px;height:36px;border:0;border-radius:10px;background:linear-gradient(135deg,#22c55e,#16a34a);color:#04120a;cursor:pointer;display:flex;align-items:center;justify-content:center}
.btn-go svg{width:16px;height:16px}
.meta{font-size:12px;color:#55555f;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:10px 18px}
.meta b{color:#22c55e}
.meta a{color:#a1a1aa}
.err{background:rgba(190,40,40,.14);border:1px solid rgba(248,113,113,.3);color:#fca5a5;padding:14px 16px;border-radius:13px;font-size:13px;margin-bottom:16px;line-height:1.6}
.panel{border:1px solid rgba(255,255,255,.08);border-radius:22px;overflow:hidden;background:rgba(12,12,15,.72);backdrop-filter:blur(18px);box-shadow:0 30px 70px -30px #000}
.row{padding:14px 18px;border-bottom:1px solid rgba(255,255,255,.08)}
.row:last-child{border-bottom:0}
.row:hover{background:rgba(34,197,94,.04)}
.cells{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px 12px}
.cell-label{font-size:10px;color:#55555f;margin-bottom:2px}
.cell-val{font-size:13px;word-break:break-word}
mark{background:rgba(34,197,94,.28);color:#fff;border-radius:3px;padding:0 2px}
.empty{text-align:center;padding:40px 16px;color:#55555f}
.empty b{display:block;color:#a1a1aa;font-size:15px;margin-bottom:6px}
.foot{margin-top:36px;text-align:center;font-size:10px;letter-spacing:3px;text-transform:uppercase;color:#55555f}
.foot span{color:#16a34a}
</style>
</head>
<body>
<div class="wrap">
  <div class="logo">CSV SEARCH</div>
  <div class="sub">Drive / local · streaming</div>

  <?php if ($err): ?>
  <div class="err">
    <?= h($err) ?><br><br>
    <b>Как починить:</b><br>
    1. Положи файл как <code>data.csv</code> в ту же папку, что и index.php<br>
    2. Или на Drive: доступ «Все, у кого есть ссылка» → Читатель<br>
    3. <a href="?refresh=1" style="color:#22c55e">Повторить загрузку с Drive</a>
  </div>
  <?php endif; ?>

  <form class="search-wrap" method="get" action="">
    <input type="search" name="q" placeholder="Поиск…" value="<?= h($query) ?>" autofocus autocomplete="off">
    <button class="btn-go" type="submit" aria-label="search">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
    </button>
  </form>

  <?php if (!$err): ?>
  <div class="meta">
    <span>Найдено <b><?= (int)$count ?></b></span>
    <span>Строк просмотрено <b><?= number_format($scanned, 0, '.', ' ') ?></b></span>
    <span>Файл <b><?= round($fileSize/1048576, 1) ?> МБ</b> (<?= h($source) ?>)</span>
    <a href="?refresh=1<?= $query!=='' ? '&q='.urlencode($query) : '' ?>">Обновить</a>
  </div>
  <?php endif; ?>

  <?php if (!$err && $count === 0): ?>
    <div class="panel empty"><b>Ничего не найдено</b>Введите другой запрос</div>
  <?php elseif (!$err): ?>
    <div class="panel">
      <?php foreach ($results as $row): ?>
        <div class="row"><div class="cells">
          <?php foreach ($headers as $col): ?>
            <div>
              <div class="cell-label"><?= h($col) ?></div>
              <div class="cell-val"><?= hl($row[$col] ?? '', $query) ?></div>
            </div>
          <?php endforeach; ?>
        </div></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="foot">CSV Search · <span>fixed</span></div>
</div>
</body>
</html>
