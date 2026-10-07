<?php
/**
 * Вкладка «Настройки сайта» 
 *
 * Подключается из admin.php, который уже положил в $settings массив
 * site_settings(). Ожидает:
 *   $settings — array<string, string>
 *   $extraJs  — добавляется список скриптов ниже
 */

// Подключение Leaflet и html2canvas — в admin.php до require header.php,
// здесь добавлять нечего: к моменту include этого файла <head> уже выведен.
// Проверено по порядку строк в admin.php, сначала $extraJs, потом header.php.

// Ожидается:
//   $settings — array<string, string>
//   $snapshotUrl — вычисляется ниже из $settings

$snapshotUrl = site_setting($settings, 'map_snapshot_url');

// Ошибки сохранения приходят в адрес (?bad=...), потому что обработчик
// перезагружает страницу редиректом: та же форма не может одновременно
// принять POST и отрисоваться с результатом. Коды переводятся здесь -
// в адресе должен лежать текст, а не имя внутреннего кода.
$badCodes = [
    'branding_size' => 'Файл больше 2 МБ.',
    'branding_mime' => 'Неподдерживаемый формат: нужен PNG, JPG или WebP.',
    'branding_image' => 'Файл не читается как изображение.',
    'branding_dir' => 'Не удалось создать каталог для файлов бренда.',
    'favicon_generate' => 'Не удалось создать favicon.',
    'favicon_letter_empty' => 'Укажите букву для иконки.',
    'favicon_letter_unsupported' => 'Генератор рисует одну букву. Для этого варианта загрузите свою картинку.',
    'social_name' => 'Укажите название соцсети.',
    'social_name_long' => 'Название длиннее 50 символов.',
    'social_url' => 'Ссылка должна начинаться с http:// или https://',
    'social_url_long' => 'Ссылка длиннее 255 символов.',
    'social_icon' => 'Выберите иконку или загрузите свою.',
    'social_icon_upload' => 'Иконка не загрузилась. Нужен SVG, PNG или WebP до 500 КБ.',
    'social_icon_unsafe' => 'Иконка не распознана: выберите из списка или загрузите свою.',
    'social_not_found' => 'Такой соцсети больше нет.',
];
$badMessages = [];
foreach (explode(', ', (string) ($_GET['bad'] ?? '')) as $code) {
    $code = trim($code);
    if ($code !== '') {
        $badMessages[] = $badCodes[$code] ?? $code;
    }
}

// Соцсети и набор иконок.
//
// Строки читаются здесь, а не в admin.php: вкладка настроек и так
// разбирает на себе и форму, и модалки, а выборка одна и короткая.
// Порядок вывода - по добавлению. Поле sort_order в таблице осталось
// как резерв, но в форме его больше нет: порядок вручную не задают.
$socialLinks = [];
$stmt = db_prepare(
    $mysql,
    'SELECT link_id, link_name, link_url, link_icon, is_active
       FROM social_links
      ORDER BY link_id ASC',
    ''
);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $socialLinks[] = $row;
}
$result->free();

// Иконки берутся из каталога, а не из списка в коде. Причина
// практическая: чтобы добавить площадку, достаточно положить svg в
// assets/images/social/, и она сразу появится в списке. Список в коде
// пришлось бы править на каждую новую иконку, и через год он разошёлся
// бы с каталогом.
$socialIconDir = __DIR__ . '/../assets/images/social/';

// Подписи. Слаг файла не всегда читается: ok - это «OK», а не «Ok».
// Нет ключа - берётся ucfirst от имени файла, поэтому иконка вне
// словаря всё равно будет подписана и выбрана.
$socialIconLabels = [
    'vk' => 'VK',
    'telegram' => 'Telegram',
    'whatsapp' => 'WhatsApp',
    'youtube' => 'YouTube',
    'ok' => 'Одноклассники',
    'viber' => 'Viber',
];

