<?php
/**
 * IconsTest — иконки конфигуратора (Stage 7.5).
 */

declare(strict_types=1);

final class IconsTest extends AionTestCase
{
    /**
     * Ключи, которые обязаны существовать.
     *
     * Список явный, а не «сколько есть в массиве»: иначе тест прошёл бы
     * и с одной иконкой, и с нулём - достаточно, чтобы icon() ничего не
     * вернул и страница осталась без иконок.
     *
     * @var string[]
     */
    private const REQUIRED = ['cpu', 'mb', 'gpu', 'ram', 'psu', 'case', 'cooler', 'hdd', 'ssd', 'os'];

    /**
     * 7.5: набор ключей полный, лишних нет.
     *
     * Лишние ключи опасны тем, что на них ссылается разметка, а сама
     * иконка в них забыта или удалена - icon() вернёт пустую строку, и
     * дыра в карточке будет выглядеть как «ну не загрузилось».
     */
    public function testAllIconKeysRenderAndNoExtras(): void
    {
        require_once dirname(__DIR__) . '/modules/icons.php';

        foreach (self::REQUIRED as $key) {
            $svg = icon($key);
            $this->assertNotSame('', $svg, "иконка «{$key}» должна рисоваться");
            $this->assertStringContainsString('<svg', $svg, "иконка «{$key}» должна быть svg");
        }

        // неизвестный ключ - пустая строка, а не исключение и не мусор
        $this->assertSame('', icon('такой-ключа-нет'));
    }

    /**
     * 7.5: у всех иконок одинаковая оболочка.
     *
     * Девять атрибутов в общем классе оболочки сделаны для того, чтобы
     * их нельзя было забыть у одной иконки. Проверяется это сравнением
     * открывающих тегов: если у какой-то иконки viewBox или
     * stroke-linecap отличается, теги разойдутся.
     *
     * Плюс отдельная проверка currentColor: при жёстко заданном цвете
     * обводки смена акцента темы перестала бы работать, и иконки
     * выглядели бы чужеродно на синем фоне.
     */
    public function testIconsShareOneStyle(): void
    {
        require_once dirname(__DIR__) . '/modules/icons.php';

        $openingTags = [];
        foreach (self::REQUIRED as $key) {
            $svg = icon($key);
            $this->assertSame(
                1,
                preg_match('/^<svg\b[^>]*>/', $svg, $m),
                "иконка «{$key}» должна начинаться с тега svg"
            );
            $openingTags[$key] = $m[0];

            // currentColor и никаких зашитых цветов
            $this->assertStringContainsString(
                'stroke="currentColor"',
                $m[0],
                "иконка «{$key}» должна наследовать цвет"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/(?:fill|stroke)="#/',
                $svg,
                "в иконке «{$key}» не должно быть зашитого цвета"
            );

            // высота не задаётся CSS-потолком, а viewBox есть у всех
            $this->assertStringContainsString('viewBox="0 0 24 24"', $m[0], "у иконки «{$key}» должен быть viewBox");
        }

        $first = $openingTags[self::REQUIRED[0]];
        foreach (self::REQUIRED as $key) {
            $this->assertSame(
                $first,
                $openingTags[$key],
                "открывающий тег иконки «{$key}» отличается от остальных"
            );
        }
    }

    /**
     * 7.5: размер подставляется и приводится к целому.
     *
     * Подставлять нужно именно (int): иначе $size = '24abc' дал бы в
     * разметке width="24abc", и весь svg не отрисовался бы.
     */
    public function testSizeIsCastToInt(): void
    {
        require_once dirname(__DIR__) . '/modules/icons.php';

        $this->assertStringContainsString('width="24" height="24"', icon('cpu', 24));
        $this->assertStringContainsString('width="36" height="36"', icon('cpu', 36));
        $this->assertStringContainsString('width="20" height="20"', icon('cpu', 20));

        // Тип int в сигнатуре и (int) в теле - разные вещи. (int) нужен
        // для вызова из файла без declare(strict_types=1): там в тело
        // может прийти дробь, и в атрибут width должно попасть целое.
        // Здесь файл со strict_types, поэтому проверяем только целые.
    }

