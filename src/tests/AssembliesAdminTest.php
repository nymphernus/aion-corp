<?php
/**
 * AssembliesAdminTest — вкладка «Сборки»: добавление, правка и удаление
 * базовых сборок витрины.
 *
 * Тесты бьют по живым POST-запросам: обработчик собирает девять
 * плейсхолдеров по списку слотов, и ошибка в их числе или в порядке
 * объявляется только при выполнении запроса. Юнит-тест на уровне функции
 * проверял бы намерение, а не форму запроса.
 *
 * Состав собирается из реальных компонентов, номера берутся запросом, а
 * не из демо-данных: тест не должен зависеть от того, что в базу положили
 * при настройке.
 */

declare(strict_types=1);

final class AssembliesAdminTest extends AionTestCase
{
    /** @var int[] Базовые сборки, созданные этим тестом */
    private array $createdIds = [];

    /** @var array<string, int> Слот => component_id */
    private array $parts = [];

    protected function tearDown(): void
    {
        $this->cleanupConfiguratorRows();
        $this->dropCreatedAssemblies();
        parent::tearDown();
    }

    /**
     * Имя поля формы для слота.
     *
     * Список слотов один - production-функция assembly_slots(). Свой
     * список в тесте означал бы, что тест проверяет другую форму, а
     * расхождение между ними заметил бы только пользователь.
     */
    private static function field(string $slot): string
    {
        return 'comp_' . (assembly_slots()[$slot] ?? 0);
    }

    /**
     * Имя строки фикстуры: маркер для уборки плюс уникальный хвост.
     *
     * Хвост нужен потому, что два теста подряд стартуют в пределах одной
     * секунды, и имена из одного time() совпали бы: второй тест увидел бы
     * строку первого как будто она существовала до него.
     */
    private static function tmpName(string $prefix): string
    {
        return $prefix . substr((string) time(), -6) . '_' . bin2hex(random_bytes(3));
    }

    private function loginAsAdmin(): void
    {
        $adminPass = getenv('ADMIN_PASSWORD');
        $this->assertNotEmpty($adminPass, 'ADMIN_PASSWORD не задан в окружении');
        $this->clearLoginAttempts('admin');
        $r = $this->loginAs('admin', (string) $adminPass);
        $this->assertSame(302, $r['code']);
    }

    private function freshToken(): string
    {
        $page = $this->httpGet('/admin.php?tab=assemblies');
        $this->assertSame(200, $page['code']);
        return $this->extractCsrf($page['body']);
    }

    /**
     * Поля формы для сохранения.
     *
     * @return array<string, string>
     */
    private function formData(string $name, int $price, array $overrides = []): array
    {
        $data = [
            'assemblyAction' => 'save',
            'assemblyId' => '0',
            'assembly_name' => $name,
            'assembly_price' => (string) $price,
        ];
        foreach ($this->parts as $slot => $componentId) {
            $data[self::field($slot)] = (string) $componentId;
        }
        return array_merge($data, $overrides);
    }

    private function assemblyRow(int $id): ?array
    {
        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT * FROM assembly WHERE assembly_id = ?', 'i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mysql->close();
        return $row === null ? null : $row;
    }

    private function dropCreatedAssemblies(): void
    {
        if ($this->createdIds === []) {
            return;
        }

        $mysql = connect();
        foreach ($this->createdIds as $id) {
            foreach (['favorites', 'orders'] as $table) {
                $stmt = db_prepare($mysql, "DELETE FROM `$table` WHERE assembly_id = ?", 'i', $id);
                $stmt->execute();
                $stmt->close();
            }
            $stmt = db_prepare($mysql, 'DELETE FROM assembly WHERE assembly_id = ?', 'i', $id);
            $stmt->execute();
            $stmt->close();
        }
        $this->createdIds = [];
        $mysql->close();
    }