$socialIcons = [];
if (is_dir($socialIconDir)) {
    $found = scandir($socialIconDir);
    if ($found !== false) {
        foreach ($found as $file) {
            if (!str_ends_with($file, '.svg')) {
                continue;
            }
            $slug = pathinfo($file, PATHINFO_FILENAME);
            $socialIcons[] = [
                'path' => '/assets/images/social/' . $file,
                'label' => $socialIconLabels[$slug] ?? ucfirst($slug),
            ];
        }
    }
    // Сортировка по подписи, а не по файлу: иначе ok встал бы между
    // odnoklassniki и telegram, и список выглядел бы случайным.
    usort($socialIcons, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));
}
?>
                <?php // Контакты и брендинг - две отдельные карточки внутри одной
                      // формы. Форма вынесена выше обеих: внутри карточки
                      // контактов брендинг читался как вложенный блок без
                      // границы.

                      // enctype обязателен: в этой же форме загружаются
                      // файлы бренда (логотип, favicon). Без него браузер
                      // отправит только имена файлов, а $_FILES будет
                      // пустым - и загрузка молча не сработала бы. ?>
                <form method="post" action="/admin.php?tab=settings"
                      enctype="multipart/form-data">

                    <article class="settings-block">
                        <h2 class="settings-block__title">Контакты</h2>
                        <p class="settings-block__hint">
                            Эти значения подставляются в блок «Свяжитесь с нами»
                            на главной. Пустую ссылку в соцсеть иконка не показывает.
                        </p>

                        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">

<?php if ($badMessages !== []): ?>
                        <div class="alert alert--error" role="alert">
<?php foreach ($badMessages as $badMessage): ?>
                            <div><?= escape($badMessage) ?></div>
<?php endforeach; ?>
                        </div>
