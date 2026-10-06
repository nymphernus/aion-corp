
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
    } else if (action === 'toggle-password') {
        // показать/скрыть пароль.
        //
        // Кнопка объявлена type="button", поэтому submit не происходит и
        // полагаться только на preventDefault не приходится: на старых iOS
        // Safari он не всегда срабатывает, и форма уходит раньше.
        e.preventDefault();
        var pwField = el.closest ? el.closest('.password-field') : null;
        if (!pwField) return;
        var pwInput = pwField.querySelector('input');
        if (!pwInput) return;

        // Каретка запоминается до смены типа: смена type сбрасывает
        // selectionStart, и курсор уехал бы в конец, копия значения
        // сдвинулась бы вправо.
        var caret = null;
        try {
            caret = pwInput.selectionStart;
        } catch (err) {
            // у поля нет выделения (например disabled) - пропускаем
        }

        var visible = pwField.classList.toggle('is-visible');
        pwInput.type = visible ? 'text' : 'password';
        var pwLabel = visible ? 'Скрыть пароль' : 'Показать пароль';
        el.setAttribute('aria-label', pwLabel);
        el.setAttribute('title', pwLabel);

        // Фокус остаётся в поле, иначе после клика он ушёл бы на кнопку
        pwInput.focus();
        if (caret !== null) {
            try {
                pwInput.setSelectionRange(caret, caret);
            } catch (err) {
                // не все поля пароля поддерживают выделение
            }
        }
    }
});

// клик по кнопке не должен забирать фокус у поля до того, как
// отработает обработчик выше. Без этого каретка мигает: поле теряет
// фокус на кнопку и тут же получает его обратно.
document.addEventListener('mousedown', function(e) {
    var pwToggle = e.target.closest ? e.target.closest('.password-toggle') : null;
    if (pwToggle) e.preventDefault();
});

// пересборка селекта форм-фактора под категорию.
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

// показ/скрытие групп полей модалки по выбранной категории.
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

// 2: открытие edit-модалки кликом по строке таблицы.
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

    // сначала пересборка селекта форм-фактора под категорию,
    // и только потом значение - установка .value для отсутствующей опции
    // обнуляет select, и прежнее значение (например M.2) потерялось бы
    var catSelect = modal.querySelector('[name="cat"]');
    if (catSelect) {
        catSelect.dispatchEvent(new Event('change', { bubbles: true }));
    }
    // после пересборки возвращаем значение из БД: если его нет в
    // списке категории, оно добавляется как «прежнее значение»
    rebuildFormFactors(modal, catSelect ? catSelect.value : '', data.form_factor ?? '');

    // кнопка удаления видима только в edit-режиме
    var delBtn = modal.querySelector('#modalDeleteBtn');
    if (delBtn) {
        delBtn.hidden = false;
        delBtn.dataset.id = data.id;
        delBtn.dataset.name = data.name ?? '';
    }

    // --- Изображение корпуса : превью в edit-режиме.
    // Картинка-кнопка  переключается классом has-image
    var imgPreview = document.getElementById('imagePreviewImg');
    var trigger = document.getElementById('imagePickerTrigger');
    
    if (data.image) {
        trigger.classList.add('has-image');
        imgPreview.src = data.image;
    } else {
        trigger.classList.remove('has-image');
        imgPreview.src = '';
    }
    // скрытый выбор сбрасываем: картинка приходит от текущей записи, а не
    // от прошлого выбора в пикере
    var selUrl = document.getElementById('imageSelectedUrl');
    if (selUrl) selUrl.value = '';
    document.getElementById('removeImageFlag').value = '0';
    document.getElementById('imageFileInput').value = '';

    // change на категории уже отправлен выше: он и перестроил селект
    // форм-фактора, и показал релевантные группы полей

    modal.showModal();
});

// 3-10: фильтр «Сокет» показывается только для CPU / платы /
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

// строка с data-href переходит по ссылке.
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

// клик по фону вокруг открытой модалки закрывает её.
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

