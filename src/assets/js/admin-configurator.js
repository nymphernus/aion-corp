/* Вкладка «Конфигуратор»: пресеты и операционные системы.
 *
 * Отдельный файл, а не дописание к admin-settings.js: тот подключается
 * только на вкладке настроек (админ.php добавляет его по условию), а
 * эта вкладка другая и скрипта настроек на ней нет.
 *
 * Открытие модалки, заполнение полей из строки таблицы и удаление через
 * общую #confirmModal. Сам обработчик POST - в admin.php.
 */
(function() {
    /* Открыть модалку пресета.
     * preset передаётся строкой таблицы (data-preset-id) или null для
     * добавления. Данные берутся из самой строки, а не из глобальной
     * переменной: у каждой строки свой набор, и общее состояние
     * разъезжалось бы при переходе между строками. */
    function openPresetModal(row) {
        const modal = document.getElementById('presetModal');
        if (!modal) return;

        const picker = document.getElementById('presetIconPicker');

        if (row) {
            const id = row.dataset.presetId;
            const nameCell = row.querySelector('.social-row__name');
            const budgetCell = row.querySelector('.cfg-table__num');
            const iconCell = row.querySelector('.cfg-row__icon svg');
            const isActive = row.querySelector('.badge--success') !== null;

            document.getElementById('presetId').value = id;
            document.getElementById('presetName').value = nameCell ? nameCell.textContent.trim() : '';
            document.getElementById('presetBudget').value =
                budgetCell ? budgetCell.textContent.replace(/[^\d]/g, '') : '';
            document.getElementById('presetActive').checked = isActive;
            document.getElementById('presetModalTitle').textContent = 'Редактировать пресет';

            /* Иконка в строке таблицы - тот же svg, что в выборе.
               Имя ключа берём из его класса в разметке выбора, а не
               пытаемся распознать нарисованную картинку. */
            if (picker && iconCell) {
                const iconKey = findIconKey(picker, iconCell);
                if (iconKey) {
                    const radio = picker.querySelector('input[value="' + iconKey + '"]');
                    if (radio) radio.checked = true;
                }
            }
        } else {
            document.getElementById('presetId').value = '';
            document.getElementById('presetName').value = '';
            document.getElementById('presetBudget').value = '';
            document.getElementById('presetActive').checked = true;
            document.getElementById('presetModalTitle').textContent = 'Добавить пресет';

            if (picker) {
                const first = picker.querySelector('input[value="monitor"]') ||
                    picker.querySelector('input');
                if (first) first.checked = true;
            }
        }

        if (typeof modal.showModal === 'function') {
            modal.showModal();
        } else {
            modal.setAttribute('open', '');
        }
    }

    /* Найти ключ иконки по нарисованному svg.
     * Сравниваем содержимое path/line внутри svg: набор иконок
     * фиксирован, и одинаковых по содержимому нет. */
    function findIconKey(picker, svg) {
        const target = svg.innerHTML.trim();
        const options = picker.querySelectorAll('input[type="radio"][value]');
        for (let i = 0; i < options.length; i++) {
            const optionSvg = options[i].parentElement.querySelector('svg');
            if (optionSvg && optionSvg.innerHTML.trim() === target) {
                return options[i].value;
            }
        }
        return null;
    }

    function openOsModal(row) {
        const modal = document.getElementById('osModal');
        if (!modal) return;

        if (row) {
            const nameCell = row.querySelector('.social-row__name');
            const priceCell = row.querySelector('.cfg-table__num');
            const isActive = row.querySelector('.badge--success') !== null;

            document.getElementById('osId').value = row.dataset.osId;
            document.getElementById('osName').value = nameCell ? nameCell.textContent.trim() : '';
            /* В таблице ноль выводится словом «бесплатно», из которого
               цифр не достать. Ноль и есть правильное значение, но
               подставить пустую строку хуже, чем 0: поле required
               показало бы ошибку на ровном валидном состоянии. */
            const priceText = priceCell ? priceCell.textContent.replace(/[^\d]/g, '') : '';
            document.getElementById('osPrice').value = priceText === '' ? '0' : priceText;
            document.getElementById('osActive').checked = isActive;
            document.getElementById('osModalTitle').textContent = 'Редактировать операционную систему';
        } else {
            document.getElementById('osId').value = '';
            document.getElementById('osName').value = '';
            document.getElementById('osPrice').value = '0';
            document.getElementById('osActive').checked = true;
            document.getElementById('osModalTitle').textContent = 'Добавить операционную систему';
        }

        if (typeof modal.showModal === 'function') {
            modal.showModal();
        } else {
            modal.setAttribute('open', '');
        }
    }

    document.addEventListener('click', function(e) {
        const target = e.target;

        const addPreset = target.closest('[data-action="add-preset"]');
        if (addPreset) {
            e.preventDefault();
            openPresetModal(null);
            return;
        }

        const editPreset = target.closest('[data-action="edit-preset"]');
        if (editPreset) {
            e.preventDefault();
            openPresetModal(editPreset.closest('tr'));
            return;
        }

        const addOs = target.closest('[data-action="add-os"]');
        if (addOs) {
            e.preventDefault();
            openOsModal(null);
            return;
        }

        const editOs = target.closest('[data-action="edit-os"]');
        if (editOs) {
            e.preventDefault();
            openOsModal(editOs.closest('tr'));
            return;
        }

        const delPreset = target.closest('[data-action="delete-preset"]');
        if (delPreset) {
            e.preventDefault();
            const name = delPreset.dataset.presetName || 'пресет';
            window.confirmAction(
                'Удалить пресет?',
                'Пресет «' + name + '» пропадёт из конфигуратора на главной.',
                function () {
                    const form = document.getElementById('deletePresetForm');
                    if (!form) return;
                    form.querySelector('input[name="presetId"]').value = delPreset.dataset.presetId;
                    form.submit();
                }
            );
            return;
        }

        const delOs = target.closest('[data-action="delete-os"]');
        if (delOs) {
            e.preventDefault();
            const name = delOs.dataset.osName || 'операционная система';
            window.confirmAction(
                'Удалить операционную систему?',
                '«' + name + '» пропадёт из списка в конфигураторе на главной.',
                function () {
                    const form = document.getElementById('deleteOsForm');
                    if (!form) return;
                    form.querySelector('input[name="osId"]').value = delOs.dataset.osId;
                    form.submit();
                }
            );
        }
    });
})();
