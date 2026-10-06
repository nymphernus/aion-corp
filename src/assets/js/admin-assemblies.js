/* Вкладка «Сборки»: базовые сборки витрины.
 *
 * Отдельный файл по той же причине, что и admin-configurator.js: тот
 * подключается только на своей вкладке, и на этой его просто не было бы.
 *
 * Открытие модалки, заполнение полей из строки таблицы и удаление через
 * общую #confirmModal. Сам обработчик POST - в admin.php.
 *
 * Данные для заполнения берутся из самой строки (data-parts), а не из
 * глобальной переменной: у каждой строки свой набор, и общее состояние
 * разъезжалось бы при переходе между сборками.
 */
(function() {
    /* Разобрать data-parts строки в объект «категория -> компонент».
     *
     * Формат «1:5,2:44» выбран из-за CSP: инлайн-скрипт с данными
     * заблокирован, а JSON в атрибуте пришлось бы разбирать этим же
     * разбором строки - только на две строки больше. */
    function readParts(row) {
        const parts = {};
        const raw = row.dataset.parts || '';
        if (!raw) return parts;

        const pairs = raw.split(',');
        for (let i = 0; i < pairs.length; i++) {
            const bits = pairs[i].split(':');
            if (bits.length === 2) {
                parts[bits[0]] = bits[1];
            }
        }
        return parts;
    }

    /* Открыть модалку сборки.
     *
     * row - строка таблицы для правки или null для добавления. */
    function openAssemblyModal(row) {
        const modal = document.getElementById('assemblyModal');
        if (!modal) return;

        const selects = modal.querySelectorAll('select[name^="comp_"]');
        const title = document.getElementById('assemblyModalTitle');

        if (row) {
            const parts = readParts(row);

            document.getElementById('assemblyId').value = row.dataset.assemblyId;

            const nameCell = row.querySelector('.social-row__name');
            document.getElementById('asName').value =
                nameCell ? nameCell.textContent.trim() : '';

            /* Цена в таблице нарисована через number_format с
               неразрывным пробелом и знаком рубля. Цифры достаются
               регуляркой; если их не оказалось, поле остаётся пустым и
               required покажет ошибку - лучше, чем молча подставить 0. */
            const priceCell = row.children[3];
            const priceText = priceCell ? priceCell.textContent.replace(/[^\d]/g, '') : '';
            document.getElementById('asPrice').value = priceText;

            for (let i = 0; i < selects.length; i++) {
                const select = selects[i];
                const categoryId = select.name.replace('comp_', '');
                const componentId = parts[categoryId] || '0';
                /* Значение, которого нет в списке (например, компонент
                   закончился и пропал из выдачи), оставляем на первом
                   пункте. Иначе select молча показал бы старое значение
                   другого слота, и при сохранении в него записался бы
                   не тот компонент. */
                select.value = select.querySelector('option[value="' + componentId + '"]')
                    ? componentId
                    : '0';
            }

            if (title) title.textContent = 'Редактировать сборку';
        } else {
            document.getElementById('assemblyId').value = '';
            document.getElementById('asName').value = '';
            document.getElementById('asPrice').value = '';

            for (let i = 0; i < selects.length; i++) {
                selects[i].value = '0';
            }

            if (title) title.textContent = 'Добавить сборку';
        }

        if (typeof modal.showModal === 'function') {
            modal.showModal();
        } else {
            modal.setAttribute('open', '');
        }
    }

    document.addEventListener('click', function(e) {
        const target = e.target;

        const add = target.closest('[data-action="add-assembly"]');
        if (add) {
            e.preventDefault();
            openAssemblyModal(null);
            return;
        }

        const edit = target.closest('[data-action="edit-assembly"]');
        if (edit) {
            e.preventDefault();
            openAssemblyModal(edit.closest('tr'));
            return;
        }

        const del = target.closest('[data-action="delete-assembly"]');
        if (del) {
            e.preventDefault();
            const name = del.dataset.assemblyName || 'сборка';
            window.confirmAction(
                'Удалить сборку?',
                'Сборка «' + name + '» пропадёт с главной страницы.',
                function () {
                    const form = document.getElementById('deleteAssemblyForm');
                    if (!form) return;
                    form.querySelector('input[name="assemblyId"]').value = del.dataset.assemblyId;
                    /* form.submit(), а не отправка кнопкой: у формы нет
                       submit-полей, которые надо передать, - в отличие от
                       форм модалок. */
                    form.submit();
                }
            );
        }
    });
})();
