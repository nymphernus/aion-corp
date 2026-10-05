<?php
/**
 * Мелкие помощники разметки (Stage 5-f-4).
 *
 * Существует ради одного поля — пароля с кнопкой «показать». Само поле
 * рисуется в пяти местах: логин, регистрация и три поля смены пароля.
 * Копировать обёртку с двумя svg пять раз - это пять мест, где
 * разъедутся type кнопки, aria-подпись и набор атрибутов.
 */

if (!function_exists('password_field')) {
    /**
     * Поле пароля с кнопкой показа/скрытия.
     *
     * Кнопка внутри формы обязана быть type="button". Без этого она
     * submit по умолчанию, а preventDefault в обработчике клика не
     * спасает на старых iOS Safari: форма уходит раньше, чем обработчик
     * отработает. Тип задан в разметке, а не в скрипте.
     *
     * Значение поля не принимается намеренно: пароль не должен
     * попадать в HTML даже временно, например при ошибке валидации.
     *
     * Разметка собирается строками с общим отступом $indent, чтобы
     * вывод совпадал по отступу с местом вызова. Иначе первая строка
     * брала бы отступ из этого файла, а остальные - нет, и разметка
     * выглядела бы сломанной в исходнике страницы.
     *
     * @param string $name        имя поля для отправки
     * @param string $id          id для связи с <label for>
     * @param string $autocomplete current-password / new-password
     * @param string $placeholder подсказка внутри поля
     * @param int|null $minlength ограничение снизу, если нужно
     * @param int|null $maxlength ограничение сверху, если нужно
     * @param bool $required      обязательность
     * @param int $indent         отступ первой строки, пробелов
     */
    function password_field(
        string $name,
        string $id,
        string $autocomplete,
        string $placeholder = '',
        ?int $minlength = null,
        ?int $maxlength = null,
        bool $required = true,
        int $indent = 0
    ): void {
        $attr = 'name="' . escape($name) . '"'
            . ' id="' . escape($id) . '"'
            . ' autocomplete="' . escape($autocomplete) . '"';
        if ($placeholder !== '') {
            $attr .= ' placeholder="' . escape($placeholder) . '"';
        }
        if ($minlength !== null) {
            $attr .= ' minlength="' . (int) $minlength . '"';
        }
        if ($maxlength !== null) {
            $attr .= ' maxlength="' . (int) $maxlength . '"';
        }
        if ($required) {
            $attr .= ' required';
        }

        $lines = [
            '<div class="password-field">',
            '<input class="input" type="password" ' . $attr . '>',
            '<button type="button" class="password-toggle" data-action="toggle-password"',
            '        aria-label="Показать пароль" title="Показать пароль">',
            '<svg class="password-toggle__show" width="20" height="20" viewBox="0 0 24 24" fill="none"',
            '     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"',
            '     aria-hidden="true" focusable="false">',
            '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>',
            '<circle cx="12" cy="12" r="3"></circle>',
            '</svg>',
            '<svg class="password-toggle__hide" width="20" height="20" viewBox="0 0 24 24" fill="none"',
            '     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"',
            '     aria-hidden="true" focusable="false">',
            '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>',
            '<line x1="1" y1="1" x2="23" y2="23"></line>',
            '</svg>',
            '</button>',
            '</div>',
        ];

        $pad = str_repeat(' ', max(0, $indent));
        foreach ($lines as $i => $line) {
            echo ($i === 0 ? $pad : $pad . '    ') . $line . "\n";
        }
    }
}
