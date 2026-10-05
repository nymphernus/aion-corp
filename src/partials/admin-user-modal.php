<?php
/**
 * Модалки пользователя и его удаления (Stage 3.7-f-4-2).
 *
 * Подключаются из admin.php, а не из _tab_users.php: переход
 * «заказ -> покупатель» открывает editUserModal прямо на вкладке
 * заказов, где вкладка users не отрисована.
 *
 * Отдельного ADMIN_CONTEXT не требуют — разметка не зависит от данных
 * вкладки, всё подставляет JS.
 */
?>
                <!--
                    3.7-h-2: модалка пользователя — имя, группа, контакты, адрес.
                    Логин только для чтения. Отправляет name="editUser".
                -->
                <!-- 3.7-f-4c-2: modal--wide — широкий вариант, иначе данные
                     пользователя не помещались.
                     7: ширина поднята до 900px и раскладка перестроена в две
                     колонки. Раньше секции стояли по две в ряд сеткой на самой
                     форме, и «Верификация», попав в третий ряд, растягивала
                     высоту: измеренная высота формы была 1127px при высоте
                     диалога 675px, то есть блок верификации и кнопки формы
                     оказывались ниже сгиба и требовали прокрутки. -->
                <dialog id="editUserModal" class="modal modal--wide">
                    <form method="post" class="modal-form modal-form--user" action="/admin.php?tab=users">
                        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                        <input type="hidden" name="editUserId" id="editUserId" value="">

                        <h2>Пользователь: <span id="editUserLogin"></span></h2>

                        <!-- 7: две колонки вместо трёх рядов секций.

                             Раскладка не по высоте, а по смыслу: слева личные
                             данные (кто и где живёт), справа аккаунт и
                             верификация (права, контакты, подтверждение). Так
                             колонки примерно равны, и ни одна не требует
                             прокрутки.

                             align-items: start обязателен: без него колонки
                             растянутся до высоты самой длинной, и в короткой
                             осталась бы пустота под секциями. -->
                        <div class="user-modal-grid">

                            <!-- ЛЕВАЯ КОЛОНКА: личные данные -->
                            <div class="user-modal-col">
                                <div class="modal-section modal-section--cols">
                                    <h3>Основные данные</h3>
                                    <div class="form-group">
                                        <label class="form-label" for="editUserName">Имя</label>
                                        <input class="input" name="user_name" id="editUserName" maxlength="20" required>
                                    </div>
                                    <!-- 3.7-f-4c-3: maxlength 30 — в users это varchar(30),
                                         поле 50 в разметке пропустило бы слишком длинное
                                         значение в колонку -->
                                    <div class="form-group">
                                        <label class="form-label" for="editUserSurname">Фамилия</label>
                                        <input class="input" name="user_surname" id="editUserSurname" maxlength="30">
                                    </div>
                                    <!-- id остаётся editUserNameRO: пишет в него
                                         openUserModal в scripts.js. -->
                                    <div class="form-group">
                                        <label class="form-label" for="editUserNameRO">Логин (не изменяется)</label>
                                        <input class="input" id="editUserNameRO" disabled>
                                    </div>
                                    <!-- 3.7-g-6: дата регистрации только для чтения,
                                         без name, поэтому в POST не уходит -->
                                    <div class="form-group">
                                        <label class="form-label" for="editUserRegdate">Дата регистрации</label>
                                        <input class="input" id="editUserRegdate" disabled>
                                    </div>
                                </div>

                                <div class="modal-section modal-section--cols">
                                    <h3>Адрес</h3>
                                    <!--
                                        3.7-i-2: адрес разбит на поля (миграция 3.7-i-1).
                                        Город не required: колонка nullable, и у части
                                        пользователей адреса нет вовсе - required
                                        запрещал бы сохранить любое другое поле.
                                        Значения кладутся без маркеров («Победы», а не
                                        «ул. Победы»), подпись поля их поясняет.

                                        modal-section--cols раскладывает поля внутри
                                        секции в две колонки. Это сетка уровня поля,
                                        а не секции, поэтому с новой раскладкой модалки
                                        она не конфликтует и наоборот убирает шесть
                                        строк высоты из левой колонки.
                                    -->
                                    <div class="form-group">
                                        <label class="form-label" for="editUserPostalCode">Индекс</label>
                                        <input class="input" name="user_postal_code" id="editUserPostalCode"
                                               maxlength="10" placeholder="101000" inputmode="numeric">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="editUserRegion">Регион</label>
                                        <input class="input" name="user_region" id="editUserRegion"
                                               maxlength="100" placeholder="Московская область">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="editUserCity">Город</label>
                                        <input class="input" name="user_city" id="editUserCity"
                                               maxlength="100" placeholder="Москва">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="editUserStreet">Улица</label>
                                        <input class="input" name="user_street" id="editUserStreet"
                                               maxlength="150" placeholder="ул. Победы">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="editUserHouse">Дом</label>
                                        <input class="input" name="user_house" id="editUserHouse"
                                               maxlength="20" placeholder="15">
                                    </div>
                                    <!-- FIX-2: показывать legacy-строку user_address
                                         больше нечем - поле удалено целиком. В базе
                                         колонка остаётся, но UI её не читает. -->
                                </div>
                            </div>

                            <!-- ПРАВАЯ КОЛОНКА: аккаунт и верификация -->
                            <div class="user-modal-col">
                                <div class="modal-section">
                                    <h3>Права</h3>
                                    <div class="form-group">
                                        <label class="form-label" for="editUserGroup">Группа</label>
                                        <select class="input" name="user_group" id="editUserGroup">
                                            <option value="user">Пользователь</option>
                                            <option value="admin">Администратор</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="modal-section">
                                    <h3>Контакты</h3>
                                    <div class="form-group">
                                        <label class="form-label" for="editUserNumber">Телефон</label>
                                        <input class="input" name="user_number" id="editUserNumber" maxlength="13"
                                               pattern="\+7\s?[\(]{0,1}\d{3}[\)]{0,1}\s?\d{3}[\-]{0,1}\d{2}[\-]{0,1}\d{2}">
                                    </div>
                                </div>

                                <!-- 7: подтверждение контактов администратором.

                                     Секция стоит в правой колонке рядом с
                                     «Контактами», которые и подтверждает.
                                     Значения заполняет JS при открытии модалки из
                                     data-row; до первого клика тут стоят прочерки.

                                     Email в самой модалке редактировать нельзя: поля
                                     для него тут нет, есть только телефон, а блок
                                     верификации показывает его read-only. Раньше при
                                     переходе из модалки заказа email вообще не
                                     передавался, и поле оставалось пустым - ключ
                                     добавлен в dataset.user.

                                     Формы approveEmailForm и approvePhoneForm лежат
                                     после dialog, а не здесь: вложенных форм в HTML
                                     не бывает, а вокруг содержимого модалки уже
                                     стоит форма редактирования пользователя. -->
                                <div class="modal-section">
                                    <h3>Верификация</h3>
                                    <div class="verify-admin-row">
                                        <div class="verify-admin-info">
                                            <div class="form-label">Email</div>
                                            <div id="adminUserEmail" class="verify-admin-value">—</div>
                                        </div>
                                        <div class="verify-admin-actions">
                                            <span id="adminUserEmailStatus" class="badge"></span>
                                            <button type="button" class="btn-icon btn-icon--success"
                                                    data-action="approve-email" id="adminApproveEmailBtn"
                                                    title="Подтвердить email" aria-label="Подтвердить email" disabled>
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                                     stroke="currentColor" stroke-width="2.5"
                                                     stroke-linecap="round" stroke-linejoin="round"
                                                     aria-hidden="true" focusable="false">
                                                    <polyline points="20 6 9 17 4 12"></polyline>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="verify-admin-row">
                                        <div class="verify-admin-info">
                                            <div class="form-label">Телефон</div>
                                            <div id="adminUserPhone" class="verify-admin-value">—</div>
                                        </div>
                                        <div class="verify-admin-actions">
                                            <span id="adminUserPhoneStatus" class="badge"></span>
                                            <button type="button" class="btn-icon btn-icon--success"
                                                    data-action="approve-phone" id="adminApprovePhoneBtn"
                                                    title="Подтвердить телефон" aria-label="Подтвердить телефон" disabled>
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                                     stroke="currentColor" stroke-width="2.5"
                                                     stroke-linecap="round" stroke-linejoin="round"
                                                     aria-hidden="true" focusable="false">
                                                    <polyline points="20 6 9 17 4 12"></polyline>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="modal-actions">
                            <button type="button" class="btn btn--danger" id="editUserDeleteBtn" data-action="open-delete-user-modal">Удалить</button>
                            <div class="modal-actions-right">
                                <button type="button" class="btn btn--secondary" data-action="close-modal">Отмена</button>
                                <button type="submit" name="editUser" class="btn btn--primary">Сохранить</button>
                            </div>
                        </div>
                    </form>
                </dialog>
                <!-- 3.7-g-4: подтверждение удаления показывает общая #confirmModal
                     из partials/header.php, поэтому отдельная модалка
                     удалена. Осталась форма, которую отправляет
                     confirmAction. -->
                <form id="deleteUserForm" method="post" action="/admin.php?tab=users" hidden>
                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                    <input type="hidden" name="userId" id="deleteUserId" value="">
                    <!-- 3.7-g-4: скрытый input вместо submit-кнопки,
                         потому что форму отправляет form.submit() -->
                    <input type="hidden" name="deleteUser" value="1">
                </form>

                <!-- 7: формы подтверждения верификации.

                     Отдельные формы, а не кнопки внутри формы модалки:
                     вложенных форм в HTML не бывает, а вокруг всего
                     содержимого editUserModal уже стоит форма
                     редактирования пользователя с name="editUser".
                     Отправляются из JS через form.submit() - поэтому
                     скрытый input вместо submit-кнопки, как у
                     deleteUserForm.

                     action указан явно: браузер по умолчанию отправил бы
                     POST на текущий адрес, а это /admin.php?tab=users
                     только по счастливому совпадению - при переходе из
                     закладки другого адреса обработчик не нашёлся бы. -->
                <form id="approveEmailForm" method="post" action="/admin.php?tab=users" hidden>
                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                    <input type="hidden" name="userId" value="">
                    <input type="hidden" name="approveEmail" value="1">
                </form>
                <form id="approvePhoneForm" method="post" action="/admin.php?tab=users" hidden>
                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                    <input type="hidden" name="userId" value="">
                    <input type="hidden" name="approvePhone" value="1">
                </form>