    /**
     * Взять по одному компоненту каждой обязательной категории.
     *
     * Требуются все девять: остальные слоты nullable, но проверять надо
     * именно то, что форма их принимает, иначе тест прошёл бы на сборке,
     * у которой половина состава пустая.
     */
    private function pickParts(): void
    {
        $mysql = connect();
        $this->parts = [];

        foreach (assembly_slots() as $slot => $categoryId) {
            $stmt = db_prepare(
                $mysql,
                'SELECT component_id FROM components WHERE category_id = ? ORDER BY component_id ASC LIMIT 1',
                'i',
                $categoryId
            );
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $this->assertNotEmpty($row, "в категории {$categoryId} должен быть хотя бы один компонент");
            $this->parts[$slot] = (int) $row['component_id'];
        }

        $mysql->close();
    }

    public function testCreateBaseAssembly(): void
    {
        $this->loginAsAdmin();
        $this->pickParts();
        $name = self::tmpName('tmp_asm_');

        $r = $this->httpPost('/admin.php?tab=assemblies', array_merge(
            ['csrf_token' => $this->freshToken()],
            $this->formData($name, 150000)
        ));

        $this->assertSame(302, $r['code'], 'после сохранения должен быть редирект');
        $this->assertStringNotContainsString('bad=', $r['location'], 'сохранение не должно отклоняться');
        $this->assertStringContainsString('saved=1', $r['location']);

        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT assembly_id FROM assembly WHERE assembly_name = ?', 's', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mysql->close();

        $this->assertNotEmpty($row, 'сборка не появилась в таблице');
        $id = (int) $row['assembly_id'];
        $this->createdIds[] = $id;

        $assembly = $this->assemblyRow($id);
        $this->assertNotNull($assembly);

        // Ключевое: сборка из админки обязана быть витриной. Без флага
        // cleanup_orphans.php снёс бы её через час как сироту.
        $this->assertSame(1, (int) $assembly['is_base'], 'сборка из админки должна быть базовой');
        $this->assertSame(150000, (int) $assembly['assembly_price'], 'стоимость должна сохраниться');

        foreach ($this->parts as $slot => $componentId) {
            $this->assertSame($componentId, (int) $assembly[$slot], "слот {$slot} должен сохраниться");
        }
    }

    public function testEditAssemblyUpdatesSameRow(): void
    {
        $this->loginAsAdmin();
        $this->pickParts();

        $name = self::tmpName('tmp_asm_');
        $create = $this->httpPost('/admin.php?tab=assemblies', array_merge(
            ['csrf_token' => $this->freshToken()],
            $this->formData($name, 100000)
        ));
        $this->assertSame(302, $create['code']);

        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT assembly_id FROM assembly WHERE assembly_name = ?', 's', $name);
        $stmt->execute();
        $id = (int) ($stmt->get_result()->fetch_assoc()['assembly_id'] ?? 0);
        $stmt->close();
        $mysql->close();
        $this->assertGreaterThan(0, $id, 'сборка для правки не создалась');
        $this->createdIds[] = $id;

        // Другой корпус: правка обязана менять ту же строку, а её состав
        // - по-настоящему перезаписываться.
        $mysql = connect();
        $stmt = db_prepare(
            $mysql,
            'SELECT component_id FROM components WHERE category_id = 6 AND component_id <> ? ORDER BY component_id ASC LIMIT 1',
            'i',
            $this->parts['case_id']
        );
        $stmt->execute();
        $otherCase = (int) ($stmt->get_result()->fetch_assoc()['component_id'] ?? 0);
        $stmt->close();
        $mysql->close();
        $this->assertGreaterThan(0, $otherCase, 'нужен второй корпус для проверки правки');

        $renamed = self::tmpName('tmp_asm_');
        $edit = $this->httpPost('/admin.php?tab=assemblies', array_merge(
            ['csrf_token' => $this->freshToken()],
            $this->formData($renamed, 250000, [
                'assemblyId' => (string) $id,
                self::field('case_id') => (string) $otherCase,
            ])
        ));

        $this->assertSame(302, $edit['code']);
        $this->assertStringNotContainsString('bad=', $edit['location']);

        $assembly = $this->assemblyRow($id);
        $this->assertNotNull($assembly, 'правка не должна была создать новую строку');
        $this->assertSame($renamed, (string) $assembly['assembly_name']);
        $this->assertSame(250000, (int) $assembly['assembly_price']);
        $this->assertSame($otherCase, (int) $assembly['case_id'], 'корпус должен замениться');
        $this->assertSame(1, (int) $assembly['is_base'], 'правка не должна снимать флаг базовой сборки');
    }