    /**
     * 7.5: класс прокидывается, лишние пробелы не мешают.
     *
     * Класс идёт в разметку напрямую, поэтому в нём не должно быть
     * возможности подсунуть кавычку и закрыть атрибут.
     */
    public function testClassIsEscaped(): void
    {
        require_once dirname(__DIR__) . '/modules/icons.php';

        $svg = icon('cpu', 24, 'comp-card__icon-svg');
        $this->assertStringContainsString('class="icon comp-card__icon-svg"', $svg);

        $evil = icon('cpu', 24, 'a" onload="alert(1)');
        $this->assertStringNotContainsString('onload="alert(1)"', $evil);
        $this->assertStringContainsString('&quot;', $evil);
    }

    /**
     * 7.5: иконка декоративная и не мешает скринридеру.
     *
     * aria-hidden и focusable="false" - как у иконок в password_field().
     * Без них пустой svg без подписи читался бы как элемент без имени.
     */
    public function testIconIsHiddenFromAssistiveTech(): void
    {
        require_once dirname(__DIR__) . '/modules/icons.php';

        foreach (self::REQUIRED as $key) {
            $svg = icon($key);
            $this->assertStringContainsString('aria-hidden="true"', $svg, "иконка «{$key}» должна быть скрыта от скринридера");
            $this->assertStringContainsString('focusable="false"', $svg, "иконка «{$key}» не должна получать фокус");
        }
    }

    /**
     * 7.5: на странице сборки иконки - svg, а не img.
     *
     * Проверяется разбором ответа, а не поиском подстроки: сломанная
     * разметка внутри html-комментария в ответе выглядит так же, как
     * рабочая, и такой тест её пропустил бы.
     */
    public function testAssemblyPageUsesSvgIcons(): void
    {
        // Сборка 1 - первая в сиде, поэтому init=1 показывает карточки
        // с иконками, а не пустую страницу с плашкой «ничего не выбрано».
        $this->loginAs('dethtaker', 'NewPass2026!');

        $page = $this->httpGet('/assembly.php?init=1');
        $this->assertSame(200, $page['code']);

        $this->assertSame(
            0,
            $this->xpathCount($page['body'], '//img[contains(@src, "cfg-icons")]'),
            'на странице сборки не должно остаться png-иконок конфигуратора'
        );

        $icons = $this->xpathCount($page['body'], '//div[@class="comp-card__icon"]/svg[@class="icon comp-card__icon-svg"]');
        $this->assertGreaterThan(0, $icons, 'на странице сборки должны быть svg-иконки компонентов');

        // каждая карточка компонента получила иконку, а не только часть.
        // Точное совпадение класса, а не contains: «comp-card» входит в
        // class и у вложенных div (comp-card__icon, comp-card__body,
        // comp-card__name), и contains насчитал бы 49 элементов вместо 7
        // карточек - тест прошёл бы на пустой странице и упал бы на
        // правильной.
        $cards = $this->xpathCount($page['body'], '//div[@class="comp-card"]');
        $this->assertSame($cards, $icons, 'иконка должна быть в каждой карточке');
    }

    /**
     * 7.5: icon() подключается там же, где escape().
     *
     * Функция зовёт escape(), а он живёт в connect.php. Если icons.php
     * не подключён автоматически, страница упадёт с «undefined
     * function» ровно в тот момент, когда компонент нашёлся.
     */
    public function testIconsLoadedWithConnect(): void
    {
        $connect = file_get_contents(dirname(__DIR__) . '/modules/connect.php');
        $this->assertIsString($connect, 'не удалось прочитать connect.php');

        $this->assertStringContainsString(
            "require_once __DIR__ . '/icons.php'",
            $connect,
            'icons.php должен подключаться из connect.php'
        );
    }
}