// единый открыватель модалки пользователя.
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
    // фамилия не обязательна, пустое значение тоже валидно
    uSet('#editUserSurname', data.surname);
    // дата регистрации только для чтения, в POST не уходит
    uSet('#editUserRegdate', data.regdate);
    // адрес разбит на поля. Значения кладутся как есть, с маркерами
    // вроде «ул.» поле нормализуется при сохранении, поэтому подсказки в
    // placeholder об этом напоминают.
    uSet('#editUserPostalCode', data.postal_code);
    uSet('#editUserRegion', data.region);
    uSet('#editUserCity', data.city);
    uSet('#editUserStreet', data.street);
    uSet('#editUserHouse', data.house);
    uSet('#editUserApartment', data.apartment);
    // legacy-строка user_address в модалку больше не выводится -
    // шесть адресных полей единственный источник правды
    uSet('#editUserNumber', data.number);
    uSet('#editUserGroup', data.group);

    // 7: блок верификации. Идёт после заполнения полей, потому что сам
    // показывает email и телефон, а не редактирует их.
    fillVerificationBlock(modal, data);

    var delBtn = modal.querySelector('#editUserDeleteBtn');
    if (delBtn) {
        delBtn.dataset.id = data.id;
        delBtn.dataset.name = data.login ?? '';
    }
    modal.showModal();
}

// из модалки заказа — кнопка покупателя открывает его модалку.
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

// открыватель read-only модалки заказа в профиле.
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
    // ссылка на саму сборку открывается в новой вкладке
    var asmLink = modal.querySelector('#userOrderAssemblyLink');
    if (asmLink) {
        asmLink.href = '/assembly.php?id=' + encodeURIComponent(data.assembly_id ?? '');
    }
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

// клик по строке заказа — модалка с деталями и сменой статуса
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

    // строка пользователя открывает свою модалку
    if (data.modal === 'user') {
        openUserModal(data);
        return;
    }

    // строка заказа в профиле пользователя — только просмотр
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

    // покупатель — кнопка (откроет модалку пользователя в f-4),
    // сборка — ссылка-кнопка в новой вкладке
    var label = function(id, value) {
        var el = modal.querySelector(id);
        if (el) el.textContent = (value === null || value === undefined || value === '')
            ? 'Не указан' : String(value);
    };

    modal.querySelector('#editOrderId').value = data.id;
    text('#editOrderNumber', data.id);
    label('#editOrderBuyerName', data.buyer);
    // кладём данные покупателя прямо на кнопку, чтобы переход
    // в его модалку работал без AJAX
    var buyerBtn = modal.querySelector('#editOrderBuyerBtn');
    if (buyerBtn) {
        buyerBtn.dataset.userId = data.user_id ?? '';
        buyerBtn.dataset.user = JSON.stringify({
            id: data.user_id ?? '',
            name: data.user_name ?? data.buyer ?? '',
            // фамилия покупателя нужна его же модалке
            surname: data.user_surname ?? '',
            login: data.user_login ?? '',
            group: data.user_group ?? 'user',
            number: data.user_number ?? '',
            // разбитый адрес покупателя. legacy-строка
            // address сюда больше не передаётся - её нигде не читают
            postal_code: data.user_postal_code ?? '',
            region: data.user_region ?? '',
            city: data.user_city ?? '',
            street: data.user_street ?? '',
            house: data.user_house ?? '',
            apartment: data.user_apartment ?? '',
            // 7: email покупателя в модалку не передавался вообще, хотя в
            // data-row он есть. Из-за этого блок верификации при переходе
            // из заказа показывал «Не указан» у пользователя с заполненным
            // email, и админ не мог подтвердить контакт
            email: data.user_email ?? '',
            // 7: флаги верификации, иначе кнопка «Подтвердить» всегда была
            // бы disabled на этом пути
            email_verified: data.user_email_verified ?? 0,
            email_verification_requested: data.user_email_verification_requested ?? 0,
            phone_verified: data.user_phone_verified ?? 0,
            phone_verification_requested: data.user_phone_verification_requested ?? 0,
            // без этого ключа поле «Дата регистрации» в модалке
            // покупателя оставалось пустым при переходе из заказа
            regdate: data.user_regdate ?? '',
        });
    }
    // адрес собирается из шести полей.
    // fallback на legacy user_address убран - если новых полей
    // нет, адрес считается незаполненным.
    var addrParts = [
        data.user_postal_code,
        data.user_region,
        data.user_city,
        data.user_street,
        data.user_house ? 'д. ' + data.user_house : '',
        data.user_apartment ? 'кв. ' + data.user_apartment : '',
    ].filter(Boolean);
    text('#editOrderAddress', addrParts.join(', '));
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