<?php endif; ?>

                        <div class="modal-row">
                            <div class="form-group">
                                <label class="form-label" for="setPhone">Телефон</label>
                                <input class="input" type="tel" name="contact_phone" id="setPhone"
                                       value="<?= escape(site_setting($settings, 'contact_phone')) ?>"
                                       placeholder="+7 (999) 999-99-99">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="setEmail">Email</label>
                                <input class="input" type="email" name="contact_email" id="setEmail"
                                       value="<?= escape(site_setting($settings, 'contact_email')) ?>"
                                       placeholder="mail@mail.ru">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="setAddress">Адрес (текстом)</label>
                            <input class="input" name="map_address_text" id="setAddress"
                                   value="<?= escape(site_setting($settings, 'map_address_text')) ?>"
                                   placeholder="Москва, ул. Победы, д. 15">
                        </div>

                        <!--
                            Соцсети - подраздел внутри «Контактов»,
                            а не отдельная карточка. Раньше одна и та же
                            ссылка показывалась в двух местах сразу: в
                            поле «VK» здесь и в таблице ниже. Теперь у
                            контактов один список, и телефон, почта и адрес
                            видны в нём же.

                            Кнопка объявлена type="button" и не отправляет
                            форму настроек: соцсети сохраняются отдельной
                            формой в модалке, иначе правка телефона
                            затирала бы иконки.
                        -->
                        <div class="settings-subsection">
                            <div class="settings-subsection__header">
                                <h3 class="settings-subsection__title">Соцсети</h3>
                                <button type="button" class="btn btn--primary btn--sm"
                                        data-action="add-social">
                                    + Добавить соцсеть
                                </button>
                            </div>
                            <p class="settings-subsection__hint">
                                Показываются в блоке «Свяжитесь с нами» на главной,
                                в порядке поля «Порядок». С пустой ссылкой не
                                выводятся, выключенные скрываются, но остаются здесь.
                            </p>
    <?php if ($socialLinks): ?>
                        <div class="table-wrap">
                            <table class="table social-table">
                                <thead>
                                    <tr>
                                        <th class="social-table__icon" title="Иконка"></th>
                                        <th>Название</th>
                                        <th class="social-table__url">Ссылка</th>
                                        <th class="social-table__actions"></th>
                                    </tr>
                                </thead>
                                <tbody>
    <?php foreach ($socialLinks as $social): ?>
                                    <tr data-link-id="<?= (int) $social['link_id'] ?>"
                                        data-link-url="<?= escape((string) $social['link_url']) ?>">
                                        <td>
                                            <img class="social-row__icon"
                                                 src="<?= escape(asset_url((string) $social['link_icon'])) ?>"
                                                 alt="" width="24" height="24">
                                        </td>
                                        <td>
                                            <span class="social-row__name" title="<?= escape((string) $social['link_name']) ?>"><?= escape((string) $social['link_name']) ?></span>
    <?php // Бейдж стоит рядом с названием, а не своей колонкой: карточка
         // настроек шириной 413px, шесть колонок в неё не помещались -
         // таблица выходила на 783px и кнопки правки уезжали за край.
         if ((int) $social['is_active'] === 1): ?>
                                            <span class="badge badge--success">показ</span>
    <?php else: ?>
                                            <span class="badge">скрыта</span>
    <?php endif; ?>
                                        </td>
                                        <td class="social-table__url">
    <?php if ((string) $social['link_url'] !== ''): ?>
                                            <a href="<?= escape((string) $social['link_url']) ?>"
                                               target="_blank" rel="noopener noreferrer"
                                               class="link-muted"
                                               title="<?= escape((string) $social['link_url']) ?>"><?= escape((string) $social['link_url']) ?></a>
    <?php else: ?>
                                            <span class="settings-list__hint">не задана</span>
    <?php endif; ?>
                                        </td>
                                        <td class="social-table__actions">
                                            <button type="button" class="btn-icon btn-icon--muted"
                                                    data-action="edit-social"
                                                    data-link-id="<?= (int) $social['link_id'] ?>"
                                                    title="Редактировать"
                                                    aria-label="Редактировать <?= escape((string) $social['link_name']) ?>">
                                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 000-1.41l-2.34-2.34a1 1 0 00-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                            </button>
                                            <button type="button" class="btn-icon btn-icon--danger"
                                                    data-action="delete-social"
                                                    data-link-id="<?= (int) $social['link_id'] ?>"
                                                    data-link-name="<?= escape((string) $social['link_name']) ?>"
                                                    title="Удалить"
                                                    aria-label="Удалить <?= escape((string) $social['link_name']) ?>">
                                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 19a2 2 0 002 2h8a2 2 0 002-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                            </button>
                                        </td>
                                    </tr>
    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
    <?php else: ?>
                        <p class="settings-list__hint">Пока ни одной соцсети не заведено.</p>
    <?php endif; ?>
                        </div>

                        <!--
                            брендинг. Карточка внутри этой же формы,
                            а не отдельная вкладка: всё под одним «Сохранить».
                            Скрытые site_*_url нужны, когда картинку не
                            загружают заново - тогда в POST уходит прежнее
                            значение, и настройка не сбрасывается.
                        -->
                    </article>

                    <article class="settings-block">
                        <h2 class="settings-block__title">Брендинг</h2>
                        <p class="settings-block__hint">
                            Название и картинки сайта. Применяются на всех
                            страницах: в заголовке вкладки, шапке, подвале
                            и на главной.
                        </p>

                            <div class="modal-row">
                                <div class="form-group">
                                    <label class="form-label" for="setSiteName">Название сайта</label>
                                    <input class="input" name="site_name" id="setSiteName"
                                           maxlength="100"
                                           value="<?= escape(site_setting($settings, 'site_name')) ?>"
                                           placeholder="Aion Corporation">
                                    <p class="form-hint">Отображается в шапке, подвале, заголовке вкладки и на главной</p>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="setHomeTitle">Заголовок главной страницы</label>
                                <input class="input" type="text" name="site_home_title" id="setHomeTitle"
                                       maxlength="200"
                                       value="<?= escape(site_setting($settings, 'site_home_title', 'Интернет-магазин персональных компьютеров индивидуальной комплектации')) ?>">
                                <p class="form-hint">Заголовок вкладки браузера на главной. К нему автоматически добавляется «Название сайта» — здесь его писать не нужно, иначе при переименовании останется старое</p>
                            </div>

                            <div class="modal-row">
                                <div class="form-group">
                                    <label class="form-label" for="setSiteDescription">Описание</label>
                                    <textarea class="input" name="site_description" id="setSiteDescription"
                                              rows="3" maxlength="300"><?= escape(site_setting($settings, 'site_description')) ?></textarea>
                                    <p class="form-hint">Подпись под названием на главной и meta-описание для поисковиков</p>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="setFoundedYear">Год основания</label>
                                    <input class="input" name="site_founded_year" id="setFoundedYear"
                                           maxlength="4" inputmode="numeric" pattern="\d{4}"
                                           value="<?= escape(site_setting($settings, 'site_founded_year', '2022')) ?>">
                                    <p class="form-hint">Отображается в подвале как «© 2022 Название».</p>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Логотип</label>
                                <div class="branding-preview">
                                    <img src="<?= escape(site_setting($settings, 'site_logo_url', '/assets/images/logo.png')) ?>"
                                         alt="Логотип" id="logoPreview">
                                </div>
                                <input type="hidden" name="site_logo_url"
                                       value="<?= escape(site_setting($settings, 'site_logo_url')) ?>">
                                <label class="btn btn--secondary btn--sm">
                                    <input type="file" id="brandingLogoInput"
                                           name="branding_logo"
                                           accept="image/png,image/jpeg,image/webp,image/gif"
                                           style="display:none">
                                    Загрузить новый логотип
                                </label>
                                <p class="form-hint">PNG, JPG, WebP до 2 МБ. Ширина уменьшается до 400px</p>
                            </div>

                        <div class="form-group">
                            <label class="form-label" for="faviconLetter">Favicon</label>

                            <div class="favicon-editor">
                                <div class="favicon-editor__preview">
                                    <img src="<?= escape(site_setting($settings, 'site_favicon_png_url', '/assets/images/branding/favicon.png')) ?>"
                                         alt="Favicon" id="faviconPreview"
                                         width="64" height="64" class="favicon-preview">
                                </div>

                                <div class="favicon-editor__controls">

                                    <div class="favicon-section">
                                        <div class="favicon-section__title">Содержимое</div>
                                        <div class="favicon-section__row">
                                            <div class="favicon-field">
                                                <label class="favicon-field__label" for="faviconLetter">Символ</label>
                                                <input class="input input--compact" name="favicon_letter" id="faviconLetter"
                                                       maxlength="1" inputmode="text" autocomplete="off"
                                                       value="<?= escape(site_setting($settings, 'favicon_letter', 'A')) ?>"
                                                       placeholder="A">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="favicon-section">
                                        <div class="favicon-section__title">Цвета</div>
                                        <div class="favicon-section__row">
                                            <div class="favicon-field">
                                                <label class="favicon-field__label" for="faviconBg">Фона</label>
                                                <input type="color" name="favicon_bg" id="faviconBg"
                                                       value="<?= escape(site_setting($settings, 'favicon_bg', '#C99CFF')) ?>"
                                                       class="favicon-color">
                                            </div>

                                            <div class="favicon-field">
                                                <label class="favicon-field__label" for="faviconText">Буквы</label>
                                                <input type="color" name="favicon_text" id="faviconText"
                                                       value="<?= escape(site_setting($settings, 'favicon_text', '#000000')) ?>"
                                                       class="favicon-color">
                                            </div>

                                            <div class="favicon-field favicon-field--check">
                                                <label class="favicon-auto">
                                                    <input type="checkbox" name="favicon_auto_color"
                                                           id="faviconAutoColor" value="1"
                                                           <?= site_setting($settings, 'favicon_auto_color') === '1' ? 'checked' : '' ?>>
                                                    <span>Авто по контрасту</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="favicon-section favicon-section--actions">
                                        <button type="button" class="btn btn--primary btn--sm" id="faviconGenerate"
                                                data-action="generate-favicon">Сгенерировать</button>
                                        <label class="btn btn--secondary btn--sm">
                                            <input type="file" id="faviconUploadInput"
                                                   name="favicon_upload"
                                                   accept="image/png,image/jpeg,image/webp"
                                                   style="display:none">
                                            Загрузить свою
                                        </label>
