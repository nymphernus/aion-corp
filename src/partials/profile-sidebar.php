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

$adminTabs = ['dashboard', 'users', 'orders', 'components'];
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
                        <details class="profile-nav-group"<?= $isAdminSection ? ' open' : '' ?>>
                            <summary class="profile-nav-item<?= $isAdminSection ? ' active' : '' ?>">Панель управления</summary>
                            <div class="profile-nav-sub">
<?php
    $adminLinks = [
        // 3.7-g: дашборд первым - с него начинают работу в панели
        'dashboard' => ['/admin.php?tab=dashboard', 'Дашборд'],
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
<?php endif; ?>

                        <a href="/validation/exit.php" class="profile-nav-item profile-nav-exit">Выйти</a>
                    </nav>
                </aside>