// единое подтверждение действия.
//
// Колбэк хранится в переменной, кнопки слушает делегированный
// обработчик ниже. Планировалось клонировать кнопку «Подтвердить»
// ради отвязки прошлых слушателей - не нужно: слушатель один, он на
// документе, и подменять ему нечего.
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
        return;
    }

    // выход из аккаунта требует подтверждения
    if (e.target.closest('[data-action="logout-confirm"]')) {
        e.preventDefault();
        window.confirmAction('Выйти из аккаунта?', 'Придётся снова вводить логин и пароль.', function () {
            window.location.href = '/validation/exit.php';
        });
        return;
    }

    // удаление заказа из модалки заказа
    var orderDel = e.target.closest('[data-action="delete-order"]');
    if (orderDel) {
        e.preventDefault();
        var orderModal = document.getElementById('editOrderModal');
        var form = document.getElementById('deleteOrderForm');
        if (!form) return;
        // id берём из скрытого поля модалки, а не из текста заголовка:
        // в форме должен лежать именно order_id, иначе DELETE уйдёт с 0
        var orderIdField = orderModal ? orderModal.querySelector('#editOrderId') : null;
        form.querySelector('#deleteOrderId').value = orderIdField ? orderIdField.value : '';
        var orderNumber = orderModal ? orderModal.querySelector('#editOrderNumber').textContent : '';
        window.confirmAction(
            'Удалить заказ?',
            'Заказ №' + orderNumber + ' будет удалён безвозвратно.',
            function () { form.submit(); }
        );
        return;
    }

    // удаление из избранного, форма лежит в строке таблицы
    var favDel = e.target.closest('[data-action="delete-favorite"]');
    if (favDel) {
        e.preventDefault();
        var favForm = favDel.closest('form');
        if (!favForm) return;
        window.confirmAction(
            'Убрать из избранного?',
            'Сборка «' + (favDel.dataset.name || '') + '» исчезнет из избранного.',
            function () { favForm.submit(); }
        );
        return;
    }

    // удаление пользователя вместо отдельной модалки
    var userDel = e.target.closest('[data-action="open-delete-user-modal"]');
    if (userDel) {
        e.preventDefault();
        var userModal = document.getElementById('editUserModal');
        var userForm = document.getElementById('deleteUserForm');
        if (!userForm) return;
        userForm.querySelector('#deleteUserId').value = userDel.dataset.id || '';
        if (userModal && userModal.open) userModal.close();
        window.confirmAction(
            'Удалить пользователя?',
            'Пользователь ' + (userDel.dataset.name || '') + ' будет удалён вместе с избранным.',
            function () { userForm.submit(); }
        );
        return;
    }

    // удаление комплектующего вместо отдельной модалки
    var compDel = e.target.closest('[data-action="open-delete-modal"]');
    if (compDel) {
        e.preventDefault();
        var editModal = document.getElementById('addComponentModal');
        var compForm = document.getElementById('deleteComponentForm');
        if (!compForm) return;
        compForm.querySelector('#deleteComponentId').value = compDel.dataset.id || '';
        if (editModal && editModal.open) editModal.close();
        window.confirmAction(
            'Удалить комплектующий?',
            '«' + (compDel.dataset.name || '') + '» будет удалён. Если он используется в сборках, удаление не пройдёт.',
            function () { compForm.submit(); }
        );
        return;
    }
});

// закрытие по Escape тоже сбрасывает колбэк, иначе он остался
// бы висеть до следующего открытия модалки
document.addEventListener('close', function (e) {
    if (e.target && e.target.id === 'confirmModal') confirmCallback = null;
}, true);

