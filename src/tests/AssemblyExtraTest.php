<?php
/**
 * AssemblyExtraTest — допкомпоненты пользовательской сборки.
 *
 * Отдельный файл: проверка сайд-компонентов, а не основного цикла
 * конфигуратора, и свои сборки со своим циклом удаления.
 */

declare(strict_types=1);

final class AssemblyExtraTest extends AionTestCase
{
    /** @var int[] сборки, созданные тестом */
    private array $assemblyIds = [];

    protected function tearDown(): void
    {
        $this->dropAssemblies();
        parent::tearDown();
    }

    /**
     * Секция выбора допов есть только у пользовательской сборки.
     *
     * Проверка на обеих сторонах: у витринной сборки блока быть не должно,
     * иначе покупатель менял бы состав чужой сборки.
     */
    public function testSectionShownOnlyForUserAssembly(): void
    {
        $this->loginAsAdmin();
        $id = $this->makeUserAssembly();

        $user = $this->httpGet('/assembly.php?id=' . $id);
        $this->assertSame(200, $user['code']);
        $this->assertSame(
            1,
            $this->xpathCount($user['body'], '//*[@id="extraComponents"]'),
            'у пользовательской сборки должен быть блок допкомпонентов'
        );
        $this->assertSame(
            1,
            $this->xpathCount($user['body'], '//select[@id="extraSsd2"]'),
            'в блоке должен быть селект дополнительного SSD'
        );
        $this->assertSame(
            1,
            $this->xpathCount($user['body'], '//select[@id="extraHdd"]'),
            'в блоке должен быть селект жёсткого диска'
        );

        // Базовую собираем мимо конфигуратора: она нужна только как
        // отрицательный случай, и трогать витринные сборки нельзя.
        $base = $this->makeBaseAssembly();
        $shop = $this->httpGet('/assembly.php?id=' . $base);
        $this->assertSame(200, $shop['code']);
        $this->assertSame(
            0,
            $this->xpathCount($shop['body'], '//*[@id="extraComponents"]'),
            'у базовой сборки блока допкомпонентов быть не должно'
        );
    }

    /**
     * Сохранение допов меняет слоты и цену дельтой.
     *
     * Цена проверяется дельтой, а не суммой: assembly_price у
     * пользовательской сборки равна сумме восьми основных компонентов плюс
     * цена ОС. Пересчёт с нуля потерял бы ОС, поэтому тест фиксирует
     * именно разницу.
     */
    public function testSaveWritesSlotsAndAdjustsPrice(): void
    {
        $this->loginAsAdmin();
        $id = $this->makeUserAssembly();
        $before = $this->assemblyRow($id);

        $ssd = $this->pickPart(9, (int) $before['ssd_id']);
        $hdd = $this->pickPart(8);

        $r = $this->httpPost('/assembly.php?id=' . $id, [
            'save' => '1',
            'csrf_token' => $this->freshToken('/assembly.php?id=' . $id),
            'extra_ssd_2_id' => (string) $ssd['component_id'],
            'extra_hdd_id' => (string) $hdd['component_id'],
        ]);
        $this->assertSame(302, $r['code']);

        $after = $this->assemblyRow($id);
        $this->assertSame((int) $ssd['component_id'], (int) $after['ssd_2_id'], 'слот SSD 2 должен записаться');
        $this->assertSame((int) $hdd['component_id'], (int) $after['hdd_id'], 'слот HDD должен записаться');

        $expected = (int) $before['assembly_price']
            + (int) $ssd['component_price']
            + (int) $hdd['component_price'];
        $this->assertSame(
            $expected,
            (int) $after['assembly_price'],
            'цена должна вырасти ровно на стоимость допов'
        );

        // Возврат к прежней цене при снятии допов
        $r2 = $this->httpPost('/assembly.php?id=' . $id, [
            'save' => '1',
            'csrf_token' => $this->freshToken('/assembly.php?id=' . $id),
            'extra_ssd_2_id' => '0',
            'extra_hdd_id' => '0',
        ]);
        $this->assertSame(302, $r2['code']);

        $cleared = $this->assemblyRow($id);
        $this->assertNull($cleared['ssd_2_id'], 'пустой слот должен писаться как NULL, а не ноль');
        $this->assertNull($cleared['hdd_id'], 'пустой слот должен писаться как NULL, а не ноль');
        $this->assertSame(
            (int) $before['assembly_price'],
            (int) $cleared['assembly_price'],
            'снятие допов обязано вернуть прежнюю цену'
        );
    }

