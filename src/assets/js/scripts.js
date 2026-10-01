
function switchReg(divIdF,divIdS){
    var stylePropF = document.getElementById(divIdF);
    var stylePropS = document.getElementById(divIdS);
    if(window.getComputedStyle(stylePropF).display == "none")
    {
        stylePropF.style.display = "block";
        stylePropS.style.display = "none";
    }
    else
    {
        stylePropF.style.display = "none";
        stylePropS.style.display = "block";
    }
}

function show(getId){
    var fieldId = document.getElementById(getId);
    if(window.getComputedStyle(fieldId).display == "none")
    {
        fieldId.style.display = "block";
    }
    else
    {
        fieldId.style.display = "none";
    }
}

function hide(getId){
    let fieldId = document.getElementById(getId);
    if(window.getComputedStyle(fieldId).display != "none")
    {
        fieldId.style.display = "none";
    }
}

// Делегирование кликов для data-action (CSP: инлайн-обработчики запрещены)
document.addEventListener('click', function(e) {
    var el = e.target.closest ? e.target.closest('[data-action]') : null;
    if (!el) return;
    var action = el.getAttribute('data-action');
    if (action === 'switch' && el.hasAttribute('data-target')) {
        // Табы профиля: скрыть все секции, показать целевую
        e.preventDefault();
        var target = el.getAttribute('data-target');
        document.querySelectorAll('.profile-content > [data-section]').forEach(function(s) {
            s.style.display = 'none';
        });
        var t = document.getElementById(target);
        if (t) t.style.display = '';
        document.querySelectorAll('.profile-nav-item').forEach(function(i) {
            i.classList.remove('active');
        });
        el.classList.add('active');
    } else if (action === 'switch') {
        e.preventDefault();
        switchReg(el.getAttribute('data-a'), el.getAttribute('data-b'));
    } else if (action === 'show') {
        e.preventDefault();
        show(el.getAttribute('data-id'));
    } else if (action === 'hide') {
        e.preventDefault();
        hide(el.getAttribute('data-id'));
    } else if (action === 'edit') {
        e.preventDefault();
        document.querySelectorAll('.profile-field[data-editing]')
            .forEach(function(f) { f.removeAttribute('data-editing'); });
        var field = el.closest ? el.closest('.profile-field') : null;
        if (field) {
            field.setAttribute('data-editing', '');
            var input = field.querySelector('.input');
            if (input) input.focus();
        }
    } else if (action === 'cancel-edit') {
        e.preventDefault();
        var cfield = el.closest ? el.closest('.profile-field') : null;
        if (cfield) cfield.removeAttribute('data-editing');
    } else if (action === 'open-modal') {
        e.preventDefault();
        var modal = document.getElementById(el.getAttribute('data-modal'));
        if (modal && typeof modal.showModal === 'function') modal.showModal();
    } else if (action === 'close-modal') {
        e.preventDefault();
        var dlg = el.closest ? el.closest('dialog') : null;
        if (dlg && typeof dlg.close === 'function') dlg.close();
    } else if (action === 'menu') {
        e.preventDefault();
        var nav = document.querySelector('.nav');
        if (nav) nav.classList.toggle('nav--open');
    }
});

// 3.7-f-3-12: пересборка селекта форм-фактора под категорию.
// Значение, которого нет в новом списке (старые данные вроде M.2 или
// mATX), сохраняем отдельной опцией - иначе оно молча потерялось бы
// при сохранении.
function rebuildFormFactors(modal, catId, desiredValue) {
    var sel = modal.querySelector('#formFactorSelect');
    if (!sel) return;

    var map = {};
    try {
        map = JSON.parse(sel.dataset.options || '{}');
    } catch (err) {
        console.error('Invalid form factor map', err);
    }

    var options = map[catId] || [];
    // desiredValue передаёт edit-режим: на момент вызова значение ещё
    // нельзя ставить в select, опции для него может не быть
    var current = desiredValue !== undefined && desiredValue !== null
        ? desiredValue
        : sel.value;

    sel.innerHTML = '';
    if (options.length === 0) {
        var none = document.createElement('option');
        none.value = '';
        none.textContent = 'Не применимо';
        sel.appendChild(none);
        sel.disabled = true;
        return;
    }

    sel.disabled = false;
    var empty = document.createElement('option');
    empty.value = '';
    empty.textContent = 'Не указан';
    sel.appendChild(empty);

    options.forEach(function(v) {
        var opt = document.createElement('option');
        opt.value = v;
        opt.textContent = v;
        sel.appendChild(opt);
    });

    if (current && options.indexOf(current) === -1) {
        var legacy = document.createElement('option');
        legacy.value = current;
        legacy.textContent = current + ' (прежнее значение)';
        sel.appendChild(legacy);
    }
    if (current) {
        sel.value = current;
    } else {
        sel.value = '';
    }
}