// «+ Добавить» после edit — выйти из режима редактирования
document.addEventListener('click', function(e) {
    var openBtn = e.target.closest('[data-action="open-modal"]');
    if (!openBtn) return;
    if (openBtn.dataset.modal !== 'addComponentModal') return;

    var modal = document.getElementById('addComponentModal');
    modal.querySelector('#modalTitle').textContent = 'Добавить комплектующий';
    modal.querySelector('#editComponentId').value = '';
    modal.querySelector('#modalSubmit').textContent = 'Добавить';
    modal.querySelector('form').reset();

    // в add-режиме удаления нет
    var delBtn = modal.querySelector('#modalDeleteBtn');
    if (delBtn) delBtn.hidden = true;

    var catSelect = modal.querySelector('[name="cat"]');
    if (catSelect) {
        catSelect.dispatchEvent(new Event('change', { bubbles: true }));
    }
    // showModal вызывается в существующем обработчике open-modal
});

    /* пресет задаёт бюджет.
       Раньше пресет переключал и приоритет «Что важнее», но приоритет
       убран: сборка собирается по бюджету, а распределение всегда
       сбалансированное. Клик по пресету вписывает сумму в поле.

       При ручном вводе суммы подсветка с пресетов снимается, если
       сумма не совпала. */
    document.addEventListener('click', function(e) {
        const preset = e.target.closest('.cfg-preset');
        if (!preset) return;

        const budget = preset.dataset.budget;
        if (!budget) return;

        document.querySelectorAll('.cfg-preset')
            .forEach(p => p.classList.remove('is-active'));
        preset.classList.add('is-active');

        const input = document.getElementById('cfgPrice');
        if (input) {
            input.value = budget;
            input.focus();
        }
    });

document.addEventListener('input', function(e) {
    if (e.target.id !== 'cfgPrice') return;
    const value = e.target.value;
    document.querySelectorAll('.cfg-preset').forEach(p => {
        p.classList.toggle('is-active', p.dataset.budget === value);
    });
});

/* 7: слайдер готовых сборок.
   На узком экране карточки не переносятся, а листаются по горизонтали.
   Стрелки по бокам появляются только когда есть куда листать: в начале
   скрыта «назад», в конце - «вперёд». На широком экране всё это скрыто
   через CSS (display: none у .builds-slider__nav), а updateNav решает
   по факту переполнения.

   Шаг листания берём у реальной карточки и зазора из CSS, а не задаём
   числом: ширина карточки 320px, но на узком экране 280px, и жёстко
   прописанный шаг промахивался бы на половину карточки. */
(function() {
    const slider = document.getElementById('buildsSlider');
    if (!slider) return;

    const wrap = slider.closest('.builds-slider-container');
    if (!wrap) return;

    const prev = wrap.querySelector('[data-direction="-1"]');
    const next = wrap.querySelector('[data-direction="1"]');

    // Погрешность в несколько пикселей: при округлении scrollLeft
    // браузер даёт 90.00001 вместо 90, и стрелка «вперёд» мигала бы
    // на последней карточке.
    const EPS = 8;

    function updateNav() {
        if (!prev || !next) return;

        const maxScroll = slider.scrollWidth - slider.clientWidth;
        if (maxScroll <= EPS) {
            // Переполнения нет: листать некуда, стрелки не нужны.
            prev.hidden = true;
            next.hidden = true;
            return;
        }

        /* Конец ленты считаем не как scrollWidth - clientWidth, а как
           последнюю позицию, до которой лента реально доедет.
           scroll-snap-type: mandatory не даёт остановиться между
           карточками, поэтому на узком экране scrollLeft упирается в
           начало последней карточки (замер на 375px: maxScroll 621px,
           а лента встаёт на 600px). По scrollWidth стрелка «вперёд»
           считалась бы видимой и на последней карточке, и клик по ней
           не двигал бы ленту - то есть стрелка врёт. */
        const cards = slider.querySelectorAll('.build');
        let reach = maxScroll;
        if (cards.length) {
            const last = cards[cards.length - 1];
            // offsetLeft отсчитывается от прокручиваемого контейнера,
            // если тот позиционирован, иначе - от ближайшего
            // позиционированного предка, и значение будет чужим.
            const origin = slider.getBoundingClientRect().left;
            const lastLeft = last.getBoundingClientRect().left - origin
                + slider.scrollLeft;
            if (lastLeft < reach) reach = lastLeft;
        }

        const left = slider.scrollLeft;
        prev.hidden = left <= EPS;
        next.hidden = left >= reach - EPS;
    }

    function scrollBy(direction) {
        const card = slider.querySelector('.build');
        if (!card) return;

        // зазор берём из computedStyle: в CSS он 28px, и дублировать
        // число здесь значило бы забыть про него при правке оформления
        const styles = getComputedStyle(slider);
        const gap = parseFloat(styles.columnGap || styles.gap) || 0;

        slider.scrollBy({
            left: (card.offsetWidth + gap) * direction,
            behavior: 'smooth'
        });
    }

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('[data-action="scroll-builds"]');
        if (!btn) return;
        // Стрелка стоит рядом с карточками, но внутри их общей обёртки.
        // Без preventDefault кнопка отдала бы форму, если бы оказалась
        // внутри неё.
        e.preventDefault();
        scrollBy(parseInt(btn.dataset.direction, 10) || 1);
    });

    slider.addEventListener('scroll', updateNav, { passive: true });
    window.addEventListener('resize', updateNav);

    /* Первый замер делаем сразу и повторяем в requestAnimationFrame.
       Одного rAF мало по двум причинам. Если картинки в карточках
       грузятся позже скрипта, ширина ленты на прямом вызове ещё
       старая, и стрелки скрылись бы зря. И в скрытой вкладке rAF не
       вызывается вовсе, а стрелки остались бы скрытыми до первого
       клика по странице - на узком экране их бы нечем было открыть. */
    updateNav();
    requestAnimationFrame(updateNav);
})();

