<?php
/**
 * CSV SEARCH — загрузка CSV через сайт + поиск
 * На Render Free файл живёт до сна/редеплоя
 */
error_reporting(0);
date_default_timezone_set('Europe/Moscow');
header('X-Content-Type-Options: nosniff');
set_time_limit(600);
ini_set('memory_limit', '512M');
ini_set('upload_max_filesize', '300M');
ini_set('post_max_size', '300M');
ini_set('max_execution_time', '600');

$DATA_DIR  = __DIR__ . '/data';
$DATA_FILE = $DATA_DIR . '/database.csv';
$MAX_RESULTS = 80;
$DELIMITER = ',';

if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0775, true);

$query = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$msg = '';
$err = '';

// --- загрузка ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    $f = $_FILES['csv'];
    if ($f['error'] === UPLOAD_ERR_OK) {
        $tmp = $f['tmp_name'];
        $name = $f['name'];
        $size = (int)$f['size'];
        // проверка расширения
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt', 'tsv'], true)) {
            $err = 'Нужен файл .csv';
        } elseif ($size < 10) {
            $err = 'Файл пустой';
        } else {
            // определить разделитель по первой строке
            $head = file_get_contents($tmp, false, null, 0, 2000);
            if (substr_count($head, ';') > substr_count($head, ',')) {
                @file_put_contents($DATA_DIR . '/delimiter.txt', ';');
            } else {
                @file_put_contents($DATA_DIR . '/delimiter.txt', ',');
            }
            if (@move_uploaded_file($tmp, $DATA_FILE) || @copy($tmp, $DATA_FILE)) {
                $msg = 'Загружено: ' . round($size / 1048576, 1) . ' МБ';
            } else {
                $err = 'Не удалось сохранить. На Free Render иногда мало места — попробуй ещё раз.';
            }
        }
    } else {
        $codes = [
            UPLOAD_ERR_INI_SIZE => 'Файл больше лимита PHP (upload_max_filesize)',
            UPLOAD_ERR_FORM_SIZE => 'Файл слишком большой',
            UPLOAD_ERR_PARTIAL => 'Загружен частично — попробуй снова',
            UPLOAD_ERR_NO_FILE => 'Файл не выбран',
            UPLOAD_ERR_NO_TMP_DIR => 'Нет tmp на сервере',
            UPLOAD_ERR_CANT_WRITE => 'Ошибка записи на диск',
        ];
        $err = $codes[$f['error']] ?? ('Ошибка загрузки #' . $f['error']);
    }
}

if (is_file($DATA_DIR . '/delimiter.txt')) {
    $DELIMITER = trim(file_get_contents($DATA_DIR . '/delimiter.txt')) ?: ',';
}

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

function searchCsv($path, $query, $delimiter, $maxResults) {
    $fh = @fopen($path, 'r');
    if (!$fh) return [null, [], 0];
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);
    $headers = fgetcsv($fh, 0, $delimiter);
    if (!$headers) { fclose($fh); return [null, [], 0]; }
    $headers = array_map('trim', $headers);
    if (count($headers) === 1 && strpos($headers[0], ';') !== false) {
        rewind($fh);
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($fh);
        $delimiter = ';';
        $headers = array_map('trim', fgetcsv($fh, 0, $delimiter));
    }
    $q = $query !== '' ? u8lower($query) : '';
    $results = [];
    $scanned = 0;
    while (($data = fgetcsv($fh, 0, $delimiter)) !== false) {
        $scanned++;
        $row = [];
        foreach ($headers as $i => $name) {
            $row[$name] = isset($data[$i]) ? trim((string)$data[$i]) : '';
        }
        if ($q === '') {
            if (count($results) < 15) $results[] = $row;
            continue;
        }
        if (strpos(u8lower(implode(' ', $row)), $q) !== false) {
            $results[] = $row;
            if (count($results) >= $maxResults) break;
        }
    }
    fclose($fh);
    return [$headers, $results, $scanned];
}

$hasData = is_file($DATA_FILE) && filesize($DATA_FILE) > 50;
$fileSize = $hasData ? filesize($DATA_FILE) : 0;
$headers = [];
$results = [];
$scanned = 0;

