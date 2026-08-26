<?php
/**
 * CSV SEARCH v2.1 — Multi + SQLite + авто-инициализация из /csv
 * Папка csv в репозитории = исходные базы (живут вечно)
 * /data = рабочие SQLite (эфемерные на Render Free)
 */
error_reporting(0);
date_default_timezone_set('Europe/Moscow');
header('X-Content-Type-Options: nosniff');
set_time_limit(600);
ini_set('memory_limit', '512M');
ini_set('upload_max_filesize', '300M');
ini_set('post_max_size', '300M');
ini_set('max_execution_time', '600');

$DATA_DIR   = __DIR__ . '/data';      // рабочие SQLite (эфемерные)
$SOURCE_DIR = __DIR__ . '/csv';       // исходные CSV из GitHub (постоянные)
$MAX_SHOW   = 200;

if (!is_dir($DATA_DIR))   @mkdir($DATA_DIR, 0775, true);
if (!is_dir($SOURCE_DIR)) @mkdir($SOURCE_DIR, 0775, true);

$query    = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$dbSelect = isset($_GET['db']) ? trim((string)$_GET['db']) : 'all';
$msg = $err = '';

// ---------- helpers ----------
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

function safeName($name) {
    $name = pathinfo($name, PATHINFO_FILENAME);
    $name = preg_replace('/[^a-zA-Z0-9_\-\p{L}]/u', '_', $name);
    return mb_substr($name, 0, 60) ?: 'db_' . time();
}

function detectDelimiter($path) {
    $head = @file_get_contents($path, false, null, 0, 4000);
    if ($head === false) return ',';
    $sc = substr_count($head, ';');
    $cc = substr_count($head, ',');
    $tc = substr_count($head, "\t");
    if ($tc > $sc && $tc > $cc) return "\t";
    return $sc > $cc ? ';' : ',';
}

function listDatabases($dir) {
    $list = [];
    foreach (glob($dir . '/*.sqlite') as $f) {
        $name = basename($f, '.sqlite');
        $list[$name] = [
            'sqlite' => $f,
            'csv'    => $dir . '/' . $name . '.csv',
            'size'   => filesize($f),
            'mtime'  => filemtime($f),
        ];
    }
    ksort($list);
    return $list;
}

function importCsvToSqlite($csvPath, $sqlitePath, $table = 'data') {
    $delimiter = detectDelimiter($csvPath);
    $pdo = new PDO('sqlite:' . $sqlitePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL;');

    $fh = fopen($csvPath, 'r');
    if (!$fh) throw new Exception('Не удалось открыть CSV: ' . basename($csvPath));

    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);

    $headers = fgetcsv($fh, 0, $delimiter);
    if (!$headers) { fclose($fh); throw new Exception('Нет заголовков в ' . basename($csvPath)); }
    $headers = array_map('trim', $headers);

    if (count($headers) === 1 && strpos($headers[0], ';') !== false) {
        rewind($fh);
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($fh);
        $delimiter = ';';
        $headers = array_map('trim', fgetcsv($fh, 0, $delimiter));
    }

    $cols = [];
    foreach ($headers as $i => $h) {
        $c = preg_replace('/[^a-zA-Z0-9_\p{L}]/u', '_', $h);
        $c = $c === '' ? 'col_' . $i : $c;
        $base = $c; $n = 1;
        while (isset($cols[$c])) { $c = $base . '_' . $n++; }
        $cols[$c] = true;
        $headers[$i] = $c;
    }
    $headers = array_values($headers);

    $pdo->exec("DROP TABLE IF EXISTS \"$table\"");
    $colDefs = array_map(fn($c) => "\"$c\" TEXT", $headers);
    $pdo->exec('CREATE TABLE "' . $table . '" (' . implode(',', $colDefs) . ')');

    $placeholders = implode(',', array_fill(0, count($headers), '?'));
    $colList = '"' . implode('","', $headers) . '"';
    $stmt = $pdo->prepare("INSERT INTO \"$table\" ($colList) VALUES ($placeholders)");

    $pdo->beginTransaction();
    $count = 0;
    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
        $row = array_pad(array_slice($row, 0, count($headers)), count($headers), '');
        $row = array_map('trim', $row);
        $stmt->execute($row);
        $count++;
        if ($count % 5000 === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
        }
    }
    $pdo->commit();
    fclose($fh);
    return [$headers, $count];
}

/**
 * Авто-инициализация всех CSV из папки csv (GitHub)
 * Запускается при каждом запросе, но реально импортирует только если SQLite отсутствует или устарел
 */
