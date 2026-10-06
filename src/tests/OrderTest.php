<?php
/**
 * OrderTest — покупка сборки и складские остатки.
 *
 * Тесты бьют по живым POST: списание остатков и проверка нехватки
 * живут в обработчике assembly.php, и юнит-тест на уровне функции
 * проверял бы намерение, а не то, что запрос действительно ушёл в
 * базу вместе с заказом.
 *
 * Сборка для теста создаётся напрямую, а не конфигуратором: её
 * состав известен до покупки, и остатки можно проверить поштучно.
 * Зависимости от демо-сборок тут нет - номера компонентов берутся
 * запросом «первые три с остатком», а не из готовой #4.
 *
 * Один компонент стоит в трёх слотах (процессор, корпус, накопитель),
 * другой - в двух. Так проверяется счётчик в assembly_demand: списание
 * по списку id убрало бы единицу вместо трёх.
 */

declare(strict_types=1);

final class OrderTest extends AionTestCase
{
    /** @var int[] Сборки, созданные этим тестом */
    private array $assemblyIds = [];

    /** @var array{0: int, 1: int, 2: int} Компоненты a, b, c */
    private array $parts = [0, 0, 0];

    protected function tearDown(): void
    {
        // Остатки возвращает родительский tearDown - общий для всех
        // тестов, чтобы никто не забыл про склад.
        $this->dropTestAssemblies();
        parent::tearDown();
    }

    /**
     * Пользователь и сборка из трёх компонентов.
     *
     * a стоит в слотах cpu, case и ssd - три единицы,
     * b - в motherboard и cooler - две,
     * c - в ram и power_supply - две.
     *
     * @return array{0: int, 1: int} user_id, assembly_id
     */
    private function arrangePurchase(): array
    {
        $login = $this->uniqueLogin('buyer_');
        $this->trackCleanup($login);

        $mysql = connect();
        $hash = password_hash('password123', PASSWORD_BCRYPT);
        $stmt = db_prepare(
            $mysql,
            "INSERT INTO users (user_name, user_login, user_pass, user_group) VALUES (?, ?, ?, ?)",
            'ssss',
            'Покупатель',
            $login,
            $hash,
            'user'
        );
        $stmt->execute();
        $userId = (int) $mysql->insert_id;

        // Номера берутся из наличия, а не из демо-данных: с составом
        // сборки 4 пришлось бы гадать, есть ли её детали в сиде.
        $parts = [];
        $stmt = db_prepare($mysql, "SELECT component_id FROM components WHERE amount > 0 ORDER BY component_id LIMIT 3", '');
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $parts[] = (int) $row['component_id'];
        }
        $stmt->close();

        $this->assertCount(3, $parts, 'в базе должно быть хотя бы три компонента в наличии');
        [$a, $b, $c] = $parts;
        $this->parts = [$a, $b, $c];

        $stmt = db_prepare(
            $mysql,
            "INSERT INTO assembly
                (assembly_name, cpu_id, motherboard_id, ram_id, case_id, cooler_id, power_supply_id, ssd_id, assembly_price)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            'siiiiiiii',
            'tmp_order_build',
            $a,
            $b,
            $c,
            $a,
            $b,
            $c,
            $a,
            1000
        );
        $stmt->execute();
        $assemblyId = (int) $mysql->insert_id;
        $mysql->close();

        $this->assemblyIds[] = $assemblyId;
        $this->snapshotStock([$a, $b, $c]);

        $r = $this->loginAs($login, 'password123');
        $this->assertSame(302, $r['code']);