    public function testDeleteAssemblyWithoutOrders(): void
    {
        $this->loginAsAdmin();
        $this->pickParts();

        $name = self::tmpName('tmp_asm_');
        $create = $this->httpPost('/admin.php?tab=assemblies', array_merge(
            ['csrf_token' => $this->freshToken()],
            $this->formData($name, 100000)
        ));
        $this->assertSame(302, $create['code']);

        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT assembly_id FROM assembly WHERE assembly_name = ?', 's', $name);
        $stmt->execute();
        $id = (int) ($stmt->get_result()->fetch_assoc()['assembly_id'] ?? 0);
        $stmt->close();
        $mysql->close();
        $this->assertGreaterThan(0, $id);
        $this->createdIds[] = $id;

        $del = $this->httpPost('/admin.php?tab=assemblies', [
            'csrf_token' => $this->freshToken(),
            'assemblyAction' => 'delete',
            'assemblyId' => (string) $id,
        ]);

        $this->assertSame(302, $del['code']);
        $this->assertStringNotContainsString('error=used', $del['location'], 'свободную сборку удалять можно');
        $this->assertNull($this->assemblyRow($id), 'сборка должна исчезнуть');
    }

    public function testDeleteAssemblyWithOrdersIsRefused(): void
    {
        $this->loginAsAdmin();
        $this->pickParts();

        $name = self::tmpName('tmp_asm_');
        $create = $this->httpPost('/admin.php?tab=assemblies', array_merge(
            ['csrf_token' => $this->freshToken()],
            $this->formData($name, 100000)
        ));
        $this->assertSame(302, $create['code']);

        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT assembly_id FROM assembly WHERE assembly_name = ?', 's', $name);
        $stmt->execute();
        $id = (int) ($stmt->get_result()->fetch_assoc()['assembly_id'] ?? 0);
        $stmt->close();

        // Заказ на сборку: удалять её нельзя, на неё ссылается внешний
        // ключ, и без проверки MySQL ответил бы кодом 1451.
        $stmt = db_prepare(
            $mysql,
            'INSERT INTO orders (user_id, assembly_id, status) VALUES (?, ?, ?)',
            'iis',
            (int) $this->adminId(),
            $id,
            'Обрабатывается'
        );
        $stmt->execute();
        $stmt->close();
        $mysql->close();

        $this->createdIds[] = $id;

        $del = $this->httpPost('/admin.php?tab=assemblies', [
            'csrf_token' => $this->freshToken(),
            'assemblyAction' => 'delete',
            'assemblyId' => (string) $id,
        ]);

        $this->assertSame(302, $del['code']);
        $this->assertStringContainsString('error=used', $del['location'], 'нужен отказ с кодом used');
        $this->assertNotNull($this->assemblyRow($id), 'сборка с заказом должна остаться');
    }

