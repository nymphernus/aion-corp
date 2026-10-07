/*
 * Допкомпоненты пользовательской сборки на /assembly.php.
 *
 * Отдельный файл, а не инлайн-скрипт: CSP в .htaccess запрещает
 * script-src 'self' без nonce. Данные приходят через data-slots по той же
 * причине. Весь DOM собирается через createElement и textContent:
 * innerHTML выполнил бы имя компонента как разметку.
 *
 * У базовой сборки секции #extraComponents нет, и код выходит сразу.
 * Сохранение и покупка живут в assembly-actions.js - под этим выходом
 * они у базовой сборки не регистрировались вовсе.
 */
(function () {
    'use strict';

    var section = document.getElementById('extraComponents');
    if (!section) {
        return;
    }

    var slots;
    try {
        slots = JSON.parse(section.dataset.slots || '{}');
    } catch (e) {
        return;
    }

    /* Порядок слотов задаёт этот список, а не объект: в JSON порядок ключей
       не гарантирован, и карточки поехали бы в разном порядке при каждой
       загрузке. */
    var ORDER = ['ssd_2_id', 'hdd_id'];

    var ssdHidden = document.getElementById('extraSsd2Hidden');
    var hddHidden = document.getElementById('extraHddHidden');
    var itemsEl = document.getElementById('extraItems');
    var emptyEl = document.getElementById('extraEmpty');
    var modal = document.getElementById('extraPickerModal');
    var listEl = document.getElementById('extraPickerList');
    var priceEl = document.getElementById('buildPrice');

    /* База = нынешняя цена сборки минус нынешние допы. Сервер считает
       то же дельтой, поэтому цифра на экране совпадает с базой. */
    var base = parseInt(section.dataset.basePrice, 10) || 0;
    var fmt = new Intl.NumberFormat('ru-RU');

    var state = {
        ssd_2_id: parseInt(ssdHidden.value, 10) || 0,
        hdd_id: parseInt(hddHidden.value, 10) || 0
    };
    var pickerSlot = ORDER[0];

    function itemsOf(slot) {
        var data = slots[slot];
        return data && data.items ? data.items : [];
    }

    function labelOf(slot) {
        var data = slots[slot];
        return data && data.label ? data.label : '';
    }

    function findItem(slot, id) {
        var list = itemsOf(slot);
        for (var i = 0; i < list.length; i++) {
            if (parseInt(list[i].component_id, 10) === id) {
                return list[i];
            }
        }
        return null;
    }

    function rubles(value) {
        return fmt.format(value) + ' ₽';
    }

    function render() {
        itemsEl.textContent = '';
        var total = AionCore.computeTotal(state, slots, base);
        var count = 0;

        for (var i = 0; i < ORDER.length; i++) {
            var slot = ORDER[i];
            var id = state[slot];
            if (!id) {
                continue;
            }

            /* Компонент, которого нет в списке, пропускаем: так бывает,
               когда он закончился и попал в базу до того, как его списали.
               Молчаливый пропуск честнее карточки с ценой 0. */
            var item = findItem(slot, id);
            if (!item) {
                continue;
            }

            var price = parseInt(item.component_price, 10) || 0;
            count++;

            var row = document.createElement('div');
            row.className = 'extra-item';

            var info = document.createElement('div');
            info.className = 'extra-item__info';

            var label = document.createElement('div');
            label.className = 'extra-item__label';
            label.textContent = labelOf(slot);

            var name = document.createElement('div');
            name.className = 'extra-item__name';
            name.textContent = item.component_name;

            info.appendChild(label);
            info.appendChild(name);

            var priceBox = document.createElement('div');
            priceBox.className = 'extra-item__price';
            priceBox.textContent = rubles(price);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'extra-item__remove';
            remove.textContent = '×';
            remove.title = 'Убрать';
            remove.setAttribute('aria-label', 'Убрать');
            remove.setAttribute('data-action', 'remove-extra');
            remove.setAttribute('data-slot', slot);

            row.appendChild(info);
            row.appendChild(priceBox);
            row.appendChild(remove);
            itemsEl.appendChild(row);
        }

        emptyEl.hidden = count > 0;
        ssdHidden.value = state.ssd_2_id || '0';
        hddHidden.value = state.hdd_id || '0';

        if (priceEl) {
            priceEl.textContent = rubles(total);
        }
    }

    function openPicker(slot) {
        pickerSlot = slot;

        var tabs = modal.querySelectorAll('.extra-picker-tab');
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('is-active', tabs[i].dataset.slot === slot);
        }

        listEl.textContent = '';

        var list = itemsOf(slot);
        if (list.length === 0) {
            var hint = document.createElement('p');
            hint.className = 'form-hint';
            hint.textContent = 'Нет доступных компонентов';
            listEl.appendChild(hint);
            return;
        }

        var currentId = state[slot];

        for (var j = 0; j < list.length; j++) {
            var item = list[j];
            var id = parseInt(item.component_id, 10);

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'extra-picker-item'
                + (id === currentId ? ' is-current' : '');
            btn.setAttribute('data-action', 'pick-extra');
            btn.setAttribute('data-slot', slot);
            btn.setAttribute('data-id', String(id));

            var nameEl = document.createElement('span');
            nameEl.className = 'extra-picker-item__name';
            nameEl.textContent = item.component_name;

            var priceSpan = document.createElement('span');
            priceSpan.className = 'extra-picker-item__price';
            priceSpan.textContent = rubles(parseInt(item.component_price, 10) || 0);

            btn.appendChild(nameEl);
            btn.appendChild(priceSpan);
            listEl.appendChild(btn);
        }
    }

    document.addEventListener('click', function (e) {
        var open = e.target.closest('[data-action="open-extra-picker"]');
        if (open) {
            e.preventDefault();
            openPicker(pickerSlot);
            modal.showModal();
            return;
        }

        var close = e.target.closest('[data-action="close-extra-picker"]');
        if (close) {
            e.preventDefault();
            modal.close();
            return;
        }

        var tab = e.target.closest('[data-action="extra-picker-tab"]');
        if (tab) {
            e.preventDefault();
            openPicker(tab.dataset.slot);
            return;
        }

        var pick = e.target.closest('[data-action="pick-extra"]');
        if (pick) {
            e.preventDefault();
            state[pick.dataset.slot] = parseInt(pick.dataset.id, 10) || 0;
            modal.close();
            render();
            return;
        }

        var remove = e.target.closest('[data-action="remove-extra"]');
        if (remove) {
            e.preventDefault();
            state[remove.dataset.slot] = 0;
            render();
        }
    });

    render();
}());