// 7: блок «Верификация» в модалке пользователя.
//
// Четыре состояния на контакт, по ТЗ:
//   1. контакта нет - значение «Не указан» серым курсивом, бейдж пустой,
//      кнопка скрыта: подтверждать нечего;
//   2. контакт есть, не подтверждён, заявки не было - бейдж серый
//      «Не подтверждён», кнопка видна, но disabled: ждём заявки;
//   3. заявка есть - бейдж жёлтый «Заявка от пользователя», кнопка
//      активна;
//   4. подтверждён - бейдж зелёный, кнопка скрыта.
//
// Скрытие кнопки это hidden, а не disabled: при пустом контакте
// disabled-кнопка с подписью «Подтвердить» выглядела бы как кнопка,
// которая сломалась.
//
// Значение disabled сбрасывается в каждой ветке явно. Иначе кнопка,
// побывав активной для одного пользователя, осталась бы активной для
// следующего, у которого заявки нет: модалка открывается много раз
// подряд, а состояние кнопки живёт в DOM.
function fillVerificationBlock(modal, data) {
    var fill = function (valueId, statusId, btnId, contact, verified, requested) {
        var valueEl = modal.querySelector(valueId);
        var statusEl = modal.querySelector(statusId);
        var btn = modal.querySelector(btnId);
        if (!valueEl || !statusEl || !btn) return;

        btn.disabled = false;

        if (!contact) {
            valueEl.textContent = 'Не указан';
            valueEl.classList.add('is-muted');
            statusEl.className = 'badge';
            statusEl.textContent = '';
            btn.hidden = true;
            return;
        }

        valueEl.textContent = contact;
        valueEl.classList.remove('is-muted');
        btn.hidden = false;

        // Двойное равенство осознанно: из data-row значения приходят
        // числами, но если формат поменяется, 0 и "0" должны читаться
        // одинаково, а не молча давать ложное «не подтверждён».
        if (Number(verified) === 1) {
            statusEl.className = 'badge badge--success';
            statusEl.textContent = 'Подтверждён';
            btn.hidden = true;
        } else if (Number(requested) === 1) {
            statusEl.className = 'badge badge--warning';
            statusEl.textContent = 'Заявка от пользователя';
            btn.disabled = false;
        } else {
            statusEl.className = 'badge';
            statusEl.textContent = 'Не подтверждён';
            btn.disabled = true;
        }
    };

    // у order-пути ключи с префиксом user_, у таблицы
    // пользователей - без него. Поэтому читаем оба.
    var pick = function (shortKey, longKey) {
        var v = data[shortKey];
        if (v === undefined || v === null || v === '') v = data[longKey];
        return Number(v) || 0;
    };

    fill(
        '#adminUserEmail', '#adminUserEmailStatus', '#adminApproveEmailBtn',
        data.email, pick('email_verified', 'user_email_verified'),
        pick('email_verification_requested', 'user_email_verification_requested')
    );
    fill(
        '#adminUserPhone', '#adminUserPhoneStatus', '#adminApprovePhoneBtn',
        data.number, pick('phone_verified', 'user_phone_verified'),
        pick('phone_verification_requested', 'user_phone_verification_requested')
    );
}