// 3.7-c: показ/скрытие групп полей модалки по выбранной категории.
// Группы (.field-group) описаны атрибутом data-cat — списком category_id.
document.addEventListener('change', function(e) {
    if (e.target.matches('#addComponentModal select[name="cat"]')) {
        var catId = e.target.value;
        var modal = e.target.closest('dialog');
        rebuildFormFactors(modal, catId);
        modal.querySelectorAll('.field-group').forEach(function(g) {
            var cats = (g.dataset.cat || '').split(/\s+/);
            if (cats.includes(catId)) {
                g.classList.add('is-visible');
            } else {
                g.classList.remove('is-visible');
            }
        });
    }
});

// При открытии модалки — сразу отрисовать поля для выбранной категории
document.addEventListener('click', function(e) {
    var openBtn = e.target.closest('[data-action="open-modal"]');
    if (openBtn && openBtn.dataset.modal === 'addComponentModal') {
        var modal = document.getElementById('addComponentModal');
        var catSelect = modal.querySelector('select[name="cat"]');
        if (catSelect) {
            catSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
});

// 3.7-f-1/2: открытие edit-модалки кликом по строке таблицы.
// Клик по ссылке или кнопке внутри строки игнорируем.
document.addEventListener('click', function(e) {
    var tr = e.target.closest ? e.target.closest('tr[data-component]') : null;
    if (!tr) return;
    if (e.target.closest('a, button')) return;
    e.preventDefault();

    var data;
    try {
        data = JSON.parse(tr.dataset.component);
    } catch (err) {
        console.error('Invalid component JSON', err);
        return;
    }

    var modal = document.getElementById('addComponentModal');
    if (!modal) return;

    modal.querySelector('#modalTitle').textContent = 'Редактировать комплектующий';
    modal.querySelector('#editComponentId').value = data.id;
    modal.querySelector('#modalSubmit').textContent = 'Сохранить';

    var setVal = function(name, val) {
        var el = modal.querySelector('[name="' + name + '"]');
        if (!el) return;
        if (el.type === 'checkbox') {
            el.checked = !!val;
        } else {
            el.value = val ?? '';
        }
    };

    setVal('nm', data.name);
    setVal('pr', data.price);
    setVal('col', data.amount);
    setVal('cat', data.category_id);
    setVal('description', data.description);
    setVal('manufacturer', data.manufacturer);
    setVal('model', data.model);
    setVal('socket', data.socket_id);
    setVal('tdp', data.tdp);
    setVal('frequency_mhz', data.frequency_mhz);
    setVal('video_core', data.video_core);
    setVal('ram_type', data.ram_type);
    setVal('capacity_gb', data.capacity_gb);
    setVal('memory_type', data.memory_type);
    setVal('wattage', data.wattage);
    setVal('interface', data.interface);
    setVal('rpm', data.rpm);
    setVal('cooler_type', data.cooler_type);

    // 3.7-f-3-12: сначала пересборка селекта форм-фактора под категорию,
    // и только потом значение - установка .value для отсутствующей опции
    // обнуляет select, и прежнее значение (например M.2) потерялось бы
    var catSelect = modal.querySelector('[name="cat"]');
    if (catSelect) {
        catSelect.dispatchEvent(new Event('change', { bubbles: true }));
    }
    // после пересборки возвращаем значение из БД: если его нет в
    // списке категории, оно добавляется как «прежнее значение»
    rebuildFormFactors(modal, catSelect ? catSelect.value : '', data.form_factor ?? '');

    // 3.7-f-2: кнопка удаления видима только в edit-режиме
    var delBtn = modal.querySelector('#modalDeleteBtn');
    if (delBtn) {
        delBtn.hidden = false;
        delBtn.dataset.id = data.id;
        delBtn.dataset.name = data.name ?? '';
    }

    // change на категории уже отправлен выше: он и перестроил селект
    // форм-фактора, и показал релевантные группы полей

    modal.showModal();
});

// 3.7-f-3-3/3-10: фильтр «Сокет» показывается только для CPU / платы /
// кулера. Сервер уже прячет его при отрисовке, здесь синхронизация при
// смене категории без перезагрузки.
document.addEventListener('change', function(e) {
    if (e.target.name !== 'cat') return;
    var form = e.target.closest('.admin-filters');
    if (!form) return;
    var wrap = form.querySelector('#sockFilterWrap');
    if (!wrap) return;
    var cat = e.target.value;
    var relevant = cat === '1' || cat === '2' || cat === '7';
    wrap.style.display = relevant ? '' : 'none';
});

// 3.7-f-4b-2: строка с data-href переходит по ссылке.
// Клик по кнопке/ссылке/полю внутри строки переход не запускает —
// отдельный stopPropagation не нужен, CSP не любит onclick.
document.addEventListener('click', function(e) {
    var tr = e.target.closest ? e.target.closest('tr[data-href]') : null;
    if (!tr) return;
    if (e.target.closest('a, button, select, input, form')) return;
    var href = tr.getAttribute('data-href');
    if (href) {
        window.location.href = href;
    }
});

// 3.7-f-2-6: клик по фону вокруг открытой модалки закрывает её.
// Нативное поведение dialog: клик по самому элементу (мимо содержимого)
// попадает сюда с e.target === modal. Регистрируется на верхнем уровне,
// иначе обработчик жил бы внутри другого и не существовал бы на части
// страниц.
document.addEventListener('click', function(e) {
    var openModal = e.target.closest ? e.target.closest('dialog[open]') : null;
    if (!openModal) return;
    if (e.target === openModal) {
        openModal.close();
    }
});

// 3.7-f-4-2: единый открыватель модалки пользователя.
// Используется и из таблицы пользователей, и из модалки заказа.
function openUserModal(data) {
    var modal = document.getElementById('editUserModal');
    if (!modal || !data) return;

    var uSet = function(id, value) {
        var el = modal.querySelector(id);
        if (el) el.value = value ?? '';
    };
    uSet('#editUserId', data.id);
    // #editUserLogin — span в заголовке, не input
    var loginSpan = modal.querySelector('#editUserLogin');
    if (loginSpan) loginSpan.textContent = data.login ?? '';
    uSet('#editUserNameRO', data.login);
    uSet('#editUserName', data.name);
    // 3.7-f-4c-3: фамилия не обязательна, пустое значение тоже валидно
    uSet('#editUserSurname', data.surname);
    uSet('#editUserAddress', data.address);
    uSet('#editUserNumber', data.number);
    uSet('#editUserGroup', data.group);

    var delBtn = modal.querySelector('#editUserDeleteBtn');
    if (delBtn) {
        delBtn.dataset.id = data.id;
        delBtn.dataset.name = data.login ?? '';
    }
    modal.showModal();
}

// 3.7-f-4-2: из модалки заказа — кнопка покупателя открывает его модалку.
// Данные берём из data-row заказа (без AJAX): сервер отдаёт логин,
// группу, адрес и телефон покупателя вместе со строкой заказа.
document.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-action="open-user-from-order"]');
    if (!btn) return;
    e.preventDefault();

    var raw = btn.dataset.user;
    if (!raw) return;
    var data;
    try {
        data = JSON.parse(raw);
    } catch (err) {
        console.error('Invalid user JSON', err);
        return;
    }

    var orderModal = document.getElementById('editOrderModal');
    if (orderModal && orderModal.open) orderModal.close();
    openUserModal(data);
});

