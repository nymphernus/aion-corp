<?php
/**
 * Единое подтверждение действия (Stage 3.7-g-4).
 *
 * Подключается из partials/header.php, то есть есть на каждой
 * странице: удаление заказа, комплектующего и пользователя в админке,
 * удаление из избранного и выход из аккаунта в профиле.
 *
 * Сама форма не отправляется - только подтверждение. Действие выполняет
 * колбэк, переданный в window.confirmAction().
 */
?>
<dialog id="confirmModal" class="modal">
    <div class="modal-form">
        <h2 id="confirmTitle">Подтверждение</h2>
        <p id="confirmMessage" style="color: var(--text-secondary); margin-bottom: 24px;"></p>
        <div class="modal-actions">
            <div class="modal-actions-right">
                <button type="button" class="btn btn--secondary" data-action="confirm-cancel">Отмена</button>
                <button type="button" class="btn btn--danger" data-action="confirm-ok">Подтвердить</button>
            </div>
        </div>
    </div>
</dialog>