<?php
error_reporting(0);
date_default_timezone_set('Europe/Moscow');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$dataFile = __DIR__ . '/data.json';
$items = [];
if (file_exists($dataFile)) {
    $items = json_decode(file_get_contents($dataFile), true) ?: [];
}

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$category = isset($_GET['cat']) ? trim($_GET['cat']) : '';

$categories = [];
foreach ($items as $it) {
    if (!empty($it['category'])) $categories[$it['category']] = true;
}
$categories = array_keys($categories);
sort($categories);

function u8lower($s) {
    $s = strtolower((string)$s);
    return strtr($s, [
        'А'=>'а','Б'=>'б','В'=>'в','Г'=>'г','Д'=>'д','Е'=>'е','Ё'=>'ё','Ж'=>'ж','З'=>'з',
        'И'=>'и','Й'=>'й','К'=>'к','Л'=>'л','М'=>'м','Н'=>'н','О'=>'о','П'=>'п','Р'=>'р',
        'С'=>'с','Т'=>'т','У'=>'у','Ф'=>'ф','Х'=>'х','Ц'=>'ц','Ч'=>'ч','Ш'=>'ш','Щ'=>'щ',
        'Ъ'=>'ъ','Ы'=>'ы','Ь'=>'ь','Э'=>'э','Ю'=>'ю','Я'=>'я'
    ]);
}

$results = $items;
if ($query !== '') {
    $q = u8lower($query);
    $results = array_values(array_filter($results, function ($it) use ($q) {
        $hay = u8lower(
            ($it['title'] ?? '') . ' ' .
            ($it['text'] ?? '') . ' ' .
            ($it['category'] ?? '') . ' ' .
            implode(' ', $it['tags'] ?? [])
        );
        return strpos($hay, $q) !== false;
    }));
}
if ($category !== '') {
    $results = array_values(array_filter($results, fn($it) => ($it['category'] ?? '') === $category));
}