<?php // Обе кнопки условны по файлу на диске, а не только по флагу:
      // варианта может не быть (база обновлена поверх старой установки,
      // где файлов не было), и тогда кнопка обещала бы действие, которое
      // делать не над чем. Недостающее доберётся из активной иконки.
      //
      // Пока активна загруженная картинка, обычное «Сохранить» её не
      // трогает - иначе правка телефона в соседней карточке тихо
      // стирала бы картинку. Переключение вариантов только здесь. ?>
<?php $faviconIsCustom = site_setting($settings, 'favicon_is_custom', '0') === '1'; ?>
<?php // Проверка через помощники модуля, а не путём от __DIR__: файл лежит в
      // src/admin/, и __DIR__ . '/../../assets' указал бы выше веб-рута.
      if ($faviconIsCustom && favicon_has_generated()): ?>
                                        <button type="button" class="btn btn--secondary btn--sm favicon-switch"
                                                data-action="use-favicon-variant" data-variant="generated"
                                                title="Вернуть сгенерированную иконку. Загруженная сохранится">
                                            Сгенерированная
                                        </button>
<?php endif; ?>
<?php if (!$faviconIsCustom && favicon_has_custom()): ?>
                                        <button type="button" class="btn btn--secondary btn--sm favicon-switch"
                                                data-action="use-favicon-variant" data-variant="custom"
                                                title="Вернуть загруженную иконку. Сгенерированная сохранится">
                                            Загруженная
                                        </button>