function autoInitFromSource($sourceDir, $dataDir) {
    if (!is_dir($sourceDir)) return 0;
    $imported = 0;
    $files = array_merge(
        glob($sourceDir . '/*.csv') ?: [],
        glob($sourceDir . '/*.txt') ?: [],
        glob($sourceDir . '/*.tsv') ?: []
    );
    foreach ($files as $csv) {
        $base = safeName(basename($csv));
        $sqlite = $dataDir . '/' . $base . '.sqlite';
        // Импортируем, если нет SQLite или исходный CSV новее
        if (!file_exists($sqlite) || filemtime($csv) > filemtime($sqlite)) {
            try {
                @copy($csv, $dataDir . '/' . $base . '.csv');
                importCsvToSqlite($csv, $sqlite);
                $imported++;
            } catch (Throwable $e) {
                // пропускаем битые файлы
            }
        }
    }
    return $imported;
}

// ========== АВТО-ИНИЦИАЛИЗАЦИЯ ИЗ ПАПКИ csv ==========
$autoImported = autoInitFromSource($SOURCE_DIR, $DATA_DIR);
if ($autoImported > 0) {
    $msg = "Автоматически инициализировано баз из /csv: <b>$autoImported</b>";
}

// ---------- ручная загрузка ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    $f = $_FILES['csv'];
    if ($f['error'] === UPLOAD_ERR_OK) {
        $tmp  = $f['tmp_name'];
        $name = $f['name'];
        $size = (int)$f['size'];
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'txt', 'tsv'], true)) {
            $err = 'Нужен файл .csv / .txt / .tsv';
        } elseif ($size < 20) {
            $err = 'Файл пустой';
        } else {
            $base = safeName($name);
            $csvDest    = $DATA_DIR . '/' . $base . '.csv';
            $sqliteDest = $DATA_DIR . '/' . $base . '.sqlite';

            if (@move_uploaded_file($tmp, $csvDest) || @copy($tmp, $csvDest)) {
                try {
                    list($headers, $cnt) = importCsvToSqlite($csvDest, $sqliteDest);
                    $msg = "Загружено и инициализировано: <b>" . h($base) . "</b> — " .
                           number_format($cnt, 0, '.', ' ') . " строк, " .
                           round($size / 1048576, 1) . " МБ";
                } catch (Throwable $e) {
                    @unlink($sqliteDest);
                    $err = 'Ошибка импорта: ' . $e->getMessage();
                }
            } else {
                $err = 'Не удалось сохранить файл.';
            }
        }
    } else {
        $codes = [
            UPLOAD_ERR_INI_SIZE   => 'Файл больше лимита PHP',
            UPLOAD_ERR_FORM_SIZE  => 'Файл слишком большой',
            UPLOAD_ERR_PARTIAL    => 'Загружен частично',
            UPLOAD_ERR_NO_FILE    => 'Файл не выбран',
            UPLOAD_ERR_NO_TMP_DIR => 'Нет tmp',
            UPLOAD_ERR_CANT_WRITE => 'Ошибка записи',
        ];
        $err = $codes[$f['error']] ?? ('Ошибка #' . $f['error']);
    }
}

// ---------- удаление ----------
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $del = safeName($_GET['delete']);
    @unlink($DATA_DIR . '/' . $del . '.csv');
    @unlink($DATA_DIR . '/' . $del . '.sqlite');
    header('Location: ?');
    exit;
}

