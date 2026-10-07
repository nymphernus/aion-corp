<?php
/**
 * AdminLogTest — журнал действий администратора (admin_actions).
 *
 * Тесты бьют по живым POST-запросам: журнал пишется в обработчиках
 * admin.php после успешного UPDATE/INSERT/DELETE, и расхождение между
 * кодом действия и его записью видно только при выполнении запроса.
 *
 * Каждый тест маркирует верхнюю границу журнала в setUp и в tearDown
 * удаляет всё, что появилось выше: журнал - общая таблица, и чужие
 * записи (логины других тестов) трогать нельзя.
 */

declare(strict_types=1);

final class AdminLogTest extends AionTestCase
{
    /** action_id на момент начала теста: всё выше - записи этого теста */
    private int $markId = 0;

    /** @var int[] id компонентов, созданных тестом */
    private array $componentIds = [];

    /** @var int[] id заказов, созданных тестом */
    private array $orderIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->markId = $this->maxActionId();
    }

    protected function tearDown(): void
    {
        $mysql = connect();
        foreach ($this->componentIds as $id) {
            $stmt = db_prepare($mysql, 'DELETE FROM components WHERE component_id = ?', 'i', $id);
            $stmt->execute();
            $stmt->close();
        }
        foreach ($this->orderIds as $id) {
            $stmt = db_prepare($mysql, 'DELETE FROM orders WHERE order_id = ?', 'i', $id);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = db_prepare($mysql, 'DELETE FROM admin_actions WHERE action_id > ?', 'i', $this->markId);
        $stmt->execute();
        $stmt->close();
        $mysql->close();

        $this->componentIds = [];
        $this->orderIds = [];
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Вспомогательные
    // ------------------------------------------------------------------

    private function maxActionId(): int
    {
        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT COALESCE(MAX(action_id), 0) FROM admin_actions', '');
        $stmt->execute();
        $id = (int) $stmt->get_result()->fetch_row()[0];
        $stmt->close();
        $mysql->close();

        return $id;
    }

    /**
     * Записи журнала, появившиеся после начала теста.
     *
     * @return array<int, array{action:string, entity_type:?string, entity_id:?int, details:?string, user_login:string}>
     */
    private function rowsSinceMark(?string $action = null): array
    {
        $mysql = connect();
        $sql = 'SELECT action, entity_type, entity_id, details, user_login
                FROM admin_actions WHERE action_id > ?';
        $types = 'i';
        $params = [$this->markId];
        if ($action !== null) {
            $sql .= ' AND action = ?';
            $types .= 's';
            $params[] = $action;
        }
        $sql .= ' ORDER BY action_id';
        $stmt = db_prepare($mysql, $sql, $types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $mysql->close();

        return $rows;
    }

    private function loginAsAdmin(): void
    {
        $adminPass = getenv('ADMIN_PASSWORD');
        $this->assertNotEmpty($adminPass, 'ADMIN_PASSWORD не задан в окружении');
        // лимит попыток входа общий: без сброса шестой прогон подряд
        // ловил 429 - как в остальных админ-тестах
        $this->clearLoginAttempts('admin');
        $r = $this->loginAs('admin', (string) $adminPass);
        $this->assertSame(302, $r['code']);
    }

    private function freshToken(string $tab): string
    {
        $page = $this->httpGet('/admin.php?tab=' . $tab);
        $this->assertSame(200, $page['code']);

        return $this->extractCsrf($page['body']);
    }

    private static function uniqueName(string $prefix): string
    {
        return $prefix . ' ' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    /**
     * Создание компонента через addComponent. Возвращает component_id.
     */
    private function createComponent(string $name): int
    {
        $r = $this->httpPost('/admin.php?tab=components', [
            'addComponent' => '1',
            'nm' => $name,
            'pr' => '1000',
            'col' => '5',
            'cat' => '4',
            'csrf_token' => $this->freshToken('components'),
        ]);
        $this->assertSame(302, $r['code']);

        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT component_id FROM components WHERE component_name = ?', 's', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mysql->close();
        $this->assertNotEmpty($row, 'компонент не создан');

        $id = (int) $row['component_id'];
        $this->componentIds[] = $id;

        return $id;
    }

    // ------------------------------------------------------------------
    // Логин / логаут
    // ------------------------------------------------------------------

    public function testLoginSuccessLogged(): void
    {
        $this->loginAsAdmin();

        $rows = $this->rowsSinceMark('auth.login_success');
        $this->assertCount(1, $rows, 'вход админа должен попасть в журнал');
        $this->assertSame('admin', $rows[0]['user_login']);
        $this->assertSame('user', $rows[0]['entity_type']);
        $details = json_decode((string) $rows[0]['details'], true);
        $this->assertSame('admin', $details['login'] ?? null);
    }

    public function testLoginUserNotLogged(): void
    {
        $login = $this->uniqueLogin('loguser_');
        $this->trackCleanup($login);

        $mysql = connect();
        $stmt = db_prepare(
            $mysql,
            'INSERT INTO users (user_name, user_login, user_pass, user_group) VALUES (?, ?, ?, ?)',
            'ssss',
            'Loguser',
            $login,
            password_hash('password123', PASSWORD_BCRYPT),
            'user'
        );
        $stmt->execute();
        $stmt->close();
        $mysql->close();

        $r = $this->loginAs($login, 'password123');
        $this->assertSame(302, $r['code']);

        // вход обычного пользователя - не действие в админке
        $rows = $this->rowsSinceMark('auth.login_success');
        $this->assertCount(0, $rows, 'вход не-админа не должен логироваться');
    }

    public function testLogoutLogged(): void
    {
        $this->loginAsAdmin();

        $out = $this->httpGet('/validation/exit.php');
        $this->assertSame(302, $out['code']);

        // logout пишется ДО session_destroy - проверяем, что успел
        $rows = $this->rowsSinceMark('auth.logout');
        $this->assertCount(1, $rows, 'выход админа должен попасть в журнал');
        $this->assertSame('admin', $rows[0]['user_login']);
    }

    // ------------------------------------------------------------------
    // CRUD компонентов
    // ------------------------------------------------------------------

    public function testComponentCreateLogged(): void
    {
        $this->loginAsAdmin();
        $name = self::uniqueName('LogComp');
        $id = $this->createComponent($name);

        $rows = $this->rowsSinceMark('component.create');
        $this->assertCount(1, $rows, 'создание компонента должно логироваться');
        $this->assertSame('component', $rows[0]['entity_type']);
        $this->assertSame($id, (int) $rows[0]['entity_id']);
        $details = json_decode((string) $rows[0]['details'], true);
        $this->assertSame($name, $details['name'] ?? null);
    }

    public function testComponentUpdateLogged(): void
    {
        $this->loginAsAdmin();
        $name = self::uniqueName('LogUpd');
        $id = $this->createComponent($name);
        $newName = $name . ' v2';

        $r = $this->httpPost('/admin.php?tab=components', [
            'addComponent' => '1',
            'editComponentId' => (string) $id,
            'nm' => $newName,
            'pr' => '2000',
            'col' => '7',
            'cat' => '4',
            'csrf_token' => $this->freshToken('components'),
        ]);
        $this->assertSame(302, $r['code']);

        $rows = $this->rowsSinceMark('component.update');
        $this->assertCount(1, $rows, 'правка компонента должна логироваться');
        $this->assertSame($id, (int) $rows[0]['entity_id']);
        $details = json_decode((string) $rows[0]['details'], true);
        $this->assertSame($newName, $details['name'] ?? null, 'в details идёт новое имя');
    }

    public function testComponentDeleteLogged(): void
    {
        $this->loginAsAdmin();
        $name = self::uniqueName('LogDel');
        $id = $this->createComponent($name);

        $r = $this->httpPost('/admin.php?tab=components', [
            'deleteComponent' => '1',
            'deleteComponentId' => (string) $id,
            'csrf_token' => $this->freshToken('components'),
        ]);
        $this->assertSame(302, $r['code']);

        $rows = $this->rowsSinceMark('component.delete');
        $this->assertCount(1, $rows, 'удаление компонента должно логироваться');
        $this->assertSame($id, (int) $rows[0]['entity_id']);
        // имя берётся ДО DELETE: в журнале остаётся читаемая копия
        $details = json_decode((string) $rows[0]['details'], true);
        $this->assertSame($name, $details['name'] ?? null);
    }

    // ------------------------------------------------------------------
    // Заказы
    // ------------------------------------------------------------------

    public function testOrderStatusChangeLogged(): void
    {
        $this->loginAsAdmin();

        $mysql = connect();
        // заказ от имени админа на существующей сборке: FK user_id и
        // assembly_id обязаны попадать в реальные строки
        $stmt = db_prepare($mysql, 'SELECT user_id FROM users WHERE user_login = ?', 's', 'admin');
        $stmt->execute();
        $adminId = (int) $stmt->get_result()->fetch_row()[0];
        $stmt->close();

        $stmt = db_prepare($mysql, 'SELECT assembly_id FROM assembly ORDER BY assembly_id LIMIT 1', '');
        $stmt->execute();
        $assemblyId = (int) $stmt->get_result()->fetch_row()[0];
        $stmt->close();

        $stmt = db_prepare(
            $mysql,
            "INSERT INTO orders (user_id, assembly_id, status) VALUES (?, ?, 'Обрабатывается')",
            'ii',
            $adminId,
            $assemblyId
        );
        $stmt->execute();
        $orderId = (int) $mysql->insert_id;
        $stmt->close();
        $mysql->close();
        $this->orderIds[] = $orderId;

        $page = $this->httpGet('/admin.php?tab=orders');
        $this->assertSame(200, $page['code']);
        $r = $this->httpPost('/admin.php?tab=orders', [
            'editOrder' => '1',
            'orderId' => (string) $orderId,
            'status' => 'Собирается',
            'csrf_token' => $this->extractCsrf($page['body']),
        ]);
        $this->assertSame(302, $r['code']);

        $rows = $this->rowsSinceMark('order.status_change');
        $this->assertCount(1, $rows, 'смена статуса должна логироваться');
        $this->assertSame('order', $rows[0]['entity_type']);
        $this->assertSame($orderId, (int) $rows[0]['entity_id']);
        $details = json_decode((string) $rows[0]['details'], true);
        $this->assertSame('Обрабатывается', $details['from'] ?? null, 'from - статус до смены');
        $this->assertSame('Собирается', $details['to'] ?? null, 'to - новый статус');
    }

    // ------------------------------------------------------------------
    // Вкладка «Журнал»
    // ------------------------------------------------------------------

    public function testLogTabShowsRecords(): void
    {
        $this->loginAsAdmin();
        // уникальный маркер в details: он приходит из создаваемого
        // компонента и проверяет путь «обработчик -> БД -> вкладка»
        $name = self::uniqueName('LogUi');
        $this->createComponent($name);

        $page = $this->httpGet('/admin.php?tab=log');
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('Журнал действий', $page['body']);
        $this->assertStringContainsString(
            $name,
            $page['body'],
            'свежая запись с именем компонента должна быть на вкладке'
        );
    }

    public function testLogFilterByAction(): void
    {
        $this->loginAsAdmin();
        $name = self::uniqueName('LogFilt');
        $this->createComponent($name);

        $match = $this->httpGet('/admin.php?tab=log&action=component.create');
        $this->assertSame(200, $match['code']);
        $this->assertStringContainsString($name, $match['body'], 'фильтр по своему действию показывает запись');

        $miss = $this->httpGet('/admin.php?tab=log&action=os.delete');
        $this->assertSame(200, $miss['code']);
        $this->assertStringNotContainsString(
            $name,
            $miss['body'],
            'фильтр по чужому действию не должен показывать запись component.create'
        );
    }

    // ------------------------------------------------------------------
    // Ротация
    // ------------------------------------------------------------------

    public function testLogRotation(): void
    {
        $mysql = connect();

        // запись старше 90 дней + свежая контрольная: ротация должна
        // удалить первую и не тронуть вторую
        $stmt = db_prepare(
            $mysql,
            "INSERT INTO admin_actions
             (user_id, user_login, action, entity_type, ip, created_at)
             VALUES (1, 'rotation_old', 'rotation.test', 'preset', '127.0.0.1',
                     NOW() - INTERVAL 100 DAY)",
            ''
        );
        $stmt->execute();
        $oldId = (int) $mysql->insert_id;
        $stmt->close();

        $stmt = db_prepare(
            $mysql,
            "INSERT INTO admin_actions
             (user_id, user_login, action, entity_type, ip)
             VALUES (1, 'rotation_new', 'rotation.test', 'preset', '127.0.0.1')",
            ''
        );
        $stmt->execute();
        $newId = (int) $mysql->insert_id;
        $stmt->close();
        $mysql->close();

        // та же команда, что в docker/entrypoint.sh при старте контейнера
        $out = (string) shell_exec('php /var/www/html/scripts/migrate.php 2>&1');
        $this->assertStringContainsString('migrate завершён', $out, 'миграция должна отработать');

        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT COUNT(*) FROM admin_actions WHERE action_id = ?', 'i', $oldId);
        $stmt->execute();
        $oldLeft = (int) $stmt->get_result()->fetch_row()[0];
        $stmt->close();

        $stmt = db_prepare($mysql, 'SELECT COUNT(*) FROM admin_actions WHERE action_id = ?', 'i', $newId);
        $stmt->execute();
        $newLeft = (int) $stmt->get_result()->fetch_row()[0];
        $stmt->close();
        $mysql->close();

        $this->assertSame(0, $oldLeft, 'запись старше 90 дней должна быть удалена');
        $this->assertSame(1, $newLeft, 'свежая запись переживает ротацию');
    }
}
