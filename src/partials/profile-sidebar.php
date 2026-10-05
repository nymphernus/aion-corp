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
                        <!-- 5-f-2c-1: пункты секций профиля ведут обычными
                             ссылками с ?section=, а не кнопками с JS.

                             Кнопка работала только там, где секция уже
                             отрисована на странице. В админке этого не
                             происходит: admin.php тянет тот же сайдбар, но
                             карточек профиля там нет вовсе, поэтому клик по
                             «Безопасность» на дашборде прятал секции (их
                             ноль), ничего не показывал и оставался на
                             /admin.php?tab=dashboard. Проверено в браузере до
                             правки.

                             Ссылка с ?section= работает отовсюду: с
                             профиля, из админки, по прямому адресу, по
                             перезагрузке и по кнопке «назад».

                             Замечание о форме этого комментария. Закрывать
                             HTML-комментарий можно только двумя дефисами и
                             угловой скобкой, а писать их внутри текста
                             нельзя: первый же такой набор закрыл бы
                             комментарий прямо здесь.

                             Раньше он был закрыт приёмом из CSS, двумя
                             символами «слэш-звёздочка». Для HTML это не
                             закрытие: парсер искал следующее настоящее и
                             съедал всё до него. Внутри комментария
                             оказывались обе ссылки ниже и комментарий про
                             дашборд, то есть в браузере пунктов «Личная
                             информация» и «Безопасность» не было вовсе,
                             хотя в исходнике и в ответе сервера они были.

                             Проверки по сырому HTML это пропускали, нашёл
                             браузер. Тесты на меню теперь разбирают
                             страницу через DOMDocument, где текст внутри
                             комментария не является элементом. -->
                        <a href="/profile.php?section=info" class="profile-nav-item<?= $activeSection === 'card-info' ? ' active' : '' ?>">Личная информация</a>
                        <a href="/profile.php?section=security" class="profile-nav-item<?= $activeSection === 'card-security' ? ' active' : '' ?>">Безопасность</a>
                        <!-- 8: «Избранное» у обеих ролей, и это ссылка, а не кнопка.
                             Раньше пункт был кнопкой внутри if (!$isAdmin): карточка
                             card-fav в админке не отрисовывалась вовсе, так что
                             кнопка на странице без цели прятала бы секции, и это
                             ровно тот баг, ради которого пункты уже переводили на
                             ссылки (комментарий 5-f-2c-1 выше). У админа кардочка
                             есть: он сохраняет сборки кнопкой «Сохранить», и без
                             вкладки удалять их было нечем.

                             «Мои заказы» админу не добавляется: заказы удаляются
                             через панель управления. -->
                        <a href="/profile.php?section=fav" class="profile-nav-item<?= $activeSection === 'card-fav' ? ' active' : '' ?>">Избранное</a>

<?php if (!$isAdmin): ?>
                        <!-- 3.7-f-4-1: заказы остались кнопками: они есть
                              только у обычного пользователя и только на
                              profile.php, где JS-переключение работает -->
                        <button type="button" class="profile-nav-item<?= $activeSection === 'card-builds' ? ' active' : '' ?>" data-action="switch" data-target="card-builds">Мои заказы</button>
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
        'files' => ['/admin.php?tab=files', 'Изображения'],
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