// 7: подтверждение верификации администратором.
//
// Формы approveEmailForm и approvePhoneForm лежат после dialog, потому
// что вложенных форм не бывает. Отправляются через form.submit().
//
// Второй уровень защиты - на сервере: обработчик требует
// email_verification_requested = 1 в WHERE. Проверка disabled здесь
// нужна не для безопасности, а чтобы не отправлять заведомо
// бесполезный POST: сервер всё равно сделал бы 0 строк.
document.addEventListener('click', function(e) {
    var approve = function (action, formId) {
        var btn = e.target.closest('[data-action="' + action + '"]');
        if (!btn) return false;
        if (btn.disabled) return true;
        var userIdField = document.getElementById('editUserId');
        var userId = userIdField ? userIdField.value : '';
        if (!userId) return true;
        var form = document.getElementById(formId);
        if (!form) return true;
        form.querySelector('[name="userId"]').value = userId;
        form.submit();
        return true;
    };

    if (approve('approve-email', 'approveEmailForm')) return;
    approve('approve-phone', 'approvePhoneForm');
});

// 8: плавный скролл по якорям без изменения адресной строки.
//
// Раньше ссылки шапки вели как обычные: браузер дописывал #assembly в
// адрес и переходил на якорь. Два неприятных следствия: адресная строка
// меняется (и ссылку можно скопировать, но она открывает страницу с
// позицией, а не секцию), и переход мгновенный, мимо блока.
//
// Здесь перехватываем клик и скроллим сами. Адрес не трогаем.
//
// Что важно в реализации:
//
// Слушатель на document, а не на ссылках. Ссылки шапки есть на каждой
// странице, и навешивать обработчик в разметке - значит дублировать его
// в каждом include. Делегирование работает и для ссылок, добавленных
// позже.
//
// Только на главной. С неглавной ссылка должна увести на / - там якоря
// ещё нет, перехват обошёл бы переход и скролл был бы никуда.
//
// preventDefault только если цель найдена. Если блока с таким id на
// странице нет, ссылка остаётся обычной: лучше переход с битым якорем,
// чем молчаливое ничего.
//
// behavior: 'smooth' не мешает prefers-reduced-motion: браузеры с этим
// включённым режимом решают сами, и отдельная проверка тут была бы
// дублированием их логики.
document.addEventListener('click', function(e) {
    // Ctrl/Cmd/Shift-клик и средняя кнопка - это «открыть в новой
    // вкладке», перехватывать нельзя: пользователь явно попросил
    // именно об этом.
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;

    var link = e.target.closest('a[href^="#"], a[href^="/#"]');
    if (!link) return;

    var href = link.getAttribute('href');
    // /#assembly -> #assembly. На главной это тот же якорь, но со
    // слешем, и getElementById('#assembly') вернул бы null.
    var hash = href.charAt(0) === '/' ? href.slice(1) : href;
    if (hash.charAt(0) !== '#') return;

    var isHome = window.location.pathname === '/'
              || window.location.pathname === '/index.php';
    if (!isHome) return;

    var target = document.getElementById(hash.slice(1));
    if (!target) return;

    e.preventDefault();

    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
});

// --- Изображение корпуса : превью-кнопка ---

// Применение выбранного источника к триггеру модалки компонента
function applyImageToTrigger(src) {
    const img = document.getElementById('imagePreviewImg');
    const trigger = document.getElementById('imagePickerTrigger');
    if (!img || !trigger) return;

    if (src) {
        trigger.classList.add('has-image');
        img.src = src;
    } else {
        trigger.classList.remove('has-image');
        img.src = '';
    }
}

// Файл выбран в модалке-пикере.
//
// ГЛАВНОЕ: #pickerFileInput живёт внутри #filePickerModal, а форма
// компонента заканчивается ДО этого dialog - пикер в неё не вложен.
// Поэтому выбранный файл сам по себе не отправится на сервер: $_FILES
// останется пустым, и картинка после сохранения пропадёт, хотя в окне
// её видно. Лечится переносом файла в #imageFileInput, который внутри
// формы, через DataTransfer.
function transferFileToFormInput(file) {
    var target = document.getElementById('imageFileInput');
    if (!target) return false;
    // input.files только для чтения, но DataTransfer позволяет собрать
    // новый FileList и подставить его.
    var dt = new DataTransfer();
    dt.items.add(file);
    target.files = dt.files;
    return true;
}