<?php endif; ?>
                                    </div>
                                </div>
                                </div>

<?php if ($faviconIsCustom): ?>
                            <p class="form-hint">
                                Сейчас иконка загруженная: буква и цвета её не
                                заменяют, пока не выбран другой вариант.
                            </p>
<?php endif; ?>

                            <p class="form-hint">
                                Символ занимает 70% высоты, иконка 128×128 PNG. «Авто по контрасту»
                                подбирает цвет буквы под фон. Загруженная иконка важнее
                                сгенерированной.
                            </p>
                            <p class="form-hint" id="faviconNotice"></p>

                            <input type="hidden" name="site_favicon_png_url" id="faviconUrlInput"
                                   value="<?= escape(site_setting($settings, 'site_favicon_png_url')) ?>">
                    </article>

                    <div class="modal-actions"><div class="modal-actions-right">
                        <button type="submit" name="saveSettings" class="btn btn--primary">Сохранить</button>
                    </div></div>
                </form>

                <article class="settings-block">
                    <h2 class="settings-block__title">Карта</h2>
                    <p class="settings-block__hint">
                        На главной карта показывается снимком — одним файлом,
                        без единого запроса к внешним сервисам. Снимок делается
                        один раз здесь и больше не обновляется сам.
                    </p>

                    <dl class="settings-list">
                        <dt>Координаты</dt>
                        <dd>
                            <?= escape(site_setting($settings, 'map_lat', '—')) ?>,
                            <?= escape(site_setting($settings, 'map_lng', '—')) ?>
                            <span class="settings-list__hint">масштаб <?= escape(site_setting($settings, 'map_zoom', '—')) ?></span>
                        </dd>
                        <dt>Адрес</dt>
                        <dd><?= escape(site_setting($settings, 'map_address_text', 'не задан')) ?></dd>
                        <dt>Снимок</dt>
                        <dd><?= $snapshotUrl !== '' ? 'сохранён' : 'ещё не сделан' ?></dd>
                    </dl>

<?php if ($snapshotUrl !== ''): ?>
                    <div class="form-group">
                        <label class="form-label">Текущий снимок</label>
                        <img class="settings-snapshot"
                             src="<?= escape($snapshotUrl) ?>"
                             alt="Снимок карты" loading="lazy">
                    </div>
