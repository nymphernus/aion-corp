
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

