<?php
/**
 * Мелкие помощники разметки .
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
/**
 * Иконка пресета бюджета по имени из configurator_presets.preset_icon.
 *
 * @param string $name ключ из cfg_preset_icons()
 * @param int    $size сторона квадрата в пикселях
 * @return string svg с currentColor
 */
if (!function_exists('cfg_preset_icons')) {
    function cfg_preset_icons(): array
    {
        return [
            'monitor' => '<rect x="2" y="3" width="20" height="14" rx="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line>',
            'gamepad' => '<line x1="6" y1="12" x2="10" y2="12"></line><line x1="8" y1="10" x2="8" y2="14"></line><line x1="15" y1="13" x2="15.01" y2="13"></line><line x1="18" y1="11" x2="18.01" y2="11"></line><path d="M17.32 5H6.68a4 4 0 0 0-3.978 3.59c-.006.052-.01.101-.017.152C2.604 9.416 2 14.456 2 16a3 3 0 0 0 3 3c1 0 1.5-.5 2-1l1.414-1.414A2 2 0 0 1 9.828 16h4.344a2 2 0 0 1 1.414.586L17 18c.5.5 1 1 2 1a3 3 0 0 0 3-3c0-1.545-.604-6.584-.685-7.258-.007-.05-.011-.1-.017-.151A4 4 0 0 0 17.32 5z"></path>',
            'chart' => '<line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line>',
            'zap' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>',
            'cpu' => '<rect x="4" y="4" width="16" height="16" rx="2"></rect><rect x="9" y="9" width="6" height="6"></rect><path d="M9 1v3M15 1v3M9 20v3M15 20v3M1 9h3M1 15h3M20 9h3M20 15h3"></path>',
            'star' => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>',
        ];
    }
}

if (!function_exists('cfg_preset_icon_names')) {
    /**
     * Иконки для выбора в админке: ключ => подпись.
     *
     * Порядок задаёт порядок плиток в модалке, а не выдачу массива
     * cfg_preset_icons(): тот отдаёт только внутренние пути.
     *
     * @return array<string, string>
     */
    function cfg_preset_icon_names(): array
    {
        return [
            'monitor' => 'Монитор',
            'gamepad' => 'Геймпад',
            'chart' => 'График',
            'zap' => 'Молния',
            'cpu' => 'Процессор',
            'star' => 'Звезда',
        ];
    }
}

if (!function_exists('render_preset_icon')) {
    function render_preset_icon(string $name, int $size = 24): string
    {
        $icons = cfg_preset_icons();
        $body = $icons[$name] ?? $icons['monitor'];

        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24"'
            . ' fill="none" stroke="currentColor" stroke-width="1.5"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . $body
            . '</svg>';
    }
}
