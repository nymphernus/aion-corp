/*
 * Единое подтверждение действия.
 *
 * Отдельный файл, потому что разметка модалки подключается из header.php
 * на каждой странице, а scripts.js - не везде: на странице сборки его нет,
 * и подтверждения «Сохранить» и «Купить» остались бы без обработчика.
 *
 * Колбэк хранится в переменной, кнопки слушает делегированный обработчик.
 * Клонировать кнопку «Подтвердить» ради отвязки прошлых слушателей не
 * нужно: слушатель один, он на документе, и подменять ему нечего.
 */
(function () {
    'use strict';

    var confirmCallback = null;

    window.confirmAction = function (title, message, onConfirm) {
        var modal = document.getElementById('confirmModal');
        if (!modal) return;

        var setText = function (id, value) {
            var el = modal.querySelector(id);
            if (el) el.textContent = value;
        };
        setText('#confirmTitle', title);
        setText('#confirmMessage', message);

        confirmCallback = typeof onConfirm === 'function' ? onConfirm : null;
        modal.showModal();
    };

    document.addEventListener('click', function (e) {
        var modal = document.getElementById('confirmModal');

        if (e.target.closest('[data-action="confirm-ok"]')) {
            var callback = confirmCallback;
            confirmCallback = null;
            if (modal && modal.open) modal.close();
            if (callback) callback();
            return;
        }

        if (e.target.closest('[data-action="confirm-cancel"]')) {
            confirmCallback = null;
            if (modal && modal.open) modal.close();
        }
    });
}());