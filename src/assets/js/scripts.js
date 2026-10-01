
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

// 3.7-c: показ/скрытие групп полей модалки по выбранной категории.
// Группы (.field-group) описаны атрибутом data-cat — списком category_id.
document.addEventListener('change', function(e) {
    if (e.target.matches('#addComponentModal select[name="cat"]')) {
        var catId = e.target.value;
        var modal = e.target.closest('dialog');
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
    setVal('form_factor', data.form_factor);
    setVal('rpm', data.rpm);
    setVal('cooler_type', data.cooler_type);

    // 3.7-f-2: кнопка удаления видима только в edit-режиме
    var delBtn = modal.querySelector('#modalDeleteBtn');
    if (delBtn) {
        delBtn.hidden = false;
        delBtn.dataset.id = data.id;
        delBtn.dataset.name = data.name ?? '';
    }

    // change на категории — покажет группы, релевантные этой категории
    var catSelect = modal.querySelector('[name="cat"]');
    if (catSelect) {
        catSelect.dispatchEvent(new Event('change', { bubbles: true }));
    }

    modal.showModal();
});

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
        var userModal = document.getElementById('editUserModal');
        if (!userModal) return;

        var uSet = function(id, value) {
            var el = userModal.querySelector(id);
            if (el) el.value = value ?? '';
        };
        uSet('#editUserId', data.id);
        // #editUserLogin — span в заголовке, не input
        var loginSpan = userModal.querySelector('#editUserLogin');
        if (loginSpan) loginSpan.textContent = data.login;
        uSet('#editUserNameRO', data.login);
        uSet('#editUserName', data.name);
        uSet('#editUserAddress', data.address);
        uSet('#editUserNumber', data.number);
        uSet('#editUserGroup', data.group);

        var delBtn = userModal.querySelector('#editUserDeleteBtn');
        if (delBtn) {
            delBtn.dataset.id = data.id;
            delBtn.dataset.name = data.login;
        }
        userModal.showModal();
        return;
    }

    var modal = document.getElementById('editOrderModal');
    if (!modal) return;

    var text = function(id, value) {
        var el = modal.querySelector(id);
        if (el) el.textContent = (value === null || value === undefined || value === '')
            ? 'Не указан' : String(value);
    };

    modal.querySelector('#editOrderId').value = data.id;
    text('#editOrderNumber', data.id);
    text('#editOrderBuyer', data.buyer);
    text('#editOrderAddress', data.address);
    text('#editOrderAssembly', data.assembly_name);
    text('#editOrderPrice', data.assembly_price);

    // контакты одной строкой: телефон и почта, что заполнено
    var contacts = [data.user_number, data.user_email].filter(Boolean).join(' · ');
    text('#editOrderContacts', contacts);
    text('#editOrderCreated', data.created_at);

    var asmLink = modal.querySelector('#editOrderAssemblyLink');
    if (asmLink) {
        asmLink.href = '/assembly.php?id=' + encodeURIComponent(data.assembly_id);
    }

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

