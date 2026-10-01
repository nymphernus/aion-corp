
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
    if (action === 'switch') {
        e.preventDefault();
        switchReg(el.getAttribute('data-a'), el.getAttribute('data-b'));
    } else if (action === 'show') {
        e.preventDefault();
        show(el.getAttribute('data-id'));
    } else if (action === 'hide') {
        e.preventDefault();
        hide(el.getAttribute('data-id'));
    }
});

