<?php
/**
 * Единый сайдбар для profile.php и admin.php (Stage 3.7-f-4-1).
 *
 * Ожидает:
 *   $activeTab   — 'profile' | 'dashboard' | 'users' | 'orders' | 'components'
 *   $isAdmin     — bool (если не передан, берётся из сессии)
 *   $userProfile — массив пользователя (profile.php); в admin.php
 *                  используются данные сессии
 *   $activeSection — id активной секции профиля (card-info / card-builds /
 *                  card-fav); задаётся profile.php из ?section=
 */

$activeTab = $activeTab ?? 'profile';
$isAdmin = $isAdmin ?? (($_SESSION['user_group'] ?? '') === 'admin');
// в admin.php секций профиля нет, поэтому подсветки не будет
$activeSection = ($activeTab === 'profile') ? ($activeSection ?? 'card-info') : '';
$userName = $userProfile['user_name'] ?? ($_SESSION['user_name'] ?? '');
$userLogin = $userProfile['user_login'] ?? ($_SESSION['user_login'] ?? '');
$userInitial = mb_strtoupper(mb_substr((string) $userName, 0, 1, 'UTF-8'), 'UTF-8');

// 3.7-g-2: дашборд убран из $adminTabs, он лежит рядом с аккордеоном
// отдельным пунктом. Иначе на дашборде подсвечивалось бы и «Панель
// управления» в summary, и «Дашборд» в подпунктах.
$adminTabs = ['users', 'orders', 'components'];
$isAdminSection = in_array($activeTab, $adminTabs, true);
?>
                <aside class="profile-sidebar">
                    <div class="profile-user">
                        <div class="profile-avatar"><?= escape($userInitial) ?></div>
                        <div>
                            <div class="profile-user-name"><?= escape((string) $userName) ?></div>
                            <div class="profile-user-login"><?= escape((string) $userLogin) ?></div>
                        </div>
                    </div>

                    <nav class="profile-nav">
                        <a href="/profile.php" class="profile-nav-item<?= $activeSection === 'card-info' ? ' active' : '' ?>">Личная информация</a>

<?php if (!$isAdmin): ?>
                        <!-- 3.7-f-4-1: для обычного пользователя это вкладки
                             внутри profile.php, поэтому остаются кнопками -->
                        <button type="button" class="profile-nav-item<?= $activeSection === 'card-builds' ? ' active' : '' ?>" data-action="switch" data-target="card-builds">Мои заказы</button>
                        <button type="button" class="profile-nav-item<?= $activeSection === 'card-fav' ? ' active' : '' ?>" data-action="switch" data-target="card-fav">Избранное</button>
<?php else: ?>
                        <!-- 3.7-g-2: дашборд вынесен из аккордеона отдельным
                             пунктом - это точка входа, а не раздел -->
                        <a href="/admin.php?tab=dashboard" class="profile-nav-item<?= $activeTab === 'dashboard' ? ' active' : '' ?>">Дашборд</a>
                        <details class="profile-nav-group"<?= $isAdminSection ? ' open' : '' ?>>
                            <summary class="profile-nav-item<?= $isAdminSection ? ' active' : '' ?>">Панель управления</summary>
                            <div class="profile-nav-sub">
<?php
    $adminLinks = [
        'users' => ['/admin.php?tab=users', 'Пользователи'],
        'orders' => ['/admin.php?tab=orders', 'Заказы'],
        'components' => ['/admin.php?tab=components', 'Комплектующие'],
    ];
    foreach ($adminLinks as $key => [$href, $label]):
?>
                                <a href="<?= escape($href) ?>" class="profile-nav-subitem<?= $activeTab === $key ? ' active' : '' ?>"><?= escape($label) ?></a>
<?php endforeach; ?>
                            </div>
                        </details>

                        <!--
                            5-f-2b: «Настройки сайта» вынесено из аккордеона
                            отдельным пунктом, по соседству с «Дашбордом». Раздел
                            не про заказы и комплектующие, а держать его среди
                            них было вдвое неудобнее: он ещё и закрывался вместе
                            с ними, то есть после перехода в него аккордеон
                            выглядел свёрнутым, а раздел открытым.
                            По той же причине ключа settings нет в $adminTabs -
                            иначе заголовок «Панель управления» подсвечивался бы
                            на этой странице.
                        -->
                        <a href="/admin.php?tab=settings" class="profile-nav-item<?= $activeTab === 'settings' ? ' active' : '' ?>">Настройки сайта</a>
<?php endif; ?>

                        <!-- 3.7-g-4: был ссылкой, ушла сразу. Теперь кнопка: выход требует
                             подтверждения через общую #confirmModal -->
                        <button type="button" class="profile-nav-item profile-nav-exit" data-action="logout-confirm">Выйти</button>
                    </nav>
                </aside>