// 3.7-f-4b-3: открыватель read-only модалки заказа в профиле.
// Модалка ничего не отправляет — только показывает данные строки.
function openUserOrderModal(data) {
    var modal = document.getElementById('userOrderModal');
    if (!modal || !data) return;

    var setText = function(id, value) {
        var el = modal.querySelector(id);
        if (el) {
            el.textContent = (value === null || value === undefined || value === '')
                ? 'Не указан' : String(value);
        }
    };

    setText('#userOrderNumber', data.order_id);
    setText('#userOrderAssembly', data.assembly_name);
    setText('#userOrderPrice', (data.price ?? '') + ' руб.');
    setText('#userOrderCreated', data.created_at);

    var status = modal.querySelector('#userOrderStatus');
    if (status) {
        status.textContent = data.status ?? 'Не указан';
        status.className = 'badge ' + (
            data.status === 'Выполнен' ? 'badge--success'
            : data.status === 'Отменён' ? 'badge--error'
            : 'badge--warning'
        );
    }

    modal.showModal();
}

// 3.7-h-1: клик по строке заказа — модалка с деталями и сменой статуса
document.addEventListener('click', function(e) {
    var tr = e.target.closest ? e.target.closest('tr[data-row]') : null;
    if (!tr) return;
    if (e.target.closest('a, button, select, input')) return;

    var data;
    try {
        data = JSON.parse(tr.dataset.row);
    } catch (err) {
        console.error('Invalid row JSON', err);
        return;
    }

    // 3.7-h-2: строка пользователя открывает свою модалку
    if (data.modal === 'user') {
        openUserModal(data);
        return;
    }

    // 3.7-f-4b-3: строка заказа в профиле пользователя — только просмотр
    if (data.modal === 'user-order') {
        openUserOrderModal(data);
        return;
    }

    var modal = document.getElementById('editOrderModal');
    if (!modal) return;

    var text = function(id, value) {
        var el = modal.querySelector(id);
        if (el) el.textContent = (value === null || value === undefined || value === '')
            ? 'Не указан' : String(value);
    };

    // 3.7-f-3-2: покупатель — кнопка (откроет модалку пользователя в f-4),
    // сборка — ссылка-кнопка в новой вкладке
    var label = function(id, value) {
        var el = modal.querySelector(id);
        if (el) el.textContent = (value === null || value === undefined || value === '')
            ? 'Не указан' : String(value);
    };

    modal.querySelector('#editOrderId').value = data.id;
    text('#editOrderNumber', data.id);
    label('#editOrderBuyerName', data.buyer);
    // 3.7-f-4-2: кладём данные покупателя прямо на кнопку, чтобы переход
    // в его модалку работал без AJAX
    var buyerBtn = modal.querySelector('#editOrderBuyerBtn');
    if (buyerBtn) {
        buyerBtn.dataset.userId = data.user_id ?? '';
        buyerBtn.dataset.user = JSON.stringify({
            id: data.user_id ?? '',
            name: data.user_name ?? data.buyer ?? '',
            // 3.7-f-4c-3: фамилия покупателя нужна его же модалке
            surname: data.user_surname ?? '',
            login: data.user_login ?? '',
            group: data.user_group ?? 'user',
            address: data.address ?? '',
            number: data.user_number ?? '',
        });
    }
    text('#editOrderAddress', data.address);
    label('#editOrderAssemblyName', data.assembly_name);
    modal.querySelector('#editOrderAssemblyBtn').href = '/assembly.php?id=' + encodeURIComponent(data.assembly_id);
    text('#editOrderPrice', data.assembly_price);

    // контакты одной строкой: телефон и почта, что заполнено
    var contacts = [data.user_number, data.user_email].filter(Boolean).join(' · ');
    text('#editOrderContacts', contacts);
    text('#editOrderCreated', data.created_at);

    var statusSel = modal.querySelector('#editOrderStatusSelect');
    if (statusSel) {
        var found = Array.from(statusSel.options).some(function(o) { return o.value === data.status; });
        statusSel.value = found ? data.status : 'Обрабатывается';
    }

    modal.showModal();
});