    /**
     * Чужой компонент отклоняется, а не молча выбрасывается.
     *
     * Процессор в слот жёсткого диска прошёл бы по числовому полю, но
     * сборка получила бы физически невозможный состав. Проверяется код
     * отказа в адресе.
     */
    public function testComponentFromWrongCategoryRejected(): void
    {
        $this->loginAsAdmin();
        $id = $this->makeUserAssembly();

        $cpu = $this->pickPart(1);

        $r = $this->httpPost('/assembly.php?id=' . $id, [
            'save' => '1',
            'csrf_token' => $this->freshToken('/assembly.php?id=' . $id),
            'extra_hdd_id' => (string) $cpu['component_id'],
        ]);

        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString(
            'error=extra_part',
            $r['location'],
            'процессор в слоте HDD должен отклоняться'
        );

        $after = $this->assemblyRow($id);
        $this->assertNull($after['hdd_id'], 'при отказе слот обязан остаться пустым');
    }

    /**
     * Базовую сборку допомпонентами не изменить.
     *
     * Страница не содержит ни селектов, ни скрытых полей, поэтому POST
     * мог бы прийти руками - сервер обязан его проигнорировать.
     */
    public function testBaseAssemblyIgnoresPostedExtras(): void
    {
        $this->loginAsAdmin();
        $id = $this->makeBaseAssembly();
        $hdd = $this->pickPart(8);

        $before = $this->assemblyRow($id);

        $r = $this->httpPost('/assembly.php?id=' . $id, [
            'save' => '1',
            'csrf_token' => $this->freshToken('/assembly.php?id=' . $id),
            'extra_hdd_id' => (string) $hdd['component_id'],
        ]);
        $this->assertSame(302, $r['code']);

        $after = $this->assemblyRow($id);
        $this->assertNull($after['hdd_id'], 'базовую сборку нельзя дополнить из POST');
        $this->assertSame(
            (int) $before['assembly_price'],
            (int) $after['assembly_price'],
            'цена базовой сборки меняться не должна'
        );
    }

    /**
     * Покупка списывает и допы тоже.
     *
     * assembly_demand() включал эти слоты и раньше, но путь «доп выбран -
     * куплено» не был проверен: сломанный слот молча уехал бы на склад
     * мимо списания.
     */
    public function testBuyDrawsExtraFromStock(): void
    {
        $this->loginAsAdmin();
        $id = $this->makeUserAssembly();
        $ssd = $this->pickPart(9, $this->assemblyRow($id)['ssd_id']);

        $stockBefore = $this->stockOf((int) $ssd['component_id']);

        $r = $this->httpPost('/assembly.php?id=' . $id, [
            'buy' => '1',
            'csrf_token' => $this->freshToken('/assembly.php?id=' . $id),
            'extra_ssd_2_id' => (string) $ssd['component_id'],
        ]);
        $this->assertSame(302, $r['code']);
        $this->assertStringNotContainsString('error=', $r['location'], 'покупка должна пройти');

        $this->assertSame(
            $stockBefore - 1,
            $this->stockOf((int) $ssd['component_id']),
            'допкомпонент должен быть списан'
        );
    }