    public function testCreateRejectsMissingRequiredSlots(): void
    {
        $this->loginAsAdmin();
        $this->pickParts();

        // Без корпуса: картинка на главной бралась бы из пустоты, и это
        // отказ, а не сборка по умолчанию.
        $noCase = $this->httpPost('/admin.php?tab=assemblies', array_merge(
            ['csrf_token' => $this->freshToken()],
            $this->formData(self::tmpName('tmp_asm_'), 100000, [
                self::field('case_id') => '0',
            ])
        ));
        $this->assertSame(302, $noCase['code']);
        $this->assertStringContainsString('bad=case_required', $noCase['location']);

        $noCpu = $this->httpPost('/admin.php?tab=assemblies', array_merge(
            ['csrf_token' => $this->freshToken()],
            $this->formData(self::tmpName('tmp_asm_'), 100000, [
                self::field('cpu_id') => '0',
            ])
        ));
        $this->assertSame(302, $noCpu['code']);
        $this->assertStringContainsString('bad=cpu_required', $noCpu['location']);
    }

    /**
     * Компонент нельзя положить в чужой слот.
     *
     * Форма шлёт любой component_id, а картинка на главной берётся из
     * case_id. Без сверки с категорией в корпусе оказывался бы процессор,
     * и витрина показывала бы картинку процессора.
     */
    public function testComponentFromWrongCategoryIsRejected(): void
    {
        $this->loginAsAdmin();
        $this->pickParts();

        $r = $this->httpPost('/admin.php?tab=assemblies', array_merge(
            ['csrf_token' => $this->freshToken()],
            $this->formData(self::tmpName('tmp_asm_'), 100000, [
                // процессор в слот корпуса
                self::field('case_id') => (string) $this->parts['cpu_id'],
            ])
        ));

        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString('bad=comp_category', $r['location'], 'чужой компонент должен отклоняться');
    }

    /**
     * В опциях селектов есть характеристики.
     *
     * Полноценный каскад (одно поле фильтрует другое) отложен, но
     * знающий человек должен видеть сокет, тип памяти и форм-фактор
     * прямо в списке — иначе подбирать их пришлось бы по каталогу.
     */
    /**
     * Операционная система в модалке сборки.
     *
     * Колонка assembly.os - varchar с названием: конфигуратор кладёт
     * туда имя, и редактор обязан писать то же самое. id пришёл бы в
     * колонку строкой, и страница сборки показала бы «1» вместо
     * «Windows 10 Home».
     */
    public function testOsSavesByNameAndClears(): void
    {
        $this->loginAsAdmin();
        $this->pickParts();

        // Берём активную ОС из справочника
        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT os_id, os_name FROM configurator_os WHERE is_active = 1 ORDER BY os_id ASC LIMIT 1', '');
        $stmt->execute();
        $os = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mysql->close();
        $this->assertNotEmpty($os, 'в справочнике должна быть хотя бы одна активная ОС');

        $name = self::tmpName('tmp_os_asm_');
        $create = $this->httpPost('/admin.php?tab=assemblies', array_merge(
            ['csrf_token' => $this->freshToken()],
            $this->formData($name, 150000),
            ['os_id' => (string) (int) $os['os_id']]
        ));
        $this->assertSame(302, $create['code']);

        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT assembly_id, os FROM assembly WHERE assembly_name = ?', 's', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mysql->close();

        $this->assertNotEmpty($row, 'сборка не создалась');
        $this->assertSame((string) $os['os_name'], (string) $row['os'], 'в assembly.os должно лежать название, а не id');
        $id = (int) $row['assembly_id'];
        $this->createdIds[] = $id;

        // Селект в таблице обязан нести название для заполнения формы
        $page = $this->httpGet('/admin.php?tab=assemblies');
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('data-assembly-os="' . escape((string) $os['os_name']) . '"', $page['body'], 'строка таблицы должна нести название ОС');

        // Очистка: os_id = 0 обязан записать NULL, а не пустую строку
        $clear = $this->httpPost('/admin.php?tab=assemblies', array_merge(
            ['csrf_token' => $this->freshToken()],
            $this->formData($name, 150000),
            ['os_id' => '0', 'assemblyId' => (string) $id]
        ));
        $this->assertSame(302, $clear['code']);

        $assembly = $this->assemblyRow($id);
        $this->assertNotNull($assembly);
        $this->assertNull($assembly['os'], 'сброс ОС должен записывать NULL');
    }

