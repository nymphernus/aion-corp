<?php
/**
 * HomeAssembliesTest — главная страница: гибкий вывод базовых сборок.
 *
 * Тесты проверяют, что главная работает с любым количеством базовых
 * сборок: 0, 3, 4+. Признак базовой — флаг is_base, а не номер.
 */

declare(strict_types=1);

final class HomeAssembliesTest extends AionTestCase
{
    /** @var int[] Базовые сборки, созданные этим тестом */
    private array $createdIds = [];

    /** @var int[] ID базовых сборок на момент начала теста */
    private array $baseIdsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Снимок базовых сборок: тест может снять с них флаг, и без
        // восстановления в tearDown витрина осталась бы пустой для всех
        // следующих тестов.
        $this->baseIdsBefore = $this->baseIdsSnapshot();
    }

    protected function tearDown(): void
    {
        $this->dropCreatedAssemblies();
        $this->restoreBaseFlag($this->baseIdsBefore);
        parent::tearDown();
    }

    /**
     * ID базовых сборок на момент вызова.
     *
     * @return int[]
     */
    private function baseIdsSnapshot(): array
    {
        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT assembly_id FROM assembly WHERE is_base = 1', '');
        $stmt->execute();
        $ids = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $ids[] = (int) $row['assembly_id'];
        }
        $stmt->close();
        $mysql->close();
        return $ids;
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
    private function formData(string $name, int $price): array
    {
        $data = [
            'assemblyAction' => 'save',
            'assemblyId' => '0',
            'assembly_name' => $name,
            'assembly_price' => (string) $price,
        ];
        foreach (assembly_slots() as $column => $categoryId) {
            $data['comp_' . $categoryId] = (string) $this->firstComponent($categoryId);
        }
        return $data;
    }

    private function firstComponent(int $categoryId): int
    {
        $mysql = connect();
        $stmt = db_prepare(
            $mysql,
            'SELECT component_id FROM components WHERE category_id = ? ORDER BY component_id ASC LIMIT 1',
            'i',
            $categoryId
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mysql->close();
        $this->assertNotEmpty($row, "в категории {$categoryId} должен быть хотя бы один компонент");
        return (int) $row['component_id'];
    }

    private function createBaseAssembly(string $name, int $price): int
    {
        $r = $this->httpPost('/admin.php?tab=assemblies', array_merge(
            ['csrf_token' => $this->freshToken()],
            $this->formData($name, $price)
        ));
        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString('saved=1', $r['location']);

        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT assembly_id FROM assembly WHERE assembly_name = ?', 's', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mysql->close();
        $this->assertNotEmpty($row, 'сборка не появилась в таблице');
        return (int) $row['assembly_id'];
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
     * Временно снять флаг is_base у всех базовых сборок.
     *
     * Возвращает ID сборок, у которых флаг был снят, чтобы вернуть его
     * после теста. Простой UPDATE по is_base = 1 не вернул бы флаг тем
     * сборкам, у которых он уже снят.
     *
     * @return int[]
     */
    private function clearBaseFlag(): array
    {
        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT assembly_id FROM assembly WHERE is_base = 1', '');
        $stmt->execute();
        $ids = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $ids[] = (int) $row['assembly_id'];
        }
        $stmt->close();

        $stmt = db_prepare($mysql, 'UPDATE assembly SET is_base = 0 WHERE is_base = 1', '');
        $stmt->execute();
        $stmt->close();
        $mysql->close();
        return $ids;
    }

    /**
     * Вернуть флаг is_base сборкам по их ID.
     *
     * @param int[] $ids
     */
    private function restoreBaseFlag(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $mysql = connect();
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db_prepare($mysql, "UPDATE assembly SET is_base = 1 WHERE assembly_id IN ($ph)", str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $stmt->close();
        $mysql->close();
    }

    private function countBaseAssemblies(): int
    {
        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT COUNT(*) AS c FROM assembly WHERE is_base = 1', '');
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mysql->close();
        return (int) ($row['c'] ?? 0);
    }

    public function testHomeShowsZeroAssemblies(): void
    {
        $this->loginAsAdmin();

        // Снимаем флаг со всех базовых сборок
        $baseIds = $this->clearBaseFlag();

        $page = $this->httpGet('/');
        $this->assertSame(200, $page['code']);
        $this->assertStringNotContainsString('id="assembly"', $page['body'], 'блок сборок не должен рендериться при 0 базовых сборок');
        $this->assertStringNotContainsString('class="build"', $page['body'], 'карточек сборок не должно быть');

        // Возвращаем флаг
        $this->restoreBaseFlag($baseIds);

        $page2 = $this->httpGet('/');
        $this->assertSame(200, $page2['code']);
        $this->assertStringContainsString('id="assembly"', $page2['body'], 'блок сборок должен вернуться');
    }

    public function testHomeShowsFourAssemblies(): void
    {
        $this->loginAsAdmin();

        $before = $this->countBaseAssemblies();
        $this->assertGreaterThanOrEqual(3, $before, 'должно быть хотя бы 3 базовые сборки');

        // Создаём 4-ю базовую сборку
        $name = self::tmpName('tmp_home_');
        $id = $this->createBaseAssembly($name, 150000);
        $this->createdIds[] = $id;

        $page = $this->httpGet('/');
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('data-count="' . ($before + 1) . '"', $page['body'], 'data-count должен быть равен числу базовых сборок');
        $this->assertStringContainsString('is-slider', $page['body'], 'при 4+ сборках должен быть класс is-slider');
        $this->assertStringContainsString($name, $page['body'], 'новая сборка должна быть на главной');
    }

    /**
     * Базовая сборка не должна удаляться cleanup-скриптом.
     *
     * Скрипт запускается при каждом старте контейнера и удаляет сборки
     * без заказов и избранного старше часа. Базовые сборки защищены
     * флагом is_base.
     */
    public function testCleanupDoesNotDeleteBase(): void
    {
        $this->loginAsAdmin();

        $name = self::tmpName('tmp_cleanup_');
        $id = $this->createBaseAssembly($name, 100000);
        $this->createdIds[] = $id;

        // Отодвигаем created_at на 2 часа назад — порог cleanup
        $mysql = connect();
        $stmt = db_prepare($mysql, 'UPDATE assembly SET created_at = ? WHERE assembly_id = ?', 'si', date('Y-m-d H:i:s', time() - 7200), $id);
        $stmt->execute();
        $stmt->close();
        $mysql->close();

        // Запускаем cleanup
        $output = shell_exec('php /var/www/html/scripts/cleanup_orphans.php --hours=1 2>&1');
        $this->assertStringNotContainsString('Fatal error', (string) $output, 'cleanup не должен падать');

        // Сборка должна остаться
        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT assembly_id FROM assembly WHERE assembly_id = ?', 'i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mysql->close();
        $this->assertNotEmpty($row, 'базовая сборка не должна удаляться cleanup-скриптом');
    }

    /**
     * Пользовательская сборка удаляется cleanup-скриптом.
     *
     * Проверяем, что cleanup работает как раньше для небазовых сборок.
     */
    public function testCleanupDeletesUserAssembly(): void
    {
        $this->loginAsAdmin();

        // Создаём пользовательскую сборку напрямую (is_base = 0)
        $mysql = connect();
        $parts = [];
        foreach (assembly_slots() as $column => $categoryId) {
            $stmt = db_prepare($mysql, 'SELECT component_id FROM components WHERE category_id = ? ORDER BY component_id ASC LIMIT 1', 'i', $categoryId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $parts[$column] = (int) $row['component_id'];
        }

        $stmt = db_prepare(
            $mysql,
            'INSERT INTO assembly (assembly_name, cpu_id, motherboard_id, gpu_id, ram_id, case_id, cooler_id, power_supply_id, ssd_id, assembly_price, is_base, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)',
            'siiiiiiiiis',
            self::tmpName('tmp_orphan_'),
            $parts['cpu_id'],
            $parts['motherboard_id'],
            $parts['gpu_id'],
            $parts['ram_id'],
            $parts['case_id'],
            $parts['cooler_id'],
            $parts['power_supply_id'],
            $parts['ssd_id'],
            1000,
            date('Y-m-d H:i:s', time() - 7200)
        );
        $stmt->execute();
        $id = (int) $mysql->insert_id;
        $stmt->close();
        $mysql->close();

        // Запускаем cleanup
        $output = shell_exec('php /var/www/html/scripts/cleanup_orphans.php --hours=1 2>&1');
        $this->assertStringNotContainsString('Fatal error', (string) $output, 'cleanup не должен падать');
        $this->assertStringContainsString('Удалено сборок:', (string) $output, 'cleanup должен что-то удалить');

        // Сборка должна быть удалена
        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT assembly_id FROM assembly WHERE assembly_id = ?', 'i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mysql->close();
        $this->assertEmpty($row, 'пользовательская сборка должна удаляться cleanup-скриптом');
    }

    /**
     * Имя строки фикстуры: маркер для уборки плюс уникальный хвост.
     */
    private static function tmpName(string $prefix): string
    {
        return $prefix . substr((string) time(), -6) . '_' . bin2hex(random_bytes(3));
    }
}
