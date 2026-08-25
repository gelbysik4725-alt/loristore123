<?php
$dataFile = __DIR__ . '/data/items.json';
$items = file_exists($dataFile) ? (json_decode(file_get_contents($dataFile), true) ?: []) : [];

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$category = isset($_GET['cat']) ? trim($_GET['cat']) : '';
$categories = ['Часы', 'Автомобили', 'Вино', 'Отели', 'Мода', 'Авиация'];

function utf8_lower($s) {
    $s = strtolower($s);
    return strtr($s, [
        'А'=>'а','Б'=>'б','В'=>'в','Г'=>'г','Д'=>'д','Е'=>'е','Ё'=>'ё','Ж'=>'ж','З'=>'з',
        'И'=>'и','Й'=>'й','К'=>'к','Л'=>'л','М'=>'м','Н'=>'н','О'=>'о','П'=>'п','Р'=>'р',
        'С'=>'с','Т'=>'т','У'=>'у','Ф'=>'ф','Х'=>'х','Ц'=>'ц','Ч'=>'ч','Ш'=>'ш','Щ'=>'щ',
        'Ъ'=>'ъ','Ы'=>'ы','Ь'=>'ь','Э'=>'э','Ю'=>'ю','Я'=>'я'
    ]);
}

$results = $items;
if ($query !== '') {
    $q = utf8_lower($query);
    $results = array_filter($results, function ($item) use ($q) {
        $h = utf8_lower($item['title'].' '.$item['description'].' '.$item['category'].' '.$item['highlight'].' '.implode(' ', $item['tags']));
        return strpos($h, $q) !== false;
    });
}
if ($category !== '' && in_array($category, $categories, true)) {
    $results = array_filter($results, fn($i) => $i['category'] === $category);
}
$results = array_values($results);
$count = count($results);
$total = count($items);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ÉLITE SEARCH</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="wrap">
  <div class="logo">ÉLITE SEARCH</div>
  <div class="tagline">Curated Data</div>

  <form class="search-box" method="GET" action="">
    <input type="search" name="q" class="search-input" placeholder="Поиск по данным..." value="<?= htmlspecialchars($query, ENT_QUOTES, 'UTF-8') ?>" autofocus autocomplete="off">
    <?php if ($category !== ''): ?><input type="hidden" name="cat" value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
    <button type="submit" class="search-btn" aria-label="Искать">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
    </button>
  </form>

  <div class="filters">
    <a href="?<?= $query ? 'q='.urlencode($query) : '' ?>" class="filter <?= $category===''?'active':'' ?>">Все</a>
    <?php foreach ($categories as $cat): ?>
      <a href="?cat=<?= urlencode($cat) ?><?= $query ? '&q='.urlencode($query) : '' ?>" class="filter <?= $category===$cat?'active':'' ?>"><?= htmlspecialchars($cat) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="meta">
    <?php if ($query !== '' || $category !== ''): ?>
      Найдено <strong><?= $count ?></strong> из <?= $total ?>
    <?php else: ?>
      Всего <strong><?= $total ?></strong> записей
    <?php endif; ?>
  </div>

  <?php if ($count === 0): ?>
    <div class="empty"><strong>Ничего не найдено</strong>Измените запрос или категорию</div>
  <?php else: ?>
    <div class="results">
      <?php foreach ($results as $item): ?>
        <div class="row">
          <div>
            <div class="row-cat"><?= htmlspecialchars($item['category']) ?></div>
            <div class="row-title"><?= htmlspecialchars($item['title']) ?></div>
            <div class="row-desc"><?= htmlspecialchars($item['description']) ?></div>
          </div>
          <div class="row-side">
            <div class="row-price"><?= htmlspecialchars($item['price']) ?></div>
            <div><?= (int)$item['year'] ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="footer">Élite Search · <span>Data only</span></div>
</div>
</body>
</html>