// ---------- экспорт отчёта ----------
if (isset($_GET['export']) && $query !== '') {
    $dbs = listDatabases($DATA_DIR);
    $toSearch = $dbSelect === 'all' ? array_keys($dbs) : [$dbSelect];
    $allRows = [];
    $headersOut = null;

    foreach ($toSearch as $name) {
        if (!isset($dbs[$name])) continue;
        $pdo = new PDO('sqlite:' . $dbs[$name]['sqlite']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $cols = $pdo->query('PRAGMA table_info(data)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!$cols) continue;
        if ($headersOut === null) $headersOut = $cols;

        $where = []; $params = [];
        $q = u8lower($query);
        foreach ($cols as $c) {
            $where[] = "LOWER(\"$c\") LIKE ?";
            $params[] = '%' . $q . '%';
        }
        $stmt = $pdo->prepare('SELECT * FROM data WHERE ' . implode(' OR ', $where));
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['_source'] = $name;
            $allRows[] = $row;
        }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . date('Y-m-d_H-i') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    if ($headersOut) {
        fputcsv($out, array_merge(['_source'], $headersOut), ';');
        foreach ($allRows as $r) {
            $line = [$r['_source']];
            foreach ($headersOut as $h) $line[] = $r[$h] ?? '';
            fputcsv($out, $line, ';');
        }
    }
    fclose($out);
    exit;
}

// ---------- поиск ----------
$databases = listDatabases($DATA_DIR);
$results = [];
$totalFound = 0;
$headers = [];
$scannedDbs = 0;

if ($query !== '' && $databases) {
    $toSearch = $dbSelect === 'all' ? array_keys($databases) : (isset($databases[$dbSelect]) ? [$dbSelect] : []);
    $q = u8lower($query);

    foreach ($toSearch as $name) {
        $pdo = new PDO('sqlite:' . $databases[$name]['sqlite']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $cols = $pdo->query('PRAGMA table_info(data)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!$cols) continue;
        if (!$headers) $headers = $cols;

        $where = []; $params = [];
        foreach ($cols as $c) {
            $where[] = "LOWER(\"$c\") LIKE ?";
            $params[] = '%' . $q . '%';
        }
        $sql = 'SELECT * FROM data WHERE ' . implode(' OR ', $where) . ' LIMIT ' . ($MAX_SHOW + 1);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['_source'] = $name;
            $results[] = $row;
            if (count($results) >= $MAX_SHOW) break 2;
        }
        $scannedDbs++;
    }
    $totalFound = count($results);
}

function hl($text, $q) {
    if ($q === '' || $text === '') return h($text);
    return preg_replace('/(' . preg_quote($q, '/') . ')/iu', '<mark>$1</mark>', h($text));
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
.wrap{max-width:960px;margin:0 auto;padding:40px 20px 70px}
.logo{text-align:center;font-size:11px;letter-spacing:10px;color:#22c55e;font-weight:600;margin-bottom:8px}
.sub{text-align:center;font-size:9.5px;color:#55555f;letter-spacing:3px;text-transform:uppercase;margin-bottom:28px}
.card{border:1px solid rgba(255,255,255,.08);border-radius:18px;background:rgba(12,12,15,.72);backdrop-filter:blur(16px);padding:20px;margin-bottom:20px;box-shadow:0 20px 50px -30px #000}
.card h2{font-size:13px;color:#22c55e;letter-spacing:.08em;text-transform:uppercase;margin-bottom:12px}
.upload-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
input[type=file]{font-size:13px;color:#a1a1aa;max-width:100%}
.btn{padding:11px 18px;border:none;border-radius:11px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;background:linear-gradient(135deg,#22c55e,#16a34a);color:#04120a;text-decoration:none;display:inline-flex;align-items:center}
.btn:hover{filter:brightness(1.06)}
.btn-dark{background:rgba(255,255,255,.06);color:#e5e5e5;border:1px solid rgba(255,255,255,.1)}
.btn-sm{padding:7px 12px;font-size:12px}
.hint{font-size:12px;color:#55555f;margin-top:10px;line-height:1.5}
.ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#86efac;padding:12px 14px;border-radius:11px;font-size:13px;margin-bottom:16px}
.err{background:rgba(190,40,40,.14);border:1px solid rgba(248,113,113,.3);color:#fca5a5;padding:12px 14px;border-radius:11px;font-size:13px;margin-bottom:16px}
.search-wrap{position:relative;margin-bottom:14px}
input[type=search],select{width:100%;padding:15px 50px 15px 18px;background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.08);border-radius:13px;color:#fff;font:inherit;font-size:14.5px;outline:none}
select{padding-right:18px;cursor:pointer}
input[type=search]:focus,select:focus{border-color:rgba(34,197,94,.6);box-shadow:0 0 0 3px rgba(34,197,94,.13)}
input[type=search]::placeholder{color:#55555f}
.btn-go{position:absolute;right:7px;top:50%;transform:translateY(-50%);width:36px;height:36px;border:0;border-radius:10px;background:linear-gradient(135deg,#22c55e,#16a34a);color:#04120a;cursor:pointer;display:flex;align-items:center;justify-content:center}
.btn-go svg{width:16px;height:16px}
.meta{font-size:12px;color:#55555f;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:12px;align-items:center}
.meta b{color:#22c55e}
.panel{border:1px solid rgba(255,255,255,.08);border-radius:22px;overflow:hidden;background:rgba(12,12,15,.72)}
.row{padding:14px 18px;border-bottom:1px solid rgba(255,255,255,.08)}
.row:last-child{border-bottom:0}
.row:hover{background:rgba(34,197,94,.04)}
.cells{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px 12px}
.cell-label{font-size:10px;color:#55555f}
.cell-val{font-size:13px;word-break:break-word}
.source{font-size:11px;color:#22c55e;margin-bottom:6px}
mark{background:rgba(34,197,94,.28);color:#fff;border-radius:3px;padding:0 2px}
.empty{text-align:center;padding:36px 16px;color:#55555f;font-size:13px}
.empty b{display:block;color:#a1a1aa;margin-bottom:6px;font-size:15px}
.foot{margin-top:36px;text-align:center;font-size:10px;letter-spacing:3px;text-transform:uppercase;color:#55555f}
.foot span{color:#16a34a}
.warn{font-size:11px;color:#a78bfa;margin-top:8px;line-height:1.5}
.db-list{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.db-item{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:8px 12px;font-size:12px;display:flex;align-items:center;gap:8px}
.db-item a{color:#f87171;text-decoration:none;font-size:11px}
.filters{display:grid;grid-template-columns:1fr 180px;gap:10px;margin-bottom:14px}
@media(max-width:600px){.filters{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <div class="logo">CSV SEARCH</div>
  <div class="sub">Multi · SQLite · Auto-init from /csv</div>

  <?php if ($msg): ?><div class="ok"><?= $msg ?></div><?php endif; ?>
  <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

  <div class="card">
    <h2>Загрузить базу вручную</h2>
    <form method="post" enctype="multipart/form-data" class="upload-row">
      <input type="file" name="csv" accept=".csv,.txt,.tsv" required>
      <button class="btn" type="submit">Залить и инициализировать</button>
    </form>
    <p class="hint">
      Основные базы лежат в папке <b>csv/</b> репозитория — они инициализируются автоматически при старте.<br>
      Здесь можно дополнительно заливать временные базы.
    </p>
    <p class="warn">⚠ Render Free: после сна рабочие файлы пропадают, но базы из /csv подтянутся сами при следующем запросе.</p>

    <?php if ($databases): ?>
    <div class="db-list">
      <?php foreach ($databases as $name => $info): ?>
        <div class="db-item">
          <span><b><?= h($name) ?></b> · <?= round($info['size']/1024) ?> KB</span>
          <a href="?delete=<?= urlencode($name) ?>" onclick="return confirm('Удалить <?= h($name) ?>?')">удалить</a>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($databases): ?>
  <form method="get">
    <div class="filters">
      <div class="search-wrap" style="margin:0">
        <input type="search" name="q" placeholder="Поиск по всем полям…" value="<?= h($query) ?>" autofocus autocomplete="off">
        <button class="btn-go" type="submit" aria-label="search">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
        </button>
      </div>
      <select name="db" onchange="this.form.submit()">
        <option value="all" <?= $dbSelect==='all'?'selected':'' ?>>Все базы</option>
        <?php foreach ($databases as $name => $info): ?>
          <option value="<?= h($name) ?>" <?= $dbSelect===$name?'selected':'' ?>><?= h($name) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <?php if ($query !== ''): ?>
  <div class="meta">
    <span>Найдено на экране: <b><?= (int)$totalFound ?></b><?= $totalFound >= $MAX_SHOW ? '+' : '' ?></span>
    <span>Баз: <b><?= (int)$scannedDbs ?></b></span>
    <?php if ($totalFound > 0): ?>
      <a class="btn btn-dark btn-sm" href="?q=<?= urlencode($query) ?>&db=<?= urlencode($dbSelect) ?>&export=1">Скачать отчёт (CSV)</a>
    <?php endif; ?>
  </div>

  <?php if ($totalFound === 0): ?>
    <div class="panel empty"><b>Ничего не найдено</b>Измените запрос</div>
  <?php else: ?>
    <div class="panel">
      <?php foreach ($results as $row): ?>
        <div class="row">
          <div class="source">база: <?= h($row['_source'] ?? '') ?></div>
          <div class="cells">
            <?php foreach ($headers as $col): ?>
              <div>
                <div class="cell-label"><?= h($col) ?></div>
                <div class="cell-val"><?= hl($row[$col] ?? '', $query) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($totalFound >= $MAX_SHOW): ?>
      <p class="hint" style="margin-top:12px">Показаны первые <?= $MAX_SHOW ?> строк. Чтобы получить все совпадения — нажми «Скачать отчёт».</p>
    <?php endif; ?>
  <?php endif; ?>

  <?php else: ?>
    <div class="panel empty"><b>Введите запрос</b>Поиск идёт по всем полям выбранных баз</div>
  <?php endif; ?>

  <?php else: ?>
    <div class="panel empty">
      <b>Баз пока нет</b>
      Положи CSV-файлы в папку <code>csv/</code> репозитория и сделай push,<br>
      либо залей файл вручную выше.
    </div>
  <?php endif; ?>

  <div class="foot">CSV Search · <span>auto from /csv</span></div>
</div>
</body>
</html>