if ($hasData) {
    list($headers, $results, $scanned) = searchCsv($DATA_FILE, $query, $DELIMITER, $MAX_RESULTS);
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
.wrap{max-width:900px;margin:0 auto;padding:40px 20px 70px}
.logo{text-align:center;font-size:11px;letter-spacing:10px;color:#22c55e;font-weight:600;margin-bottom:8px}
.sub{text-align:center;font-size:9.5px;color:#55555f;letter-spacing:3px;text-transform:uppercase;margin-bottom:28px}
.card{border:1px solid rgba(255,255,255,.08);border-radius:18px;background:rgba(12,12,15,.72);backdrop-filter:blur(16px);padding:20px;margin-bottom:20px;box-shadow:0 20px 50px -30px #000}
.card h2{font-size:13px;color:#22c55e;letter-spacing:.08em;text-transform:uppercase;margin-bottom:12px}
.upload-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
input[type=file]{font-size:13px;color:#a1a1aa;max-width:100%}
.btn{padding:11px 18px;border:none;border-radius:11px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;background:linear-gradient(135deg,#22c55e,#16a34a);color:#04120a}
.btn:hover{filter:brightness(1.06)}
.btn-dark{background:rgba(255,255,255,.06);color:#e5e5e5;border:1px solid rgba(255,255,255,.1)}
.hint{font-size:12px;color:#55555f;margin-top:10px;line-height:1.5}
.ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#86efac;padding:12px 14px;border-radius:11px;font-size:13px;margin-bottom:16px}
.err{background:rgba(190,40,40,.14);border:1px solid rgba(248,113,113,.3);color:#fca5a5;padding:12px 14px;border-radius:11px;font-size:13px;margin-bottom:16px}
.search-wrap{position:relative;margin-bottom:14px}
input[type=search]{width:100%;padding:15px 50px 15px 18px;background:rgba(0,0,0,.4);border:1px solid rgba(255,255,255,.08);border-radius:13px;color:#fff;font:inherit;font-size:14.5px;outline:none}
input[type=search]:focus{border-color:rgba(34,197,94,.6);box-shadow:0 0 0 3px rgba(34,197,94,.13)}
input[type=search]::placeholder{color:#55555f}
.btn-go{position:absolute;right:7px;top:50%;transform:translateY(-50%);width:36px;height:36px;border:0;border-radius:10px;background:linear-gradient(135deg,#22c55e,#16a34a);color:#04120a;cursor:pointer;display:flex;align-items:center;justify-content:center}
.btn-go svg{width:16px;height:16px}
.meta{font-size:12px;color:#55555f;margin-bottom:14px}
.meta b{color:#22c55e}
.panel{border:1px solid rgba(255,255,255,.08);border-radius:22px;overflow:hidden;background:rgba(12,12,15,.72)}
.row{padding:14px 18px;border-bottom:1px solid rgba(255,255,255,.08)}
.row:last-child{border-bottom:0}
.row:hover{background:rgba(34,197,94,.04)}
.cells{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px 12px}
.cell-label{font-size:10px;color:#55555f}
.cell-val{font-size:13px;word-break:break-word}
mark{background:rgba(34,197,94,.28);color:#fff;border-radius:3px;padding:0 2px}
.empty{text-align:center;padding:36px 16px;color:#55555f;font-size:13px}
.empty b{display:block;color:#a1a1aa;margin-bottom:6px;font-size:15px}
.foot{margin-top:36px;text-align:center;font-size:10px;letter-spacing:3px;text-transform:uppercase;color:#55555f}
.foot span{color:#16a34a}
.warn{font-size:11px;color:#a78bfa;margin-top:8px;line-height:1.5}
</style>
</head>
<body>
<div class="wrap">
  <div class="logo">CSV SEARCH</div>
  <div class="sub">Upload · Search · Online</div>

  <?php if ($msg): ?><div class="ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

  <div class="card">
    <h2>Загрузить базу</h2>
    <form method="post" enctype="multipart/form-data" class="upload-row">
      <input type="file" name="csv" accept=".csv,.txt,.tsv" required>
      <button class="btn" type="submit">Залить CSV</button>
    </form>
    <p class="hint">Выбери свой файл (до ~250–300 МБ). Первая строка = заголовки колонок.</p>
    <p class="warn">⚠ Render Free: после сна сервиса файл пропадёт — нужно залить снова. Пока сервис бодрствует — поиск работает.</p>
  </div>

  <?php if ($hasData): ?>
  <form class="search-wrap" method="get">
    <input type="search" name="q" placeholder="Поиск по базе…" value="<?= h($query) ?>" autofocus autocomplete="off">
    <button class="btn-go" type="submit" aria-label="search">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
    </button>
  </form>
  <div class="meta">
    Найдено <b><?= (int)$count ?></b>
    · строк просмотрено <b><?= number_format($scanned, 0, '.', ' ') ?></b>
    · файл <b><?= round($fileSize / 1048576, 1) ?> МБ</b>
  </div>
  <?php if ($count === 0): ?>
    <div class="panel empty"><b>Ничего не найдено</b>Измените запрос</div>
  <?php else: ?>
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
  <?php else: ?>
    <div class="panel empty"><b>База ещё не загружена</b>Нажми «Залить CSV» выше</div>
  <?php endif; ?>

  <div class="foot">CSV Search · <span>upload</span></div>
</div>
</body>
</html>
