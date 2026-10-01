
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

