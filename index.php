<?php
/**
 * ÉLITE SEARCH — Luxury Data Search
 * Элитный поиск по коллекции премиальных объектов
 */

// Load data
$dataFile = __DIR__ . '/data/items.json';
$items = [];
if (file_exists($dataFile)) {
    $json = file_get_contents($dataFile);
    $items = json_decode($json, true) ?: [];
}

// Get search params
$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$category = isset($_GET['cat']) ? trim($_GET['cat']) : '';

// Categories for filters
$categories = ['Часы', 'Автомобили', 'Вино', 'Отели', 'Мода', 'Авиация'];

// Search logic (UTF-8 safe without mbstring)
$results = $items;

if ($query !== '') {
    $q = mb_strtolower_fallback($query);
    $results = array_filter($results, function ($item) use ($q) {
        $haystack = mb_strtolower_fallback(
            $item['title'] . ' ' .
            $item['description'] . ' ' .
            $item['category'] . ' ' .
            $item['highlight'] . ' ' .
            implode(' ', $item['tags'])
        );
        return strpos($haystack, $q) !== false;
    });
}

/**
 * Simple UTF-8 lowercase fallback (covers Latin + basic Cyrillic)
 */
function mb_strtolower_fallback(string $str): string {
    $str = strtolower($str);
    $map = [
        'А'=>'а','Б'=>'б','В'=>'в','Г'=>'г','Д'=>'д','Е'=>'е','Ё'=>'ё','Ж'=>'ж','З'=>'з',
        'И'=>'и','Й'=>'й','К'=>'к','Л'=>'л','М'=>'м','Н'=>'н','О'=>'о','П'=>'п','Р'=>'р',
        'С'=>'с','Т'=>'т','У'=>'у','Ф'=>'ф','Х'=>'х','Ц'=>'ц','Ч'=>'ч','Ш'=>'ш','Щ'=>'щ',
        'Ъ'=>'ъ','Ы'=>'ы','Ь'=>'ь','Э'=>'э','Ю'=>'ю','Я'=>'я'
    ];
    return strtr($str, $map);
}

if ($category !== '' && in_array($category, $categories, true)) {
    $results = array_filter($results, function ($item) use ($category) {
        return $item['category'] === $category;
    });
}

$results = array_values($results);
$count = count($results);
$total = count($items);

// Helper for active filter
function isActiveCat($cat, $current) {
    return $cat === $current ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ÉLITE SEARCH — Премиальный поиск</title>
  <meta name="description" content="Элитный поиск по коллекции премиальных объектов: часы, автомобили, вино, отели, мода и авиация.">
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><circle cx='50' cy='50' r='40' fill='none' stroke='%23c9a227' stroke-width='6'/><circle cx='50' cy='50' r='12' fill='%23c9a227'/></svg>">
</head>
<body>
  <div class="bg-ambiance"></div>
  <div class="noise"></div>

  <div class="wrapper">
    <header>
      <a href="index.php" class="logo">
        <div class="logo-mark"></div>
        <div class="logo-text">Élite <span>Search</span></div>
      </a>
      <div class="nav-meta">Коллекция · <?= $total ?> объектов</div>
    </header>

    <section class="hero">
      <div class="hero-eyebrow">Curated Excellence</div>
      <h1 class="hero-title">Найдите то,<br>что <em>действительно</em> важно</h1>
      <p class="hero-subtitle">
        Премиальный поиск по коллекции элитных объектов — от легендарных часов до частных островов.
      </p>

      <div class="search-container">
        <form class="search-form" action="index.php" method="GET" role="search">
          <input
            type="search"
            name="q"
            class="search-input"
            placeholder="Rolex, Phantom, Aman, Birkin..."
            value="<?= htmlspecialchars($query, ENT_QUOTES, 'UTF-8') ?>"
            autocomplete="off"
            autofocus
          >
          <?php if ($category !== ''): ?>
            <input type="hidden" name="cat" value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>">
          <?php endif; ?>
          <button type="submit" class="search-btn" aria-label="Искать">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="11" cy="11" r="7"></circle>
              <path d="M21 21l-4.3-4.3"></path>
            </svg>
          </button>
        </form>

        <div class="filters">
          <a href="index.php<?= $query ? '?q=' . urlencode($query) : '' ?>" class="filter-chip <?= $category === '' ? 'active' : '' ?>">Все</a>
          <?php foreach ($categories as $cat): ?>
            <a
              href="index.php?cat=<?= urlencode($cat) ?><?= $query ? '&q=' . urlencode($query) : '' ?>"
              class="filter-chip <?= isActiveCat($cat, $category) ?>"
            >
              <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <div class="results-meta">
      <div class="results-count">
        <?php if ($query !== '' || $category !== ''): ?>
          Найдено <strong><?= $count ?></strong> из <?= $total ?>
          <?php if ($query !== ''): ?>
            по запросу «<?= htmlspecialchars($query, ENT_QUOTES, 'UTF-8') ?>»
          <?php endif; ?>
          <?php if ($category !== ''): ?>
            в категории «<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>»
          <?php endif; ?>
        <?php else: ?>
          Полная коллекция · <strong><?= $total ?></strong> объектов
        <?php endif; ?>
      </div>
    </div>

    <div class="results-grid">
      <?php if ($count === 0): ?>
        <div class="empty-state">
          <div class="empty-state-icon">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <circle cx="11" cy="11" r="7"></circle>
              <path d="M21 21l-4.3-4.3"></path>
            </svg>
          </div>
          <h3>Ничего не найдено</h3>
          <p>Попробуйте изменить запрос или выбрать другую категорию</p>
        </div>
      <?php else: ?>
        <?php foreach ($results as $item): ?>
          <article class="card">
            <div class="card-category"><?= htmlspecialchars($item['category'], ENT_QUOTES, 'UTF-8') ?></div>
            <h2 class="card-title"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="card-highlight"><?= htmlspecialchars($item['highlight'], ENT_QUOTES, 'UTF-8') ?></p>
            <p class="card-desc"><?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?></p>
            <div class="card-footer">
              <span class="card-price"><?= htmlspecialchars($item['price'], ENT_QUOTES, 'UTF-8') ?></span>
              <span class="card-year"><?= (int)$item['year'] ?></span>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <footer>
      <p class="footer-text">Élite Search · <span>Curated for the discerning</span></p>
    </footer>
  </div>

  <script>
    // Subtle focus enhancement
    document.querySelector('.search-input')?.addEventListener('focus', function() {
      this.parentElement.style.transform = 'scale(1.01)';
    });
    document.querySelector('.search-input')?.addEventListener('blur', function() {
      this.parentElement.style.transform = 'scale(1)';
    });
  </script>
</body>
</html>
