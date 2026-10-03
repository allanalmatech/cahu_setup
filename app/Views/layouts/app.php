<?php
/**
 * @var Core\View $view
 * @var string $content
 * @var Core\Auth $auth
 * @var array|null $currentUser
 * @var array|null $currentSeason
 * @var int $unreadNotifications
 */
$nav = \App\Navigation::tree($auth);
$flash = \Core\Flash::pull();
$pageTitle = $pageTitle ?? 'Dashboard';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= e($pageTitle) ?> &middot; <?= e($appName) ?></title>
    <meta name="theme-color" content="#10291c">
    <link rel="icon" href="<?= asset('assets/img/favicon.svg') ?>" type="image/svg+xml">
    <link rel="manifest" href="<?= asset('manifest.json') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head>
<body>
<div class="cahu-shell">
    <aside class="cahu-sidebar" id="cahu-sidebar">
        <div class="cahu-sidebar__brand">
            <div class="cahu-brandmark">CA</div>
            <div class="cahu-sidebar__brand-text">
                <strong><?= e($appShort) ?></strong>
                <span>Agribusiness Hub</span>
            </div>
        </div>
        <nav class="cahu-nav">
            <?php foreach ($nav as $section): ?>
                <div class="cahu-nav__section">
                    <div class="cahu-nav__section-title"><?= e($section['label']) ?></div>
                    <?php foreach ($section['items'] as $item): ?>
                        <?php
                        $isActive = $item['match'] === '/'
                            ? $currentPath === '/'
                            : str_starts_with($currentPath, $item['match']);
                        ?>
                        <a class="cahu-nav__link <?= $isActive ? 'is-active' : '' ?>" href="<?= e($item['url']) ?>">
                            <span class="ico"><?= $item['icon'] ?></span>
                            <span><?= e($item['label']) ?></span>
                            <?php if (!empty($item['count'])): ?>
                                <span class="cahu-nav__count"><?= e($item['count']) ?></span>
                            <?php endif; ?>
                        </a>
                        <?php if ($isActive && !empty($item['children'])): ?>
                            <?php foreach ($item['children'] as $child): ?>
                                <a class="cahu-nav__sublink <?= $currentPath === $child['match'] ? 'is-active' : '' ?>"
                                   href="<?= e($child['url']) ?>"><?= e($child['label']) ?></a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>
    </aside>

    <div class="cahu-main">
        <header class="cahu-header">
            <button class="cahu-iconbtn d-lg-none" id="cahu-nav-toggle" type="button" aria-label="Toggle navigation">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
            </button>

            <form class="cahu-search" action="<?= url('search') ?>" method="get" role="search">
                <span class="cahu-search__icon">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                </span>
                <input type="search" name="search" id="cahu-global-search" autocomplete="off"
                       placeholder="Search farmer ID, name, phone, garden, crop, batch..."
                       value="<?= e($_GET['search'] ?? '') ?>">
            </form>

            <div class="d-flex align-items-center gap-2 ms-auto">
                <a class="cahu-iconbtn" href="<?= url('map') ?>" title="Command map">
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 6l7-3 8 3 7-3v15l-7 3-8-3-7 3z"/><path d="M8 3v15M16 6v15"/>
                    </svg>
                </a>

                <a class="cahu-iconbtn" href="<?= url('notifications') ?>" title="Notifications">
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8a6 6 0 10-12 0c0 7-3 8-3 8h18s-3-1-3-8"/><path d="M13.7 21a2 2 0 01-3.4 0"/>
                    </svg>
                    <?php if ($unreadNotifications > 0): ?>
                        <span class="cahu-iconbtn__dot"><?= $unreadNotifications > 99 ? '99+' : e($unreadNotifications) ?></span>
                    <?php endif; ?>
                </a>

                <div class="dropdown">
                    <button class="btn btn-link text-decoration-none p-0 d-flex align-items-center gap-2" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="cahu-userchip">
                            <span>
                                <span class="cahu-userchip__name"><?= e(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?></span>
                                <span class="cahu-userchip__role d-block"><?= e(ucwords(str_replace('_', ' ', implode(', ', $auth->roleCodes())))) ?></span>
                            </span>
                        </span>
                        <?php if (!empty($currentUser['profile_photo'])): ?>
                            <img class="cahu-avatar" src="<?= e($currentUser['profile_photo']) ?>" alt="">
                        <?php else: ?>
                            <span class="cahu-avatar"><?= e(strtoupper(substr((string) ($currentUser['first_name'] ?? 'U'), 0, 1))) ?></span>
                        <?php endif; ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><a class="dropdown-item" href="<?= url('profile') ?>">My profile</a></li>
                        <li><a class="dropdown-item" href="<?= url('profile/password') ?>">Change password</a></li>
                        <li><a class="dropdown-item" href="<?= url('portal') ?>">Field portal</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form action="<?= url('logout') ?>" method="post" class="m-0">
                                <?= csrf_field() ?>
                                <button class="dropdown-item text-danger" type="submit">Sign out</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <main class="cahu-content">
            <?php foreach ($flash as $message): ?>
                <div class="alert alert-<?= e($message['type']) ?> alert-dismissible fade show d-flex align-items-start gap-2" role="alert">
                    <div class="flex-grow-1"><?= e($message['message']) ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endforeach; ?>

            <?= $content ?>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>
<?php if (!empty($chartJs)): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<?php endif; ?>
<script src="<?= asset('assets/js/app.js') ?>"></script>
<script>
    // Keeps the header bell current without a full page reload. The count is
    // already rendered server side, so this only nudges it when it changes.
    (function () {
        var dot = document.querySelector('.cahu-iconbtn__dot');
        var bell = document.querySelector('.cahu-iconbtn[href$="notifications"]');
        if (!bell) return;

        setInterval(function () {
            Cahu.get(bell.getAttribute('href') + '/data').then(function (data) {
                var unread = data.unread || 0;
                if (!dot) {
                    if (unread === 0) return;
                    var span = document.createElement('span');
                    span.className = 'cahu-iconbtn__dot';
                    bell.appendChild(span);
                    dot = span;
                }
                if (unread === 0) { dot.remove(); dot = null; return; }
                dot.textContent = unread > 99 ? '99+' : String(unread);
            }).catch(function () { /* offline: the server-rendered count stands */ });
        }, 60000);
    })();
</script>
<?php foreach (($pageScripts ?? []) as $script): ?>
    <script><?= $script ?></script>
<?php endforeach; ?>
</body>
</html>
