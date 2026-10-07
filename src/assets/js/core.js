/*
 * Чистые функции для JS-логики магазина.
 *
 * Файл не трогает DOM: он проверяется на хосте через node:test
 * (node --test src/tests/js/). Подключается в partials/footer.php
 * раньше остальных скриптов - admin- и сборочные файлы зовут
 * функции отсюда, порядок загрузки обязателен.
 */
(function (global) {
    'use strict';

    /* Разобрать data-parts строки «категория:компонент» в объект.
     *
     * Формат «1:5,2:44» выбран из-за CSP: инлайн-скрипт с данными
     * заблокирован, а JSON в атрибуте пришлось бы разбирать этим же
     * разбором строки - только на две строки больше.
     *
     * Значения - числа, а не строки: ниже всё равно везде parseInt,
     * а нечисловая пара («1:abc») отбрасывается целиком - мусор в
     * селект не попадёт, слот честно уйдёт в «0». */
    function readParts(str) {
        var parts = {};
        if (!str) return parts;

        var pairs = String(str).split(',');
        for (var i = 0; i < pairs.length; i++) {
            var bits = pairs[i].split(':');
            if (bits.length !== 2) continue;

            var key = bits[0].trim();
            var raw = bits[1].trim();
            if (key === '' || raw === '') continue;

            var value = Number(raw);
            if (!Number.isFinite(value)) continue;

            parts[key] = value;
        }
        return parts;
    }

    /* Текст подсказки для favicon-генератора.
     * code - код ошибки от сервера, letter - введённая буква. */
    function faviconNoticeText(code, letter) {
        if (code === 'favicon_letter_unsupported') {
            return 'Генератор рисует одну букву. Для «' + letter + '» загрузите свою картинку.';
        }
        if (code === 'favicon_letter_empty') {
            return 'Введите букву.';
        }
        return 'Не удалось построить иконку.';
    }

    /* Итоговая цена сборки с допкомпонентами.
     *
     * state  - { ssd_2_id: N, hdd_id: N }: id выбранных компонентов,
     *          0 - слот пуст.
     * slots  - данные data-slots секции допов:
     *          { slot: { label: ..., items: [{ component_id, ...,
     *          component_price }] } }.
     * basePrice - цена сборки без допов.
     *
     * Компонент, которого нет в items, пропускается: так бывает,
     * когда он закончился и попал в базу до того, как его списали.
     * Молчаливый пропуск честнее карточки с ценой 0. */
    function computeTotal(state, slots, basePrice) {
        var total = parseInt(basePrice, 10) || 0;
        if (!state) return total;

        var keys = Object.keys(state);
        for (var i = 0; i < keys.length; i++) {
            var id = parseInt(state[keys[i]], 10) || 0;
            if (!id) continue;

            var data = slots ? slots[keys[i]] : null;
            var items = data && data.items ? data.items : [];

            for (var j = 0; j < items.length; j++) {
                if (parseInt(items[j].component_id, 10) === id) {
                    total += parseInt(items[j].component_price, 10) || 0;
                    break;
                }
            }
        }
        return total;
    }

    global.AionCore = {
        readParts: readParts,
        faviconNoticeText: faviconNoticeText,
        computeTotal: computeTotal
    };
}(typeof window !== 'undefined' ? window : globalThis));