<?php endif; ?>

                    <div class="modal-actions"><div class="modal-actions-right">
                        <button type="button" class="btn btn--primary"
                            data-action="open-modal" data-modal="mapSnapshotModal">
                        Обновить карту
                    </button>
                </div>
                </article>

                <!--
                    Модалка соцсети и форма удаления остались здесь,
                    после карточки «Карта», а не рядом с таблицей.

                    Таблица теперь стоит внутри формы настроек, а обе
                    эти части содержат <form>. Вложенная форма в HTML
                    недопустима, и вынести их в форму контактов было бы
                    нельзя: закрывать форму настроек посреди брендинга
                    невозможно. Кнопка в таблице объявлена type="button",
                    поэтому отправки формы настроек не происходит.
                -->

                <!--
                    модалка обновления карты. Геокодирование идёт через
                    Nominatim прямо из браузера админа, снимок делает
                    html2canvas и отправляет на сервер одним POST.
                -->
                <dialog id="mapSnapshotModal" class="modal modal--wide">
                    <div class="modal-form">
                        <h2>Обновить карту</h2>

                        <div class="form-group">
                            <label class="form-label" for="mapAddressInput">Адрес</label>
                            <div class="form-inline">
                                <input class="input" id="mapAddressInput" type="text"
                                       value="<?= escape(site_setting($settings, 'map_address_text')) ?>"
                                       placeholder="Москва, Красная площадь, 1">
                                <button type="button" class="btn btn--secondary" data-action="geocode-address">
                                    Найти
                                </button>
                            </div>
                            <p class="form-hint" id="geocodeStatus" role="status"></p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Проверьте положение</label>
                            <div id="mapPreview" class="map-preview"></div>
                            <p class="form-hint">
                                Запрос к Nominatim уйдёт из браузера. Это публичный
                                сервис OpenStreetMap, он не требует ключа, но и не
                                создан для потока: одного запроса при нажатии «Найти»
                                достаточно, автоматической отправки адресов нет.
                            </p>
                        </div>

                        <div class="modal-actions">
                            <div class="modal-actions-right">
                                <button type="button" class="btn btn--secondary" data-action="close-modal">Отмена</button>
                                <button type="button" class="btn btn--primary" data-action="save-map-snapshot">
                                    Сохранить снимок
                                </button>
                            </div>
                        </div>
                    </div>
                </dialog>

                <!--
                    Модалка соцсети. Своя форма с полем socialAction:
                    отдельная от формы настроек, поэтому её нельзя было бы
                    положить внутрь той. enctype обязателен из-за загрузки
                    своей иконки.

                    Значения полей заполняет JS из строки таблицы, а при
                    добавлении очищает. Сервер при этом ничего не берёт
                    из атрибутов: всё приходит полями POST.
                -->
                <dialog id="socialModal" class="modal">
                    <form method="post" class="modal-form"
                          action="/admin.php?tab=settings"
                          enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                        <input type="hidden" name="socialAction" value="save">
                        <input type="hidden" name="linkId" id="socialLinkId" value="0">

                        <h2 id="socialModalTitle">Добавить соцсеть</h2>

                        <div class="form-group">
                            <label class="form-label" for="socialName">Название</label>
                            <input class="input" type="text" name="link_name" id="socialName"
                                   maxlength="50" placeholder="ВКонтакте">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="socialUrl">Ссылка</label>
                            <input class="input" type="url" name="link_url" id="socialUrl"
                                   maxlength="255" placeholder="https://vk.com/aioncorp">
                            <p class="form-hint">Должна начинаться с http:// или https://</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Иконка</label>
                            <div class="social-icon-picker">
<?php foreach ($socialIcons as $icon): ?>
                                <label class="social-icon-option">
                                    <input type="radio" name="link_icon"
                                           value="<?= escape($icon['path']) ?>">
                                    <span class="social-icon-option__box">
                                        <img src="<?= escape(asset_url($icon['path'])) ?>" alt="">
                                        <span><?= escape($icon['label']) ?></span>
                                    </span>
                                </label>
<?php endforeach; ?>
                            </div>
                            <p class="form-hint">
                                Свой вариант (SVG, PNG или WebP до 500 КБ) перекрывает
                                выбор сверху: если файл выбран, он и сохранится.
                            </p>
                            <label class="btn btn--secondary btn--sm">
                                <input type="file" name="link_icon_upload"
                                       accept="image/svg+xml,image/png,image/webp"
                                       style="display:none">
                                Загрузить свою
                            </label>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Показ</label>
                            <label class="checkbox-label">
                                <input type="checkbox" name="is_active"
                                       id="socialActive" value="1" checked>
                                Показывать на главной
                            </label>
                        </div>

                        <div class="modal-actions"><div class="modal-actions-right">
                            <button type="button" class="btn btn--secondary" data-action="close-modal">Отмена</button>
                            <button type="submit" class="btn btn--primary">Сохранить</button>
                        </div></div>
                    </form>
                </dialog>

                <!--
                    Удаление отдельной формой. Кнопка «удалить» в строке
                    таблицы не может сама быть submit - её форма находится
                    в модалке. Скрытая форма с тремя полями - самый
                    короткий путь без обработчика на каждый <form>.
                -->
                <form id="deleteSocialForm" method="post" action="/admin.php?tab=settings"
                      class="hidden-form">
                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                    <input type="hidden" name="socialAction" value="delete">
                    <input type="hidden" name="linkId" value="0">
                </form>
