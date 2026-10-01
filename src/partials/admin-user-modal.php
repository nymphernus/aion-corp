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
                <!-- 3.7-f-4c-2: modal--wide — 760px и две колонки секций, иначе
                     данные пользователя в 500px не помещались -->
                <dialog id="editUserModal" class="modal modal--wide">
                    <form method="post" class="modal-form" action="/admin.php?tab=users">
                        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                        <input type="hidden" name="editUserId" id="editUserId" value="">

                        <h2>Пользователь: <span id="editUserLogin"></span></h2>

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
                            <div class="form-group">
                                <label class="form-label" for="editUserNameRO">Логин (не изменяется)</label>
                                <input class="input" id="editUserNameRO" disabled>
                            </div>
                        </div>

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

                        <div class="modal-section modal-section--cols">
                            <h3>Адрес</h3>
                            <!--
                                3.7-i-2: адрес разбит на поля (миграция 3.7-i-1).
                                Город не required: колонка nullable, и у части
                                пользователей адреса нет вовсе - required
                                запрещал бы сохранить любое другое поле.
                                Значения кладутся без маркеров («Победы», а не
                                «ул. Победы»), подпись поля их поясняет.
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
                            <div class="form-group">
                                <label class="form-label" for="editUserApartment">Квартира</label>
                                <input class="input" name="user_apartment" id="editUserApartment"
                                       maxlength="20" placeholder="42">
                            </div>

                            <!-- 3.7-i-2: исходная строка адреса только для чтения.
                                 В новом потоке user_address не обновляется, но
                                 показывается, пока он есть в базе. Показывает и
                                 прячет JS - серверных данных о конкретном
                                 пользователе у этой общей модалки нет. -->
                            <div class="form-group span-all" id="editUserLegacyWrap" hidden>
                                <label class="form-label" for="editUserAddressLegacy">Исходный адрес (legacy)</label>
                                <input class="input" id="editUserAddressLegacy" disabled>
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

                        <div class="modal-actions">
                            <button type="button" class="btn btn--danger" id="editUserDeleteBtn" data-action="open-delete-user-modal">Удалить</button>
                            <div class="modal-actions-right">
                                <button type="button" class="btn btn--secondary" data-action="close-modal">Отмена</button>
                                <button type="submit" name="editUser" class="btn btn--primary">Сохранить</button>
                            </div>
                        </div>
                    </form>
                </dialog>

                <!-- 3.7-h-2: подтверждение удаления пользователя -->
                <dialog id="deleteUserModal" class="modal">
                    <form method="post" class="modal-form" action="/admin.php?tab=users">
                        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                        <input type="hidden" name="userId" id="deleteUserId" value="">

                        <h2>Удалить пользователя?</h2>
                        <p id="deleteUserName" style="color: var(--text-secondary); margin-bottom: 24px;"></p>

                        <div class="modal-actions">
                            <div class="modal-actions-right">
                                <button type="button" class="btn btn--secondary" data-action="close-modal">Отмена</button>
                                <button type="submit" name="deleteUser" class="btn btn--danger">Удалить</button>
                            </div>
                        </div>
                    </form>
                </dialog>