$count = count($results);
$total = count($items);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DATA SEARCH</title>
<style>
@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap");
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --bg:#030303;--surface:rgba(12,12,15,.72);--border:rgba(255,255,255,.08);
  --accent:#22c55e;--accent-dim:#16a34a;--text:#e5e5e5;--muted:#55555f;--soft:#a1a1aa;
}
body{
  min-height:100vh;background:var(--bg);font-family:Inter,system-ui,sans-serif;color:var(--text);
  -webkit-font-smoothing:antialiased;line-height:1.5;
}
body::before{
  content:"";position:fixed;inset:0;z-index:-1;pointer-events:none;
  background:radial-gradient(ellipse at 20% 10%,rgba(34,197,94,.12),transparent 55%),
             radial-gradient(ellipse at 85% 90%,rgba(34,197,94,.07),transparent 50%);
}
.wrap{max-width:680px;margin:0 auto;padding:56px 20px 80px}
.logo{text-align:center;font-size:11px;letter-spacing:10px;color:var(--accent);font-weight:600;margin-bottom:8px}
.sub{text-align:center;font-size:9.5px;color:var(--muted);letter-spacing:3px;text-transform:uppercase;margin-bottom:36px}
.search-wrap{position:relative;margin-bottom:18px}
input[type=search]{
  width:100%;padding:15px 50px 15px 18px;background:rgba(0,0,0,.4);
  border:1px solid var(--border);border-radius:13px;color:#fff;font-family:inherit;font-size:14.5px;outline:none;transition:.18s
}
input[type=search]::placeholder{color:var(--muted)}
input[type=search]:focus{border-color:rgba(34,197,94,.6);box-shadow:0 0 0 3px rgba(34,197,94,.13)}
.btn-go{
  position:absolute;right:7px;top:50%;transform:translateY(-50%);width:36px;height:36px;border:none;border-radius:10px;
  background:linear-gradient(135deg,var(--accent),var(--accent-dim));color:#04120a;cursor:pointer;
  display:flex;align-items:center;justify-content:center;transition:.18s
}
.btn-go:hover{filter:brightness(1.08)}
.btn-go svg{width:16px;height:16px}
.filters{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-bottom:28px}
.chip{
  padding:7px 14px;border-radius:40px;border:1px solid var(--border);background:transparent;
  color:var(--soft);font-size:12px;font-family:inherit;text-decoration:none;transition:.18s
}
.chip:hover{border-color:rgba(34,197,94,.4);color:var(--accent)}
.chip.on{background:rgba(34,197,94,.12);border-color:var(--accent);color:var(--accent)}
.meta{font-size:12px;color:var(--muted);margin-bottom:14px}
.meta b{color:var(--accent);font-weight:600}
.panel{
  border:1px solid var(--border);border-radius:22px;overflow:hidden;background:var(--surface);
  backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);
  box-shadow:0 30px 70px -30px #000,0 0 50px rgba(34,197,94,.07);
  animation:in .4s cubic-bezier(.2,.8,.2,1)
}
@keyframes in{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.row{padding:15px 20px;border-bottom:1px solid var(--border);transition:background .15s}
.row:last-child{border-bottom:none}
.row:hover{background:rgba(34,197,94,.04)}
.row-top{display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin-bottom:4px}
.row-title{font-size:14.5px;font-weight:600;color:#fff;letter-spacing:-.2px}
.row-date{font-size:11px;color:var(--muted);white-space:nowrap}
.row-cat{font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:var(--accent);font-weight:500;margin-bottom:6px}
.row-text{font-size:12.5px;color:var(--soft);line-height:1.55}
.empty{text-align:center;padding:48px 20px;color:var(--muted);font-size:13px}
.empty b{display:block;color:var(--soft);font-size:15px;margin-bottom:6px}
.foot{margin-top:48px;text-align:center;font-size:10px;letter-spacing:3px;text-transform:uppercase;color:var(--muted)}
.foot span{color:var(--accent-dim)}
::selection{background:rgba(34,197,94,.3);color:#fff}
</style>
</head>
<body>
<div class="wrap">
  <div class="logo">DATA SEARCH</div>
  <div class="sub">Search your data</div>

  <form class="search-wrap" method="get" action="">
    <input type="search" name="q" placeholder="Поиск по данным…" value="<?= htmlspecialchars($query, ENT_QUOTES, 'UTF-8') ?>" autofocus autocomplete="off">
    <?php if ($category !== ''): ?>
      <input type="hidden" name="cat" value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <button class="btn-go" type="submit" aria-label="Искать">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
    </button>
  </form>

  <div class="filters">
    <a class="chip <?= $category===''?'on':'' ?>" href="?<?= $query!==''?'q='.urlencode($query):'' ?>">Все</a>
    <?php foreach ($categories as $c): ?>
      <a class="chip <?= $category===$c?'on':'' ?>" href="?cat=<?= urlencode($c) ?><?= $query!==''?'&q='.urlencode($query):'' ?>"><?= htmlspecialchars($c) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="meta">
    <?php if ($query !== '' || $category !== ''): ?>
      Найдено <b><?= $count ?></b> из <?= $total ?>
    <?php else: ?>
      Записей: <b><?= $total ?></b>
    <?php endif; ?>
  </div>

  <?php if ($count === 0): ?>
    <div class="panel empty"><b>Ничего не найдено</b>Измените запрос или категорию</div>
  <?php else: ?>
    <div class="panel">
      <?php foreach ($results as $it): ?>
        <div class="row">
          <div class="row-cat"><?= htmlspecialchars($it['category'] ?? '') ?></div>
          <div class="row-top">
            <div class="row-title"><?= htmlspecialchars($it['title'] ?? '') ?></div>
            <div class="row-date"><?= htmlspecialchars($it['date'] ?? '') ?></div>
          </div>
          <div class="row-text"><?= htmlspecialchars($it['text'] ?? '') ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="foot">Data Search · <span>your data</span></div>
</div>
</body>
</html>
