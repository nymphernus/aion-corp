<?php
/**
 * Вкладка «Настройки сайта» (5-f-2)
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
?>
                <section class="card">
                    <h2>Контакты</h2>
                    <p class="form-hint">
                        Эти значения подставляются в блок «Свяжитесь с нами»
                        на главной. Пустую ссылку в соцсеть иконка не показывает.
                    </p>

                    <form method="post" action="/admin.php?tab=settings">
                        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">

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

                        <div class="modal-row">
                            <div class="form-group">
                                <label class="form-label" for="setVk">VK</label>
                                <input class="input" type="url" name="contact_vk" id="setVk"
                                       value="<?= escape(site_setting($settings, 'contact_vk')) ?>"
                                       placeholder="https://vk.com/aioncorp">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="setTelegram">Telegram</label>
                                <input class="input" type="url" name="contact_telegram" id="setTelegram"
                                       value="<?= escape(site_setting($settings, 'contact_telegram')) ?>"
                                       placeholder="https://t.me/aioncorp">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="setWhatsapp">WhatsApp</label>
                            <input class="input" type="url" name="contact_whatsapp" id="setWhatsapp"
                                   value="<?= escape(site_setting($settings, 'contact_whatsapp')) ?>"
                                   placeholder="https://wa.me/79999999999">
                            <p class="form-hint">
                                Ссылка должна начинаться с http:// или https:// — иначе она не сохранится.
                            </p>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="setAddress">Адрес (текстом)</label>
                            <input class="input" name="map_address_text" id="setAddress"
                                   value="<?= escape(site_setting($settings, 'map_address_text')) ?>"
                                   placeholder="Москва, ул. Победы, д. 15">
                        </div>

                        <div class="modal-actions"><div class="modal-actions-right">
                            <button type="submit" name="saveSettings" class="btn btn--primary">Сохранить контакты</button>
                        </div>
                    </form>
                </section>

                <section class="card">
                    <h2>Карта</h2>
                    <p class="form-hint">
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
                </section>

                <!--
                    5-f-2: модалка обновления карты. Геокодирование идёт через
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