        return [$userId, $assemblyId];
    }

    /**
     * Нажатие «Купить» на странице сборки.
     *
     * Форма отправляется на текущий адрес, поэтому id сборки едет в
     * GET - так же, как в браузере.
     *
     * @return array{code: int, body: string, location: string}
     */
    private function buy(int $assemblyId): array
    {
        $page = $this->httpGet('/assembly.php?id=' . $assemblyId);
        $this->assertSame(200, $page['code']);

        return $this->httpPost('/assembly.php?id=' . $assemblyId, [
            'buy' => '1',
            'csrf_token' => $this->extractCsrf($page['body']),
        ]);
    }

    private function countOrders(int $userId, int $assemblyId): int
    {
        $mysql = connect();
        $stmt = db_prepare($mysql, "SELECT COUNT(*) AS c FROM orders WHERE user_id = ? AND assembly_id = ?", 'ii', $userId, $assemblyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mysql->close();

        return (int) ($row['c'] ?? 0);
    }

    private function orderId(int $userId, int $assemblyId): int
    {
        $mysql = connect();
        $stmt = db_prepare($mysql, "SELECT order_id FROM orders WHERE user_id = ? AND assembly_id = ?", 'ii', $userId, $assemblyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mysql->close();

        $this->assertNotEmpty($row, 'заказ должен существовать');
        return (int) $row['order_id'];
    }

    private function orderStatus(int $orderId): string
    {
        $mysql = connect();
        $stmt = db_prepare($mysql, "SELECT status FROM orders WHERE order_id = ?", 'i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mysql->close();

        return (string) ($row['status'] ?? '');
    }

    /**
     * Вход админом в тот же cookie-jar.
     *
     * Отдельный jar не годится: смена статуса идёт POST-ом в
     * admin.php, и нужен именно админский.
     */
    private function loginAsAdmin(): void
    {
        $adminPass = getenv('ADMIN_PASSWORD');
        $this->assertNotEmpty($adminPass, 'ADMIN_PASSWORD не задан в окружении');
        $this->clearLoginAttempts('admin');
        $r = $this->loginAs('admin', (string) $adminPass);
        $this->assertSame(302, $r['code']);
    }

    /**
     * Смена статуса заказа из модалки админки.
     *
     * @return array{code: int, body: string, location: string}
     */
    private function setOrderStatus(int $orderId, string $status): array
    {
        $page = $this->httpGet('/admin.php?tab=orders');
        $this->assertSame(200, $page['code']);

        return $this->httpPost('/admin.php?tab=orders', [
            'editOrder' => '1',
            'orderId' => (string) $orderId,
            'status' => $status,
            'csrf_token' => $this->extractCsrf($page['body']),
        ]);
    }

    private function dropTestAssemblies(): void
    {
        if ($this->assemblyIds === []) {
            return;
        }

        $mysql = connect();
        foreach ($this->assemblyIds as $id) {
            $stmt = db_prepare($mysql, "DELETE FROM orders WHERE assembly_id = ?", 'i', $id);
            $stmt->execute();
            $stmt->close();
            $stmt = db_prepare($mysql, "DELETE FROM favorites WHERE assembly_id = ?", 'i', $id);
            $stmt->execute();
            $stmt->close();
            $stmt = db_prepare($mysql, "DELETE FROM assembly WHERE assembly_id = ?", 'i', $id);
            $stmt->execute();
            $stmt->close();
        }
        $this->assemblyIds = [];
        $mysql->close();
    }

    public function testBuyDecreasesStockOncePerSlot(): void
    {
        [$userId, $assemblyId] = $this->arrangePurchase();
        [$a, $b, $c] = $this->parts;

        $before = [
            $a => $this->componentAmount($a),
            $b => $this->componentAmount($b),
            $c => $this->componentAmount($c),
        ];

        $r = $this->buy($assemblyId);

        $this->assertSame(302, $r['code']);
        $this->assertSame(1, $this->countOrders($userId, $assemblyId), 'заказ должен появиться в orders');

        // a стоит в трёх слотах, b - в двух, c - в двух
        $this->assertSame($before[$a] - 3, $this->componentAmount($a), 'компонент в трёх слотах списывается трижды');
        $this->assertSame($before[$b] - 2, $this->componentAmount($b), 'компонент в двух слотах списывается дважды');
        $this->assertSame($before[$c] - 2, $this->componentAmount($c), 'второй компонент в двух слотах списывается дважды');
    }

    public function testBuyRefusesWhenComponentRanOut(): void
    {
        [$userId, $assemblyId] = $this->arrangePurchase();
        [$a, $b, $c] = $this->parts;

        // a нужен трижды, а остаток один - нехватка
        $this->setComponentAmounts([$a], 1);
        $before = [
            $a => $this->componentAmount($a),
            $b => $this->componentAmount($b),
            $c => $this->componentAmount($c),
        ];

        $r = $this->buy($assemblyId);

        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString('error=out_of_stock', $r['location'], 'отказ должен вести на страницу с сообщением');
        $this->assertSame(0, $this->countOrders($userId, $assemblyId), 'заказ без товара создаваться не должен');

        $this->assertSame($before[$a], $this->componentAmount($a), 'остатки при отказе не трогаются');
        $this->assertSame($before[$b], $this->componentAmount($b));
        $this->assertSame($before[$c], $this->componentAmount($c));
    }

    public function testBuyPageExplainsWhichComponentRanOut(): void
    {
        [, $assemblyId] = $this->arrangePurchase();
        [$a] = $this->parts;

        $this->setComponentAmounts([$a], 1);
        $before = $this->componentAmount($a);

        $r = $this->buy($assemblyId);
        $this->assertStringContainsString('error=out_of_stock', $r['location']);

        // Сообщение приходит кодом в адресе, а не cookie: cookie без
        // кода показания была бы забыта при следующем редиректе.
        $page = $this->httpGet('/assembly.php?id=' . $assemblyId);
        $this->assertSame(200, $page['code']);
        $this->assertStringNotContainsString('alert--error', $page['body'], 'без кода в адресе ошибки быть не должно');

        preg_match('/part=(\d+)/', $r['location'], $m);
        $this->assertNotEmpty($m, 'адрес должен содержать номер закончившегося компонента');

        $mysql = connect();
        $stmt = db_prepare($mysql, "SELECT component_name FROM components WHERE component_id = ?", 'i', (int) $m[1]);
        $stmt->execute();
        $name = (string) ($stmt->get_result()->fetch_assoc()['component_name'] ?? '');
        $stmt->close();
        $mysql->close();

        $shown = $this->httpGet('/assembly.php?id=' . $assemblyId . '&error=out_of_stock&part=' . (int) $m[1]);
        $this->assertStringContainsString('alert--error', $shown['body'], 'страница обязана объяснить отказ');
        $this->assertStringContainsString(
            htmlspecialchars($name, ENT_QUOTES),
            $shown['body'],
            'в сообщении должно быть название закончившегося компонента'
        );

        $this->assertSame($before, $this->componentAmount($a));
    }

    public function testRepeatedBuyDoesNotChargeTwice(): void
    {
        [$userId, $assemblyId] = $this->arrangePurchase();
        [$a, $b, $c] = $this->parts;

        $beforeA = $this->componentAmount($a);
        $beforeB = $this->componentAmount($b);
        $beforeC = $this->componentAmount($c);

        $this->assertSame(302, $this->buy($assemblyId)['code']);
        // Второе нажатие «Купить» - перезагрузка формы, а не новая сделка
        $this->assertSame(302, $this->buy($assemblyId)['code']);

        $this->assertSame(1, $this->countOrders($userId, $assemblyId), 'второй заказ создаваться не должен');
        $this->assertSame($beforeA - 3, $this->componentAmount($a), 'остатки списываются один раз');
        $this->assertSame($beforeB - 2, $this->componentAmount($b));
        $this->assertSame($beforeC - 2, $this->componentAmount($c));
    }

    /**
     * Откат, когда остаток кончился между проверкой и списанием.
     *
     * Через HTTP это не воспроизвести: проверка и списание идут в
     * одном запросе. Поэтому здесь тот же порядок действий вручную -
     * заказ вставлен, списание упало, откат. Проверяется именно то,
     * что заказ не остаётся: незакрытый остаток после отказа - это
     * проданный в минус товар.
     */
    public function testOrderIsDroppedWhenStockGuardFails(): void
    {
        [$userId, $assemblyId] = $this->arrangePurchase();
        [$a, $b, $c] = $this->parts;

        $this->setComponentAmounts([$a], 2);
        $before = [
            $a => $this->componentAmount($a),
            $b => $this->componentAmount($b),
            $c => $this->componentAmount($c),
        ];

        $demand = assembly_demand([
            'cpu_id' => $a,
            'motherboard_id' => $b,
            'ram_id' => $c,
            'case_id' => $a,
            'cooler_id' => $b,
            'power_supply_id' => $c,
            'ssd_id' => $a,
        ]);

        $mysql = connect();
        $mysql->begin_transaction();
        $thrown = false;
        try {
            $stmt = db_prepare(
                $mysql,
                "INSERT INTO orders (user_id, assembly_id, status) VALUES (?, ?, ?)",
                'iis',
                $userId,
                $assemblyId,
                'Обрабатывается'
            );
            $stmt->execute();
            $stmt->close();

            stock_apply($mysql, $demand, -1);
            $this->fail('списание без остатка должно было бросить исключение');
        } catch (StockShortage) {
            $thrown = true;
            $mysql->rollback();
        } finally {
            $mysql->close();
        }

        $this->assertTrue($thrown, 'ожидался StockShortage');
        $this->assertSame(0, $this->countOrders($userId, $assemblyId), 'заказ должен исчезнуть вместе с откатом');
        $this->assertSame($before[$a], $this->componentAmount($a), 'частичное списание не должно оставаться');
        $this->assertSame($before[$b], $this->componentAmount($b));
        $this->assertSame($before[$c], $this->componentAmount($c));
    }

    /**
     * Списание и возврат симметричны.
     *
     * Проверяется напрямую: возврат при отмене заказа появится
     * следующим шагом, а stock_apply() живёт в общем модуле и
     * должен работать в обе стороны сразу.
     */
    public function testStockApplyRestoresWhatItDeducted(): void
    {
        // arrangePurchase() нужен и ради $this->parts, и ради снимка
        // остатков: без него номера компонентов остались бы нулевыми.
        $this->arrangePurchase();
        [$a, $b, $c] = $this->parts;

        // Остаток поднимается до 9: у сид-компонента его может быть
        // меньше трёх, и проверка падала бы из-за нехватки, а не из-за
        // списания. Исходные значения остаются в снимке tearDown.
        $this->setComponentAmounts([$a, $b, $c], 9);

        $before = $this->componentAmount($a);
        $demand = [$a => 3, $b => 2];

        $mysql = connect();
        stock_apply($mysql, $demand, -1);
        $mysql->close();

        $this->assertSame($before - 3, $this->componentAmount($a), 'списание уменьшает остаток на число слотов');

        $mysql = connect();
        stock_apply($mysql, $demand, 1);
        $mysql->close();

        $this->assertSame($before, $this->componentAmount($a), 'возврат должен вернуть остаток до единицы');
    }

    /**
     * Списание никогда не уводит остаток в минус.
     *
     * Условие остатка стоит внутри UPDATE, а не только в проверке до
     * транзакции: два одновременных запроса проходят предварительную
     * проверку оба, и без условия второй записал бы -1.
     */
    public function testStockApplyNeverGoesBelowZero(): void
    {
        $this->arrangePurchase();
        [$a] = $this->parts;
        $this->setComponentAmounts([$a], 0);

        $mysql = connect();
        $thrown = false;
        try {
            stock_apply($mysql, [$a => 1], -1);
        } catch (StockShortage) {
            $thrown = true;
        }
        $mysql->close();

        $this->assertTrue($thrown, 'списание при нулевом остатке должно отклоняться');
        $this->assertSame(0, $this->componentAmount($a), 'остаток не должен стать отрицательным');
    }

    public function testAssemblyDemandCountsRepeatedSlots(): void
    {
        // Функция без базы: одна карта, три слота - три единицы
        $demand = assembly_demand([
            'cpu_id' => 7,
            'motherboard_id' => 7,
            'ram_id' => 9,
            'case_id' => 0,
            'cooler_id' => null,
            'power_supply_id' => 9,
            'ssd_id' => 7,
            'ssd_2_id' => 11,
            'hdd_id' => null,
            'dvd_id' => 0,
            // эти два поля - не компоненты и не должны попасть в карту
            'assembly_price' => 40909,
            'os' => 'Windows 10 Home',
        ]);

        $this->assertSame([7 => 3, 9 => 2, 11 => 1], $demand);
        $this->assertArrayNotHasKey(40909, $demand, 'цена сборки не является компонентом');
        $this->assertArrayNotHasKey(0, $demand, 'нулевые слоты пропускаются');
    }

    public function testCancelOrderRestoresStock(): void
    {
        [$userId, $assemblyId] = $this->arrangePurchase();
        [$a, $b, $c] = $this->parts;

        $before = $this->componentAmount($a);

        $this->buy($assemblyId);
        $this->assertSame($before - 3, $this->componentAmount($a), 'до отмены остаток уменьшен');

        $orderId = $this->orderId($userId, $assemblyId);
        $this->loginAsAdmin();

        $r = $this->setOrderStatus($orderId, 'Отменён');
        $this->assertSame(302, $r['code']);
        $this->assertSame('Отменён', $this->orderStatus($orderId));

        $this->assertSame($before, $this->componentAmount($a), 'отмена возвращает три слота процессора');
        $this->assertSame($this->stockSnapshot[$b], $this->componentAmount($b));
        $this->assertSame($this->stockSnapshot[$c], $this->componentAmount($c));
    }

    public function testUncancelOrderDeductsStockAgain(): void
    {
        [$userId, $assemblyId] = $this->arrangePurchase();
        [$a, $b] = $this->parts;
        $before = $this->componentAmount($a);

        $this->buy($assemblyId);
        $orderId = $this->orderId($userId, $assemblyId);
        $this->loginAsAdmin();

        $this->setOrderStatus($orderId, 'Отменён');
        $this->assertSame($before, $this->componentAmount($a), 'после отмены остаток полный');

        // снятие отмены: заказ снова в работе, товар снова продан
        $r = $this->setOrderStatus($orderId, 'Собирается');
        $this->assertSame(302, $r['code']);
        $this->assertSame('Собирается', $this->orderStatus($orderId));

        $this->assertSame($before - 3, $this->componentAmount($a), 'снятие отмены списывает обратно');
        $this->assertSame($this->stockSnapshot[$b] - 2, $this->componentAmount($b));
    }

    /**
     * Повторная отправка формы ничего не меняет.
     *
     * Ключевой случай: без проверки старого статуса второй POST с
     * «Отменён» вернул бы товар ещё раз, и склад раздувался бы на
     * единицу за каждое переоткрытие страницы.
     */
    public function testRepeatedStatusDoesNotTouchStock(): void
    {
        [$userId, $assemblyId] = $this->arrangePurchase();
        [$a] = $this->parts;
        $before = $this->componentAmount($a);

        $this->buy($assemblyId);
        $orderId = $this->orderId($userId, $assemblyId);
        $this->loginAsAdmin();

        // тот же статус, что уже стоит
        $this->setOrderStatus($orderId, 'Обрабатывается');
        $this->assertSame($before - 3, $this->componentAmount($a), 'тот же статус не должен трогать склад');

        $this->setOrderStatus($orderId, 'Отменён');
        $this->assertSame($before, $this->componentAmount($a));

        // отмена ещё раз, форма переоткрыта и отправлена повторно
        $this->setOrderStatus($orderId, 'Отменён');
        $this->setOrderStatus($orderId, 'Отменён');
        $this->assertSame($before, $this->componentAmount($a), 'повторная отмена не должна возвращать товар ещё раз');
    }

    /**
     * Статусы кроме отмены склад не трогают.
     */
    public function testNonCancelledStatusLeavesStockAlone(): void
    {
        [$userId, $assemblyId] = $this->arrangePurchase();
        [$a] = $this->parts;
        $before = $this->componentAmount($a);

        $this->buy($assemblyId);
        $orderId = $this->orderId($userId, $assemblyId);
        $this->loginAsAdmin();

        $this->setOrderStatus($orderId, 'Доставляется');
        $this->assertSame('Доставляется', $this->orderStatus($orderId));
        $this->assertSame($before - 3, $this->componentAmount($a));

        $this->setOrderStatus($orderId, 'Выполнен');
        $this->assertSame('Выполнен', $this->orderStatus($orderId));
        $this->assertSame($before - 3, $this->componentAmount($a), 'выполненный заказ товар не возвращает');
    }

    /**
     * Статус вне белого списка отклоняется.
     *
     * Раньше у обработчика editOrderStatus белого списка не было
     * вовсе, и строка из POST писалась в таблицу как есть.
     */
    public function testUnknownStatusIsRejected(): void
    {
        [$userId, $assemblyId] = $this->arrangePurchase();
        [$a] = $this->parts;
        $before = $this->componentAmount($a);

        $this->buy($assemblyId);
        $orderId = $this->orderId($userId, $assemblyId);
        $this->loginAsAdmin();

        $this->setOrderStatus($orderId, 'Отменён; DROP TABLE orders');
        $this->assertSame('Обрабатывается', $this->orderStatus($orderId), 'чужой статус не должен попасть в таблицу');
        $this->assertSame($before - 3, $this->componentAmount($a), 'отклонённый статус склад не трогает');

        // «Отменён» с хвостом - тоже не «Отменён»: сравнение строгое,
        // иначе такой статус вернул бы товар на склад
        $this->setOrderStatus($orderId, 'Отменён ');
        $this->assertSame('Обрабатывается', $this->orderStatus($orderId));
    }
}
