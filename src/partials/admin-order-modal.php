<?php
/**
 * Модалка заказа админ-панели .
 *
 * Подключается из admin.php, а не из _tab_orders.php: с 3.7-g-3 её
 * открывает не только таблица заказов, но и блок «Последние заказы»
 * на дашборде, где вкладка orders не отрисована.
 *
 * Разметка не зависит от данных вкладки - всё подставляет JS из
 * data-row строки заказа (контракт собирает adminOrderRowData()
 * из admin/_order_row_data.php).
 */
?>
                <!--
                    модалка заказа — детали, смена статуса,
                    ссылки на профиль покупателя и на сборку.
                    Отправляет name="editOrder" (обработчик в admin.php).
                -->
                <dialog id="editOrderModal" class="modal">
                    <form method="post" class="modal-form" action="/admin.php?tab=orders">
                        <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                        <?php // фильтры списка заказов переживают POST только
                              // через это поле; ключи фильтруются по
                              // белому списку на сервере ?>
                        <input type="hidden" name="return_params" value="<?= escape(admin_list_query('orders')) ?>">
                        <input type="hidden" name="orderId" id="editOrderId" value="">

                        <h2>Заказ №<span id="editOrderNumber"></span></h2>

                        <div class="modal-section">
                            <h3>Информация о покупателе</h3>
                            <div class="form-group">
                                <label class="form-label" for="editOrderBuyerBtn">Покупатель</label>
                                <!-- кнопка вместо ссылки, клик открывает
                                     модалку пользователя -->
                                <button type="button" class="btn btn--secondary btn--sm"
                                        id="editOrderBuyerBtn" data-action="open-user-from-order" data-user-id="">
                                    <span id="editOrderBuyerName"></span>
                                </button>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Контакты</label>
                                <div id="editOrderContacts"></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Адрес доставки</label>
                                <div id="editOrderAddress"></div>
                            </div>
                        </div>

                        <div class="modal-section">
                            <h3>Информация о сборке</h3>
                            <div class="form-group">
                                <label class="form-label" for="editOrderAssemblyBtn">Сборка</label>
                                <!-- остаётся <a>, но оформлен кнопкой и
                                     открывается в новой вкладке -->
                                <a href="#" id="editOrderAssemblyBtn" target="_blank" rel="noopener"
                                   class="btn btn--secondary btn--sm"><span id="editOrderAssemblyName"></span></a>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Стоимость</label>
                                <div><span id="editOrderPrice"></span> руб.</div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Создан</label>
                                <div id="editOrderCreated"></div>
                            </div>
                        </div>

                        <div class="modal-section">
                            <h3>Статус заказа</h3>
                            <div class="form-group">
                                <select class="input" name="status" id="editOrderStatusSelect">
                                    <option value="Обрабатывается">Обрабатывается</option>
                                    <option value="Собирается">Собирается</option>
                                    <option value="Доставляется">Доставляется</option>
                                    <option value="Выполнен">Выполнен</option>
                                    <option value="Отменён">Отменён</option>
                                </select>
                            </div>
                        </div>

                        <div class="modal-actions">
                            <button type="button" class="btn btn--danger" data-action="delete-order">Удалить</button>
                            <div class="modal-actions-right">
                                <button type="button" class="btn btn--secondary" data-action="close-modal">Отмена</button>
                                <button type="submit" name="editOrder" class="btn btn--primary">Сохранить</button>
                            </div>
                        </div>
                    </form>
                </dialog>

                <!--
                    форма удаления заказа. Отправляет
                    confirmAction после подтверждения в #confirmModal.
                -->
                <form id="deleteOrderForm" method="post" action="/admin.php?tab=orders" hidden>
                    <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">
                    <input type="hidden" name="return_params" value="<?= escape(admin_list_query('orders')) ?>">
                    <input type="hidden" name="orderId" id="deleteOrderId" value="">
                    <!-- deleteOrder лежит скрытым input, а не
                         кнопкой: form.submit() не включает имя нажатой
                         кнопки, а обработчик проверяет $_POST['deleteOrder'] -->
                    <input type="hidden" name="deleteOrder" value="1">
                </form>