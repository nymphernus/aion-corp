const { test } = require('node:test');
const assert = require('node:assert');
const path = require('node:path');

// core.js не модуль: он вешает AionCore на window (браузер) или
// globalThis (Node). В тестах эмулируем window, как описано в шапке файла.
global.window = {};
require(path.resolve(__dirname, '../../assets/js/core.js'));
const { readParts, faviconNoticeText, computeTotal } = global.window.AionCore;

// --- readParts ---

test('readParts: парсит "1:5,2:44"', () => {
    const result = readParts('1:5,2:44');
    assert.strictEqual(result[1], 5);
    assert.strictEqual(result[2], 44);
});

test('readParts: пустая строка -> пустой объект', () => {
    assert.deepStrictEqual(readParts(''), {});
});

test('readParts: пробелы игнорируются', () => {
    const result = readParts(' 1 : 5 , 2 : 44 ');
    assert.strictEqual(result[1], 5);
    assert.strictEqual(result[2], 44);
});

test('readParts: нечисловые значения отбрасываются', () => {
    const result = readParts('1:abc,2:44');
    assert.strictEqual(result[1], undefined);
    assert.strictEqual(result[2], 44);
});

test('readParts: битые пары без двоеточия отбрасываются', () => {
    const result = readParts('5,1:5,::,2:');
    assert.deepStrictEqual(result, { 1: 5 });
});

// --- faviconNoticeText ---

test('faviconNoticeText: неподдерживаемая буква -> подсказка про загрузку', () => {
    const text = faviconNoticeText('favicon_letter_unsupported', 'Щ');
    assert.ok(text.includes('Щ'));
    assert.ok(text.includes('загрузите свою картинку'));
});

test('faviconNoticeText: пустая буква -> «Введите букву»', () => {
    assert.strictEqual(faviconNoticeText('favicon_letter_empty', ''), 'Введите букву.');
});

test('faviconNoticeText: неизвестный код -> общая ошибка', () => {
    assert.strictEqual(faviconNoticeText('anything_else', 'А'), 'Не удалось построить иконку.');
});

// --- computeTotal ---

const SLOTS = {
    ssd_2_id: {
        label: 'Второй SSD',
        items: [
            { component_id: 7, component_name: 'SSD A', component_price: '3000' },
            { component_id: 8, component_name: 'SSD B', component_price: '4500' }
        ]
    },
    hdd_id: {
        label: 'Жёсткий диск',
        items: [
            { component_id: 9, component_name: 'HDD A', component_price: '2000' }
        ]
    }
};

test('computeTotal: пустое состояние -> только базовая цена', () => {
    assert.strictEqual(computeTotal({ ssd_2_id: 0, hdd_id: 0 }, SLOTS, 50000), 50000);
});

test('computeTotal: один выбранный слот', () => {
    assert.strictEqual(computeTotal({ ssd_2_id: 7, hdd_id: 0 }, SLOTS, 50000), 53000);
});

test('computeTotal: два выбранных слота', () => {
    assert.strictEqual(computeTotal({ ssd_2_id: 8, hdd_id: 9 }, SLOTS, 50000), 56500);
});

test('computeTotal: компонент, которого нет в slots, пропускается', () => {
    // id 99 исчез из выдачи (закончился) - цена не должна вырасти
    assert.strictEqual(computeTotal({ ssd_2_id: 99, hdd_id: 0 }, SLOTS, 50000), 50000);
});

test('computeTotal: битая базовая цена -> 0 в основе', () => {
    assert.strictEqual(computeTotal({ ssd_2_id: 0, hdd_id: 0 }, SLOTS, 'abc'), 0);
});

test('computeTotal: слот отсутствует в данных целиком', () => {
    assert.strictEqual(computeTotal({ ssd_2_id: 7 }, {}, 1000), 1000);
});

test('computeTotal: результат - целое число', () => {
    const total = computeTotal({ ssd_2_id: 7, hdd_id: 9 }, SLOTS, 50000);
    assert.ok(Number.isInteger(total));
});