    /**
     * Выключенная или несуществующая ОС отклоняется.
     *
     * Название перечитывается из базы, а не берётся из формы: иначе
     * форма могла бы подставить удалённую ОС, и карточка на странице
     * сборки показывала бы то, чего в справочнике нет.
     */
    public function testInactiveOsIsRejected(): void
    {
        $this->loginAsAdmin();
        $this->pickParts();

        // Выключенная ОС, если есть; иначе несуществующий id
        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT os_id FROM configurator_os WHERE is_active = 0 ORDER BY os_id ASC LIMIT 1', '');
        $stmt->execute();
        $inactive = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mysql->close();

        $badOsId = $inactive !== null ? (string) (int) $inactive['os_id'] : '999999';

        $r = $this->httpPost('/admin.php?tab=assemblies', array_merge(
            ['csrf_token' => $this->freshToken()],
            $this->formData(self::tmpName('tmp_os_bad_'), 100000),
            ['os_id' => $badOsId]
        ));

        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString('bad=os_not_found', $r['location'], 'выключенная или чужая ОС должна отклоняться');
    }

    public function testSelectOptionsShowSpecs(): void
    {
        $this->loginAsAdmin();

        $page = $this->httpGet('/admin.php?tab=assemblies');
        $this->assertSame(200, $page['code']);

        // У процессора должен быть сокет
        $this->assertMatchesRegularExpression(
            '/<option value="\d+">[^<]*LGA\d+[^<]*<\/option>/',
            $page['body'],
            'у процессора в опциях должен быть сокет'
        );

        // У материнской платы должен быть тип памяти
        $this->assertMatchesRegularExpression(
            '/<option value="\d+">[^<]*DDR\d[^<]*<\/option>/',
            $page['body'],
            'у материнской платы в опциях должен быть тип памяти'
        );

        // У блока питания должна быть мощность
        $this->assertMatchesRegularExpression(
            '/<option value="\d+">[^<]*\d+ Вт[^<]*<\/option>/',
            $page['body'],
            'у блока питания в опциях должна быть мощность'
        );
    }

    public function testTabListsBaseAssembliesOnly(): void
    {
        $this->loginAsAdmin();

        $page = $this->httpGet('/admin.php?tab=assemblies');
        $this->assertSame(200, $page['code']);

        // Пользовательская сборка в списке витрины быть не должна.
        $mysql = connect();
        $stmt = db_prepare(
            $mysql,
            'SELECT a.assembly_id, a.assembly_name FROM assembly a
              WHERE a.is_base = 0 ORDER BY a.assembly_id DESC LIMIT 1',
            ''
        );
        $stmt->execute();
        $userRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mysql->close();

        if ($userRow !== null) {
            $this->assertStringNotContainsString(
                '#' . (int) $userRow['assembly_id'] . '</td>',
                $page['body'],
                'сборка конфигуратора не должна попадать в список базовых'
            );
        }

        // У каждой строки есть состав в data-parts: без него правка
        // открылась бы с пустыми списками. Порядок атрибутов не важен,
        // поэтому проверка двумя отдельными соответствиями, а не одной
        // строкой.
        $this->assertMatchesRegularExpression(
            '/data-assembly-id="\d+"/',
            $page['body'],
            'в строках таблицы должен быть номер сборки'
        );
        $this->assertMatchesRegularExpression(
            '/data-parts="[0-9:,]*"/',
            $page['body'],
            'в строках таблицы должен быть состав для заполнения формы правки'
        );
    }

    private function adminId(): int
    {
        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT user_id FROM users WHERE user_login = ?', 's', 'admin');
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mysql->close();
        $this->assertNotEmpty($row);
        return (int) $row['user_id'];
    }
}