    /**
     * Компонент заданной категории.
     *
     * $skipId исключает компонент: доп не должен совпадать с уже стоящим
     * в слоте. Иначе assembly_demand() справедливо потребовал бы две
     * штуки, и проверка списания считала бы не то.
     *
     * @return array<string, mixed>
     */
    private function pickPart(int $categoryId, int $skipId = 0): array
    {
        $mysql = connect();
        $stmt = db_prepare(
            $mysql,
            'SELECT component_id, component_name, component_price
               FROM components
              WHERE category_id = ? AND amount > 0 AND component_id != ?
              ORDER BY component_id ASC LIMIT 1',
            'ii',
            $categoryId,
            $skipId
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mysql->close();

        $this->assertNotEmpty($row, "в категории {$categoryId} должен быть хотя бы один компонент в наличии");
        return $row;
    }

    /**
     * Пользовательская сборка: is_base = 0.
     */
    private function makeUserAssembly(): int
    {
        return $this->insertAssembly(0);
    }

    /**
     * Баз��вая сборка: is_base = 1.
     *
     * Через INSERT, а не конфигуратором: конфигуратор базовых не делает,
     * а править витринные сборки 1-3 тесту нельзя.
     */
    private function makeBaseAssembly(): int
    {
        return $this->insertAssembly(1);
    }

    private function insertAssembly(int $isBase): int
    {
        // Слоты заполняются теми же категориями, что и у сборки из
        // конфигуратора: колонки NOT NULL без DEFAULT, и пустая строка
        // вставки падала бы на первой же.
        $slots = [];
        foreach ([1 => 'cpu_id', 2 => 'motherboard_id', 4 => 'ram_id', 5 => 'power_supply_id',
                 6 => 'case_id', 7 => 'cooler_id', 9 => 'ssd_id'] as $categoryId => $column) {
            $slots[$column] = (int) $this->pickPart($categoryId)['component_id'];
        }

        $mysql = connect();
        $cols = array_keys($slots);
        $stmt = db_prepare(
            $mysql,
            'INSERT INTO assembly (assembly_name, assembly_price, is_base, ' . implode(', ', $cols) . ')'
                . ' VALUES (?, ?, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ')',
            'sii' . str_repeat('i', count($cols)),
            '#' . bin2hex(random_bytes(4)),
            50000,
            $isBase,
            ...array_values($slots)
        );
        $stmt->execute();
        $id = (int) $mysql->insert_id;
        $stmt->close();
        $mysql->close();

        $this->assemblyIds[] = $id;
        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
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

    private function stockOf(int $componentId): int
    {
        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT amount FROM components WHERE component_id = ?', 'i', $componentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mysql->close();

        return (int) ($row['amount'] ?? 0);
    }

    private function freshToken(string $path): string
    {
        $page = $this->httpGet($path);
        $this->assertSame(200, $page['code']);
        return $this->extractCsrf($page['body']);
    }

    private function loginAsAdmin(): void
    {
        $adminPass = getenv('ADMIN_PASSWORD');
        $this->assertNotEmpty($adminPass, 'ADMIN_PASSWORD не задан в окружении');
        $this->clearLoginAttempts('admin');
        $r = $this->loginAs('admin', (string) $adminPass);
        $this->assertSame(302, $r['code']);
    }

    /**
     * Удалить созданные сборки вместе с заказами и избранным.
     */
    private function dropAssemblies(): void
    {
        if ($this->assemblyIds === []) {
            return;
        }

        $ids = $this->assemblyIds;
        $this->assemblyIds = [];

        $mysql = connect();
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $mysql->begin_transaction();
        try {
            // сначала связи, иначе на них встанет внешний ключ
            foreach (['favorites', 'orders'] as $table) {
                $stmt = db_prepare($mysql, "DELETE FROM `$table` WHERE assembly_id IN ($ph)", $types, ...$ids);
                $stmt->execute();
                $stmt->close();
            }
            $stmt = db_prepare($mysql, "DELETE FROM assembly WHERE assembly_id IN ($ph)", $types, ...$ids);
            $stmt->execute();
            $stmt->close();
            $mysql->commit();
        } catch (Throwable $e) {
            $mysql->rollback();
            $mysql->close();
            throw $e;
        }
        $mysql->close();
    }
}