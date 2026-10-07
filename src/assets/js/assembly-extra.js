/*
 * Допкомпоненты пользовательской сборки на /assembly.php.
 *
 * Отдельный файл, а не инлайн-скрипт: политика безопасности в .htaccess
 * запрещает script-src 'self' без nonce, и inline-код просто не выполнился
 * бы. Все числа приходят через data-атрибуты секции - ровно потому же.
 *
 * Файл подключается на всех сборках, но весь код под условием: у базовой
 * секции #extraComponents на странице нет, и обработчик выходит сразу.
 */
(function () {
    'use strict';

    var section = document.getElementById('extraComponents');
    if (!section) {
        return;
    }

    var selects = section.querySelectorAll('.extra-select');
    if (!selects.length) {
        return;
    }

    /* База = нынешняя цена сборки минус нынешние допы. Сервер считает
       то же дельтой, поэтому цифра на экране и цена в базе сходятся. */
    var base = parseInt(section.dataset.basePrice, 10) || 0;
    var priceEl = document.getElementById('buildPrice');

    /* Разделитель тысяч берём из Intl, а не собираем пробелом сами: в
       шаблоне цену печатает number_format, и обычный пробел отличался бы
       от серверного на вид. */
    var fmt = new Intl.NumberFormat('ru-RU');

    function selectedPrice(select) {
        var opt = select.options[select.selectedIndex];
        if (!opt) {
            return 0;
        }
        return parseInt(opt.dataset.price, 10) || 0;
    }

    function recalc() {
        var total = base;
        for (var i = 0; i < selects.length; i++) {
            total += selectedPrice(selects[i]);
        }
        if (priceEl) {
            priceEl.textContent = fmt.format(total) + ' ₽';
        }

        /* Скрытые поля во всех трёх формах синхронизируются здесь: до
           отправки. querySelectorAll, а не getElementById - у форм
           одинаковые имена полей, и id у них не может быть общим. */
        var fields = document.querySelectorAll(
            'input[name="extra_ssd_2_id"], input[name="extra_hdd_id"]'
        );
        for (var j = 0; j < fields.length; j++) {
            var src = document.getElementById(
                fields[j].name === 'extra_ssd_2_id' ? 'extraSsd2' : 'extraHdd'
            );
            if (src) {
                fields[j].value = src.value;
            }
        }
    }

    for (var k = 0; k < selects.length; k++) {
        selects[k].addEventListener('change', recalc);
    }

    recalc();
}());