// 3.7-h-2: «Удалить» из модалки пользователя — отдельное подтверждение
document.addEventListener('click', function(e) {
    var delBtn = e.target.closest('[data-action="open-delete-user-modal"]');
    if (!delBtn) return;
    e.preventDefault();

    var userModal = document.getElementById('editUserModal');
    var modal = document.getElementById('deleteUserModal');
    if (!modal) return;

    modal.querySelector('#deleteUserId').value = delBtn.dataset.id;
    modal.querySelector('#deleteUserName').textContent = delBtn.dataset.name;

    if (userModal && userModal.open) userModal.close();
    modal.showModal();
});

// 3.7-f-2: «Удалить» из edit-модалки — закрываем её и открываем подтверждение
document.addEventListener('click', function(e) {
    var delBtn = e.target.closest('[data-action="open-delete-modal"]');
    if (!delBtn) return;
    e.preventDefault();

    var editModal = document.getElementById('addComponentModal');
    var modal = document.getElementById('deleteComponentModal');
    if (!modal) return;

    modal.querySelector('#deleteComponentId').value = delBtn.dataset.id;
    modal.querySelector('#deleteComponentName').textContent = delBtn.dataset.name;

    if (editModal && editModal.open) editModal.close();
    modal.showModal();
});

// 3.7-d: «+ Добавить» после edit — выйти из режима редактирования
document.addEventListener('click', function(e) {
    var openBtn = e.target.closest('[data-action="open-modal"]');
    if (!openBtn) return;
    if (openBtn.dataset.modal !== 'addComponentModal') return;

    var modal = document.getElementById('addComponentModal');
    modal.querySelector('#modalTitle').textContent = 'Добавить комплектующий';
    modal.querySelector('#editComponentId').value = '';
    modal.querySelector('#modalSubmit').textContent = 'Добавить';
    modal.querySelector('form').reset();

    // 3.7-f-2: в add-режиме удаления нет
    var delBtn = modal.querySelector('#modalDeleteBtn');
    if (delBtn) delBtn.hidden = true;

    var catSelect = modal.querySelector('[name="cat"]');
    if (catSelect) {
        catSelect.dispatchEvent(new Event('change', { bubbles: true }));
    }
    // showModal вызывается в существующем обработчике open-modal
});

