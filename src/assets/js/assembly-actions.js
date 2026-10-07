/*
 * Сохранение и покупка сборки на /assembly.php.
 *
 * Отдельный файл, а не часть assembly-extra.js: у базовой сборки секции
 * допкомпонентов нет, и тот файл выходит сразу - кнопки на витрине были
 * мёртвыми. Подтверждения живут в confirm.js из подвала.
 */
(function () {
    'use strict';

    /*
     * Скрытое поле с именем действия добавляется в момент отправки: в разметке
     * кнопки объявлены type="button", иначе форма ушла бы до ответа человека.
     * Обработчик на сервере различает save и buy именно по этому полю.
     */
    function confirmAndSubmit(action, title, message) {
        /* У кнопки префикс confirm-, у поля его нет. Искать по одному имени -
           верный способ получить btn === null и тихо ничего не сделать. */
        var btn = document.querySelector('[data-action="confirm-' + action + '"]');
        if (!btn || btn.disabled) {
            return;
        }

        var form = btn.closest('form');
        if (!form || typeof window.confirmAction !== 'function') {
            return;
        }

        window.confirmAction(title, message, function () {
            /* Поле от прошлого подтверждения удаляется: повторное нажатие
               добавило бы второе, и действие ушло бы дважды. */
            var stale = form.querySelector('input[name="' + action + '"]');
            if (stale) {
                stale.parentNode.removeChild(stale);
            }

            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = action;
            hidden.value = '1';
            form.appendChild(hidden);
            form.submit();
        });
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-action="confirm-save"]')) {
            e.preventDefault();
            confirmAndSubmit(
                'save',
                'Сохранить сборку?',
                'Сборка будет добавлена в избранное.'
            );
            return;
        }

        if (e.target.closest('[data-action="confirm-buy"]')) {
            e.preventDefault();
            confirmAndSubmit(
                'buy',
                'Оформить заказ?',
                'Комплектующие будут списаны со склада, заказ появится в профиле.'
            );
        }
    });
}());