document.addEventListener('change', function(e) {
    if (e.target.id !== 'pickerFileInput') return;
    var file = e.target.files[0];
    if (!file) return;

    // Файл переносится в форму ДО превью: если перенос не удался,
    // молча показывать картинку нельзя - она всё равно не сохранится.
    if (!transferFileToFormInput(file)) {
        alert('Не удалось подготовить файл к загрузке. Обновите страницу и попробуйте снова.');
        return;
    }

    var reader = new FileReader();
    reader.onload = function(ev) {
        applyImageToTrigger(ev.target.result);
        // Файл vs выбор из загруженных не должны срабатывать одновременно
        var selUrl = document.getElementById('imageSelectedUrl');
        if (selUrl) selUrl.value = '';
        document.getElementById('removeImageFlag').value = '0';
        // Модалка закрывается: файл уже принят, превью видно в триггере
        document.getElementById('filePickerModal').close();
    };
    reader.readAsDataURL(file);
});

// «Убрать изображение» из модалки выбора
document.addEventListener('click', function(e) {
    if (!e.target.closest('[data-action="remove-image-from-picker"]')) return;
    e.preventDefault();

    const input = document.getElementById('imageFileInput');
    const selUrl = document.getElementById('imageSelectedUrl');

    applyImageToTrigger(null);
    if (input) input.value = '';
    if (selUrl) selUrl.value = '';
    document.getElementById('removeImageFlag').value = '1';

    document.getElementById('filePickerModal').close();
});

// --- Файловые действия карточки: только удаление ---
// Архивации больше нет, поэтому скрытая форма несёт одно действие:
// deleteFile=1 и имя файла. submitFileDelete только подставляет имя.
function submitFileDelete(filename) {
    const form = document.getElementById('deleteFileForm');
    if (!form) return;
    form.querySelector('[name="filename"]').value = filename;
    form.submit();
}

// Удаление привязанного файла разрешено, но администратор должен увидеть,
// что именно сломается. Список привязок приходит в data-used-by;
// components.image при этом не обнуляется - решение остаётся за админом.
document.addEventListener('click', function(e) {
    const del = e.target.closest('[data-action="delete-file"]');
    if (!del) return;
    e.preventDefault();

    const filename = del.dataset.file;
    let usedBy = [];
    if (del.dataset.usedBy) {
        try {
            const parsed = JSON.parse(del.dataset.usedBy);
            if (Array.isArray(parsed)) usedBy = parsed;
        } catch (err) {
            usedBy = [];
        }
    }

    let message = 'Файл ' + filename + ' будет удалён с диска безвозвратно.';
    if (usedBy.length) {
        const names = usedBy
            .map(function(c) { return '• ' + c.name; })
            .join('\n');
        message += '\n\nВНИМАНИЕ: файл привязан к компонентам:\n' + names
            + '\n\nПривязки будут сняты, и эти корпуса останутся без картинки.';
    }

    confirmAction('Удалить файл?', message, function() {
        submitFileDelete(filename);
    });
});

// --- БЛОК 4: привязка файла к корпусу из файлового менеджера ---
let attachContext = { url: '' };

document.addEventListener('click', function(e) {
    const btn = e.target.closest('[data-action="attach-file"]');
    if (btn) {
        e.preventDefault();
        attachContext.url = btn.dataset.fileUrl;
        const modal = document.getElementById('attachCaseModal');
        if (modal) {
            // Список корпусов отрендерен сервером при загрузке страницы,
            // остаётся только сбросить поиск - иначе он останется от
            // прошлого открытия и покажет не всё.
            const search = document.getElementById('attachCaseSearch');
            if (search) search.value = '';
            filterAttachCases('');
            modal.showModal();
        }
        return;
    }

    const caseBtn = e.target.closest('[data-action="attach-file-confirm"]');
    if (caseBtn) {
        e.preventDefault();
        const form = document.getElementById('attachFileForm');
        form.querySelector('[name="fileUrl"]').value = attachContext.url;
        form.querySelector('[name="caseId"]').value = caseBtn.dataset.caseId;
        form.submit();
    }
});

