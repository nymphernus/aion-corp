/**
 * Вкладка «Настройки сайта» — поиск адреса, снимок карты  и
 * предпросмотр картинок бренда 
 *
 * Схема работы с картой: админ вводит адрес, браузер спрашивает координаты у
 * Nominatim, показывает карту в модалке, из неё html2canvas делает PNG и
 * отправляет на сервер. На главной этот PNG показывается как <img> —
 * внешних запросов от посетителей нет вообще.
 *
 * Загружается только на /admin.php?tab=settings, сам Leaflet приходит
 * раньше этого файла.
 */

(function () {
    'use strict';

    var _map = null;
    var _tileLayer = null;

    // тайлы. Основной хост openstreetmap.org, при таймауте один раз
    // переключаемся на зеркало, и дальше работаем уже с ним: зеркало
    // хранится отдельно, а не в TILE_URL, иначе при следующей неудаче
    // основного хоста счётчик сбросился бы и мы по второму разу ждали бы
    // таймаут на заведомо мёртвом адресе.
    var TILE_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
    var TILE_MIRROR = 'https://tile.openstreetmap.de/{z}/{x}/{y}.png';
    var TILE_TIMEOUT_MS = 8000;
    var tileHostIsMirror = false;
    // Сколько ждём тайлы перед снимком. Тайлы грузятся асинхронно, и
    // фиксированная пауза означала бы пустые дыры в карте на медленной
    // сети, поэтому считаем реальные события загрузки, а ждём не дольше
    // предела.
    var TILE_TIMEOUT_MS = 8000;

    function byId(id) {
        return document.getElementById(id);
    }

    function status(text) {
        var el = byId('geocodeStatus');
        if (el) el.textContent = text;
    }

    /**
     * Дождаться, пока Leaflet перерисует контейнер после смены размера.
     * Без этого снимок может уйти в старых границах.
     *
     * Именно setTimeout, а не requestAnimationFrame: в неактивной вкладке
     * браузер rAF не вызывает вообще, и промис на нём ждал бы вечно -
     * проверено, снимок так и не делался. setTimeout в фоновой вкладке
     * замедляется, но срабатывает.
     */
    function afterMapSettled(map) {
        return new Promise(function (resolve) {
            if (map) {
                map.invalidateSize();
            }
            // invalidateSize ставит перерисовку в следующий кадр, поэтому
            // ждём с запасом
            setTimeout(resolve, 300);
        });
    }

    /**
     * Дождаться, пока в слое не останется тайлов в состоянии loading.
     *
     * Заранее узнать, сколько тайлов грузится, нельзя: Leaflet режет
     * карту по размерам контейнера и сам решает, сколько нужно. Поэтому
     * смотрим на внутреннее состояние каждого тайла - у загруженного
     * loaded = true, у ещё грузящегося loading = true. Плюс потолок по
     * времени, чтобы кнопка не висла, если тайл не придёт.
     */
    function waitForTiles(layer, timeoutMs, onTimeout) {
        return new Promise(function (resolve) {
            if (!layer || !layer._tiles) {
                setTimeout(resolve, 400);
                return;
            }

            var done = false;
            var timer = null;

            function finish(timedOut) {
                if (done) return;
                done = true;
                clearInterval(timer);
                if (timedOut && onTimeout) onTimeout();
                resolve();
            }

            function pendingCount() {
                var pending = 0;
                for (var key in layer._tiles) {
                    if (!Object.prototype.hasOwnProperty.call(layer._tiles, key)) continue;
                    var tile = layer._tiles[key];
                    if (tile && tile.loading) pending++;
                }
                return pending;
            }

            timer = setInterval(function () {
                if (pendingCount() === 0) finish(false);
            }, 120);

            setTimeout(function () { finish(true); }, timeoutMs || TILE_TIMEOUT_MS);
        });
    }

    /**
     * переключиться на зеркало тайлов и показать его на карте.
     *
     * Нужен на случай, если основной хост не отвечает: в этом городе идёт
     * перебор, и если и зеркало окажется недоступно, увидит это админ.
     * Состояние переключения хранится в tileHostIsMirror, чтобы следующий
     * поиск адреса не пытался снова ждать основной хост.
     */
    function switchToMirror() {
        tileHostIsMirror = true;
        if (_map && _tileLayer) {
            _tileLayer.setUrl(TILE_MIRROR);
        }
    }

    async function geocodeAndPreview() {
        if (typeof L === 'undefined') {
            status('Leaflet не загрузился');
            return;
        }

        var input = byId('mapAddressInput');
        var container = byId('mapPreview');
        if (!input || !container) return;

        var address = input.value.trim();
        if (!address) {
            status('Введите адрес');
            return;
        }

        status('Ищу адрес...');

        var url = 'https://nominatim.openstreetmap.org/search?' + new URLSearchParams({
            q: address,
            format: 'json',
            limit: '1'
        }).toString();

        try {
            var resp = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!resp.ok) {
                throw new Error('HTTP ' + resp.status);
            }

            var data = await resp.json();
            if (!data || !data.length) {
                status('Адрес не найден. Уточните формулировку.');
                return;
            }

            var lat = parseFloat(data[0].lat);
            var lng = parseFloat(data[0].lon);
            if (isNaN(lat) || isNaN(lng)) {
                status('Сервис вернул некорректные координаты');
                return;
            }

            status('Найдено: ' + data[0].display_name);

            // каждый новый поиск адреса начинается с основного хоста,
            // даже если прошлый ушёл на зеркало: вдруг блокировка сняли
            tileHostIsMirror = false;
            if (_map) {
                _map.remove();
                _map = null;
                _tileLayer = null;
            }
            // старые координаты из прошлого поиска не должны пережить новый
            delete container.dataset.lat;
            delete container.dataset.lng;

            _map = L.map(container, {
                center: [lat, lng],
                zoom: 15,
                dragging: false,
                touchZoom: false,
                doubleClickZoom: false,
                scrollWheelZoom: false,
                boxZoom: false,
                keyboard: false,
                zoomControl: false,
                // панель атрибуции убрана. В снимок попадал и флаг
                // Leaflet, и подпись поверх картинки. Условия OpenStreetMap
                // требуют указания источника на публикуемой карте, поэтому
                // подпись не выкинута совсем: её рисует composeSnapshot
                // прямо на canvas, см. функцию ниже.
                attributionControl: false
            });

            _tileLayer = L.tileLayer(tileHostIsMirror ? TILE_MIRROR : TILE_URL, {
                maxZoom: 19,
                // attributionControl выключен выше, подпись рисуется вручную
                attribution: '',
                // без crossOrigin у тайлов нет атрибута
                // crossorigin, и html2canvas не может загрузить их в своём
                // клоне - снимок выходил пустым: маркер и подпись рисовались,
                // а карты не было. Проверено на DOM: 9 тайлов из 9 без
                // атрибута. OSM отдаёт Access-Control-Allow-Origin: *,
                // поэтому атрибут нужен и запрос проходит.
                crossOrigin: true
            }).addTo(_map);

            L.marker([lat, lng]).addTo(_map);

            container.dataset.lat = String(lat);
            container.dataset.lng = String(lng);
        } catch (err) {
            status('Ошибка поиска: ' + err.message);
        }
    }

    /**
     * Собрать снимок карты на canvas вручную.
     *
     * с html2canvas это не работает, проверено дважды. Тайлы Leaflet
     * стоят через transform: translate3d(...), и библиотека копирует
     * раскладку как есть: снимок получался пустым - на нём были только
     * маркер и подпись. Обходной приём через onclone с переводом тайлов в
     * left/top тоже не помог, снимки выходили байт в байт одинаковыми.
     *
     * Поэтому карта собирается напрямую: у каждого <img> внутри контейнера
     * берём его положение на странице и рисуем в canvas по этому же
     * смещению относительно контейнера. Позиции тайлов и маркера при этом
     * сохраняются точно - рисуем то, что реально видит админ.
     *
     * Атрибуция OpenStreetMap рисуется текстом: условия использования
     * требуют указания источника на публикуемой карте, и она должна быть
     * частью снимка, а не только интерфейса.
     */
    function composeSnapshot(container) {
        // было 2, стало 1.5. Тайлы приходят квадратами по 256 пикселей,
        // при двойном масштабе они растягивались вдвое и раздували файл без
        // добавления деталей: на 1362x800 снимок весил 1 993 010 байт.
        // Множитель 1.5 даёт промежуточный размер - на обычном экране
        // разницы не видно, а на Retina картинка всё ещё мягче оригинала.
        var SCALE = 1.5;
        var box = container.getBoundingClientRect();

        var canvas = document.createElement('canvas');
        canvas.width = Math.round(box.width * SCALE);
        canvas.height = Math.round(box.height * SCALE);

        var ctx = canvas.getContext('2d');
        ctx.scale(SCALE, SCALE);
        ctx.fillStyle = '#F8FAFC';
        ctx.fillRect(0, 0, box.width, box.height);

        // тайлы и маркер - это <img>. У тайлов стоит crossorigin,
        // поэтому canvas не пачкается и toDataURL отработает
        var images = container.querySelectorAll('img');
        var drawn = 0;
        for (var i = 0; i < images.length; i++) {
            var img = images[i];
            if (!img.complete || !img.naturalWidth) continue;
            var r = img.getBoundingClientRect();
            if (r.width === 0 || r.height === 0) continue;
            try {
                ctx.drawImage(img, r.left - box.left, r.top - box.top, r.width, r.height);
                drawn++;
            } catch (err) {
                // битый кадр не должен срывать весь снимок
            }
        }

        // подпись в правом нижнем углу
        ctx.font = '11px Ubuntu, sans-serif';
        ctx.textAlign = 'right';
        ctx.textBaseline = 'bottom';
        var label = '© OpenStreetMap';
        var pad = 6;
        var w = ctx.measureText(label).width;
        var y = box.height - pad;
        ctx.fillStyle = 'rgba(255,255,255,0.85)';
        ctx.fillRect(box.width - w - pad * 2, y - 14, w + pad * 2, 18);
        ctx.fillStyle = '#334155';
        ctx.fillText(label, box.width - pad, y - 2);

        return { canvas: canvas, drawn: drawn };
    }

    async function saveMapSnapshot() {
        var container = byId('mapPreview');
        if (!container || !_map) {
            status('Сначала нажмите «Найти» и проверьте положение');
            return;
        }
        if (!container.dataset.lat || !container.dataset.lng) {
            status('Сначала нажмите «Найти» и проверьте положение');
            return;
        }

        var tokenField = document.querySelector('input[name="csrf_token"]');
        if (!tokenField) {
            status('Не найден CSRF-токен, обновите страницу');
            return;
        }

        status('Готовлю снимок...');

        try {
            await afterMapSettled(_map);
            // если основной хост не ответил за отведённое время,
            // переходим на зеркало и даём ему столько же времени. Админ об
            // этом узнает из строки статуса, молчаливого подмены не будет.
            var usedMirror = false;
            await waitForTiles(_tileLayer, TILE_TIMEOUT_MS, function () {
                if (tileHostIsMirror) return;
                usedMirror = true;
                switchToMirror();
            });
            if (usedMirror) {
                status('Основной сервер тайлов не ответил, пробую зеркало...');
                await waitForTiles(_tileLayer, TILE_TIMEOUT_MS);
            }

            status('Рисую снимок...');

            var snap = composeSnapshot(container);
            if (snap.drawn === 0) {
                status('Не удалось прочитать тайлы карты: ни основной сервер, ни зеркало не отдали картинку');
                return;
            }

            var dataUrl = snap.canvas.toDataURL('image/png');

            var formData = new FormData();
            formData.append('csrf_token', tokenField.value);
            formData.append('map_snapshot', dataUrl);
            formData.append('map_address_text', byId('mapAddressInput').value.trim());
            formData.append('map_lat', container.dataset.lat);
            formData.append('map_lng', container.dataset.lng);
            formData.append('saveMapSnapshot', '1');

            status('Сохраняю...');

            var resp = await fetch('/admin.php?tab=settings', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            if (resp.ok || resp.redirected) {
                window.location.reload();
            } else {
                status('Ошибка сохранения: HTTP ' + resp.status);
            }
        } catch (err) {
            status('Не удалось сделать снимок: ' + err.message);
        }
    }

    document.addEventListener('click', function (e) {
        var target = e.target.closest ? e.target.closest('[data-action]') : null;
        if (!target) return;

        var action = target.getAttribute('data-action');

        if (action === 'geocode-address') {
            e.preventDefault();
            geocodeAndPreview();
        } else if (action === 'save-map-snapshot') {
            e.preventDefault();
            saveMapSnapshot();
        }
    });

    // Enter в поле адреса не должен отправлять форму: страница иначе
    // перезагрузится и модалка закроется вместе с результатом поиска
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        var input = byId('mapAddressInput');
        if (input && e.target === input) {
            e.preventDefault();
            geocodeAndPreview();
        }
    });

    // --- предпросмотр картинок бренда ---
    //
    // Файл выбирается в input, но отправляется только по «Сохранить».
    // Без предпросмотра админ узнал бы о смене логотипа уже после
    // сохранения, то есть на главной странице.
    //
    // FileReader, а не object URL: чтение в data URL ничего не оставляет
    // в памяти, а object URL пришлось бы отзывать вручную.
    var brandingPreviews = {
        brandingLogoInput: 'logoPreview',
        faviconUploadInput: 'faviconPreview'
    };

    Object.keys(brandingPreviews).forEach(function (inputId) {
        document.addEventListener('change', function (e) {
            if (!e.target || e.target.id !== inputId) return;

            var file = e.target.files && e.target.files[0];
            if (!file) return;

            var preview = byId(brandingPreviews[inputId]);
            if (!preview) return;

            var reader = new FileReader();
            reader.onload = function (ev) {
                preview.src = ev.target.result;
                // картинка была битой, а теперь загружена новая
                preview.classList.add('is-loaded');
            };
            reader.readAsDataURL(file);
        });
    });

    // --- предпросмотр сгенерированного favicon ---
    //
    // Предпросмотр спрашивает сервер и получает готовый PNG, а не рисует
    // его в браузере: рисовать дважды - значит поддерживать два разных
    // результата, и рано или поздно они разойдутся. Сервер отдаёт байты
    // из того же кода, которым потом сохраняет файл, поэтому картинка в
    // форме совпадает с тем, что окажется в вкладке.
    //
    // Файл при этом не пишется: сохранение и предпросмотр разделены, и
    // неудачный эксперимент не затирает текущую иконку.
    async function previewGeneratedFavicon() {
        var letterInput = byId('faviconLetter');
        var bgInput = byId('faviconBg');
        var textInput = byId('faviconText');
        var autoInput = byId('faviconAutoColor');
        var preview = byId('faviconPreview');
        var tokenField = document.querySelector('input[name="csrf_token"]');
        if (!letterInput || !bgInput || !preview || !tokenField) return;

        var letter = letterInput.value.trim();

        // Ручной цвет передаётся только при снятой галочке «авто»:
        // сервер считает авто пустым цветом буквы, и передавать
        // выключенное поле значением было бы враньём.
        var params = {
            csrf_token: tokenField.value,
            preview_favicon: '1',
            favicon_letter: letter,
            favicon_bg: bgInput ? bgInput.value : '#C99CFF'
        };
        if (autoInput && autoInput.checked) {
            params.favicon_auto_color = '1';
        } else if (textInput) {
            params.favicon_text = textInput.value;
        }

        var resp = await fetch('/admin.php?tab=settings', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(params).toString()
        });

        if (!resp.ok) {
            // 422 с кодом ошибки в теле: буква не та. Показываем текст
            // под полем, а не alert - alert сработал бы поверх формы и
            // мешал бы её читать.
            var why = (await resp.text()).trim();
            setFaviconNotice(faviconNoticeText(why, letter));
            return;
        }

        // Data URL, а не object URL - и не из-за памяти (хотя и это
        // осталось бы верным: object URL пришлось бы отзывать вручную),
        // а потому что CSP проекта в img-src разрешает 'self', data: и
        // тайлы OpenStreetMap, и НЕ разрешает blob:. С object URL
        // браузер молча блокировал картинку: img отдавал naturalWidth
        // 0 и показывал битую иконку, хотя сервер отвечал валидным PNG.
        // Заметить это можно было только глазами - ни в консоли, ни в
        // ответе сервера ошибки нет. Тот же приём уже применён к
        // предпросмотру загруженного файла выше, в brandingPreviews.
        var blob = await resp.blob();
        var dataUrl = await blobAsDataUrl(blob);
        preview.src = dataUrl;
        preview.classList.add('is-loaded');
        setFaviconNotice('Нажмите «Сохранить», чтобы применить иконку.');
    }

    // FileReader оборачиваем в промис: в остальном коде он используется
    // через onload, а здесь результат нужен дальше по потоку функции.
    function blobAsDataUrl(blob) {
        return new Promise(function (resolve, reject) {
            var reader = new FileReader();
            reader.onload = function (ev) {
                resolve(ev.target.result);
            };
            reader.onerror = function () {
                reject(new Error('Не удалось прочитать сгенерированную иконку'));
            };
            reader.readAsDataURL(blob);
        });
    }

    function faviconNoticeText(code, letter) {
        if (code === 'favicon_letter_unsupported') {
            return 'Генератор рисует одну букву. Для «' + letter + '» загрузите свою картинку.';
        }
        if (code === 'favicon_letter_empty') {
            return 'Введите букву.';
        }
        return 'Не удалось построить иконку.';
    }

    function setFaviconNotice(text) {
        var note = byId('faviconNotice');
        if (note) note.textContent = text || '';
    }

    // галочка «авто» отключает ручной пикер цвета буквы.
    // Выключенный пикер показывает, что ручной цвет не действует:
    // просто серый input выглядел бы рабочим.
    function syncFaviconAutoColor() {
        var auto = byId('faviconAutoColor');
        var text = byId('faviconText');
        if (auto && text) {
            text.disabled = auto.checked;
        }
    }

    var faviconAuto = byId('faviconAutoColor');
    if (faviconAuto) {
        faviconAuto.addEventListener('change', syncFaviconAutoColor);
        // начальное состояние - как в сохранённых настройках
        syncFaviconAutoColor();
    }

    document.addEventListener('click', function (e) {
        var target = e.target.closest ? e.target.closest('[data-action="generate-favicon"]') : null;
        if (!target) return;
        e.preventDefault();
        previewGeneratedFavicon();
    });

    // «Вернуть сгенерированную»: отдельный POST, а не часть обычного
    // «Сохранить».
    //
    // Форма всегда отправляет favicon_letter и favicon_bg, и пока файл
    // загружен, сервер их игнорирует - иначе иначе он перерисовывал бы
    // загруженную картинку при каждом сохранении. Значит вернуть букву
    // можно только отдельной кнопкой, отдельным запросом.
    document.addEventListener('click', function (e) {
        var target = e.target.closest ? e.target.closest('[data-action="remove-custom-favicon"]') : null;
        if (!target) return;
        e.preventDefault();

        if (!window.confirm('Вернуть сгенерированную иконку?\nЗагруженная картинка будет удалена безвозвратно.')) return;

        var form = target.closest('form');
        if (!form) return;

        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'removeCustomFavicon';
        input.value = '1';
        form.appendChild(input);
        form.submit();
    });

    // Переключение варианта иконки: показать сгенерированную или
    // загруженную. Отдельный POST, а не часть обычного «Сохранить».
    //
    // Форма всегда отправляет favicon_letter и favicon_bg, и пока активна
    // загруженная картинка, сервер их игнорирует - иначе он
    // перерисовывал бы загруженную картинку при каждом сохранении. Значит
    // сменить вариант можно только отдельной кнопкой.
    //
    // Значение варианта кладётся в data-variant, а не берётся из текста
    // кнопки: текст меняется вместе с переводом интерфейса, и опираться
    // на него было бы хрупко.
    document.addEventListener('click', function (e) {
        var target = e.target.closest ? e.target.closest('[data-action="use-favicon-variant"]') : null;
        if (!target) return;
        e.preventDefault();

        var variant = target.getAttribute('data-variant');
        if (variant !== 'generated' && variant !== 'custom') return;

        var form = target.closest('form');
        if (!form) return;

        if (!window.confirm(
            variant === 'generated'
                ? 'Показать сгенерированную иконку?\nЗагруженная картинка сохранится.'
                : 'Показать загруженную иконку?\nСгенерированная сохранится.'
        )) return;

        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'useFaviconVariant';
        input.value = variant;
        form.appendChild(input);
        form.submit();
    });

    // Enter в поле буквы отправлял бы форму целиком, то есть сохранял
    // настройки вместо предпросмотра.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        var letter = byId('faviconLetter');
        if (letter && e.target === letter) {
            e.preventDefault();
            previewGeneratedFavicon();
        }
    });
})();