// Поиск по названию корпуса в модалке привязки.
// Совпадение по подстроке без учёта регистра: data-search уже приведён
// к нижнему регистру на сервере. Отдельная строка «ничего не найдено»
// нужна, потому что пустой список сам по себе выглядит как «корпусов
// нет».
function filterAttachCases(query) {
    const list = document.getElementById('attachCasesList');
    if (!list) return;

    const q = (query || '').trim().toLowerCase();
    const rows = Array.prototype.slice.call(list.querySelectorAll('.attach-case-row'));
    let visible = 0;

    rows.forEach(function(row) {
        const name = row.dataset.search || '';
        const match = q === '' || name.indexOf(q) !== -1;
        row.hidden = !match;
        if (match) visible++;
    });

    let empty = document.querySelector('#attachCaseModal .attach-cases-empty');
    if (visible === 0) {
        if (!empty) {
            empty = document.createElement('div');
            empty.className = 'attach-cases-empty';
            empty.textContent = 'Ничего не найдено';
            list.parentNode.insertBefore(empty, list.nextSibling);
        }
    } else if (empty) {
        empty.remove();
    }
}

document.addEventListener('input', function(e) {
    if (!e.target || e.target.id !== 'attachCaseSearch') return;
    filterAttachCases(e.target.value);
});

// --- открытие модалки выбора картинкой-кнопкой ---
document.addEventListener('click', function(e) {
    if (!e.target.closest('[data-action="open-image-picker"]')) return;
    e.preventDefault();
    const picker = document.getElementById('filePickerModal');
    if (picker) picker.showModal();
});

// --- Загрузка пачки изображений на вкладке «Изображения» ---
//
// Файлы не отправляются обычной отправкой формы: у input[multiple]
// нельзя задать FileList из разметки, поэтому JS собирает FormData
// вручную. Пока запрос идёт, кнопка блокируется и показывает
// «Загрузка…» - иначе повторный клик отправил бы те же файлы дважды.
document.addEventListener('change', function(e) {
    var input = e.target;
    if (!input || input.id !== 'filesBatchInput') return;

    var files = input.files;
    if (!files || !files.length) return;

    var form = document.getElementById('batchUploadForm');
    if (!form) return;

    var fd = new FormData();
    fd.append('csrf_token', form.querySelector('[name="csrf_token"]').value);
    fd.append('return_params', form.querySelector('[name="return_params"]').value);
    fd.append('batchUpload', '1');
    for (var i = 0; i < files.length; i++) {
        fd.append('files[]', files[i]);
    }

    var label = input.closest('label');
    var original = label ? label.textContent.trim() : '';
    if (label) {
        label.style.pointerEvents = 'none';
        label.style.opacity = '0.6';
        label.textContent = 'Загрузка…';
    }

    fetch('/admin.php?tab=files', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
    }).then(function() {
        // Сервер сам редиректит с uploaded=N, поэтому reload уводит на
        // страницу с итогом и обновлёнными счётчиками.
        window.location.reload();
    }).catch(function() {
        window.location.reload();
    });
});

// Выбор файла в сетке модалки: превью + скрытое поле с URL
document.addEventListener('click', function(e) {
    const item = e.target.closest('[data-action="pick-file"]');
    if (!item) return;
    e.preventDefault();

    const url = item.dataset.url;

    applyImageToTrigger(url);

    // URL уходит в hidden-поле формы (иначе сервер о выборе не узнает)
    let hidden = document.getElementById('imageSelectedUrl');
    if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'image_selected_url';
        hidden.id = 'imageSelectedUrl';
        document.querySelector('#addComponentModal form').appendChild(hidden);
    }
    hidden.value = url;

    // Файловые вводы сбрасываем: файл vs выбор существующего не должны
    // срабатывать одновременно. Оба - и пикер, и форма.
    document.getElementById('pickerFileInput').value = '';
    document.getElementById('imageFileInput').value = '';
    document.getElementById('removeImageFlag').value = '0';

    document.getElementById('filePickerModal').close();
});
