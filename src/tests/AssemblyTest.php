<?php
/**
 * AssemblyTest — конфигуратор, save/buy (требует логина).
 */

declare(strict_types=1);

final class AssemblyTest extends AionTestCase
{
    /**
     * Сборки, которые создал этот класс.
     *
     * 5-b, фикс 1. Конфигуратор создаёт сборку на каждом прогоне, и раньше
     * тест удалял связи, но оставлял саму сборку - она оставалась сиротой
     * навсегда. Список нужен, чтобы удалять точно то, что мы создали, а не
     * всё подряд по времени.
     *
     * @var int[]
     */
    private static array $createdAssemblyIds = [];

    /**
     * Уборка после всех тестов класса.
     *
     * Сначала удаляются сборки, чьи номера мы точно знаем, вместе с их
     * избранным и заказами. Потом - все сироты за последние пять минут: если
     * тест упал посередине и номер не записался, сборка всё равно не
     * останется мусором.
     *
     * Порог по времени, а не по assembly_id, - иначе под нож попало бы всё,
     * что создано после сида, включая чужие сборки.
     */
    public static function tearDownAfterClass(): void
    {
        try {
            $mysql = connect();
        } catch (Throwable) {
            return;
        }

        $cutoff = date('Y-m-d H:i:s', time() - 300);

        $ids = self::$createdAssemblyIds;
        self::$createdAssemblyIds = [];

        $stmt = db_prepare($mysql, "SELECT assembly_id FROM assembly
            WHERE assembly_id > 3 AND created_at > ?", 's', $cutoff);
        $stmt->execute();
        $recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($recent as $row) {
            $ids[] = (int) $row['assembly_id'];
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            $mysql->close();
            return;
        }

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
            $deleted = $stmt->affected_rows;
            $stmt->close();
            $mysql->commit();
        } catch (Throwable $e) {
            $mysql->rollback();
            $mysql->close();
            throw $e;
        }

        fwrite(STDERR, sprintf("AssemblyTest: удалено сборок после прогонов - %d\n", $deleted));
        $mysql->close();
    }

    private function loginAsAdmin(): void
    {
        $adminPass = getenv('ADMIN_PASSWORD');
        $this->assertNotEmpty($adminPass, 'ADMIN_PASSWORD не задан в окружении');
        $r = $this->loginAs('admin', (string) $adminPass);
        $this->assertSame(302, $r['code']);
    }

    private function freshToken(string $path): string
    {
        $page = $this->httpGet($path);
        $this->assertSame(200, $page['code']);
        return $this->extractCsrf($page['body']);
    }

    private function adminId(): int
    {
        $mysql = connect();
        $stmt = db_prepare($mysql, "SELECT user_id FROM users WHERE user_login = ?", "s", 'admin');
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $mysql->close();
        $this->assertNotEmpty($row);
        return (int) $row['user_id'];
    }

    public function testConfiguratorSaveBuyFlow(): void
    {
        $this->loginAsAdmin();
        $uid = $this->adminId();

        // 1. Конфигуратор → новая сборка N > 3 (assemblyId выдаётся cookie)
        $token = $this->freshToken('/');
        $cfg = $this->httpPost('/assembly.php', [
            'price' => '200000',
            'csrf_token' => $token,
        ]);
        $this->assertSame(302, $cfg['code']);

        // 2. Открываем созданную сборку, запоминаем N.
        // номер выводится в сводке как «Сборка №N»,
        // раньше это был отдельный блок «Номер сборки - N»
        $page = $this->httpGet('/assembly.php');
        $this->assertSame(200, $page['code']);
        $this->assertMatchesRegularExpression('/Сборка №(\d+)/', $page['body']);
        preg_match('/Сборка №(\d+)/', $page['body'], $m);
        $n = (int) $m[1];
        $this->assertGreaterThan(3, $n, 'Конфигуратор не создал пользовательскую сборку');
        // запоминаем: уборка в tearDownAfterClass удалит именно её
        self::$createdAssemblyIds[] = $n;

        // 3. save → 302, запись в favorites
        $t2 = $this->extractCsrf($page['body']);
        $save = $this->httpPost('/assembly.php', ['save' => '1', 'csrf_token' => $t2]);
        $this->assertSame(302, $save['code']);
        $this->assertSame(1, $this->countRows('favorites', $uid, $n));

        // 4. Повторный save → 302, без дубликата
        $t3 = $this->freshToken('/assembly.php');
        $save2 = $this->httpPost('/assembly.php', ['save' => '1', 'csrf_token' => $t3]);
        $this->assertSame(302, $save2['code']);
        $this->assertSame(1, $this->countRows('favorites', $uid, $n));

        // 5. buy → 302, запись в orders
        $t4 = $this->freshToken('/assembly.php');
        $buy = $this->httpPost('/assembly.php', ['buy' => '1', 'csrf_token' => $t4]);
        $this->assertSame(302, $buy['code']);
        $this->assertSame(1, $this->countRows('orders', $uid, $n));

        // Связи чистим сразу, чтобы тест не оставлял после себя ничего,
        // кроме самой сборки. Сборку убирает tearDownAfterClass.
        $this->deleteLink('favorites', $uid, $n);
        $this->deleteLink('orders', $uid, $n);
    }

    private function countRows(string $table, int $uid, int $assemblyId): int
    {
        $allowed = ['favorites' => true, 'orders' => true];
        $this->assertArrayHasKey($table, $allowed);
        $mysql = connect();
        $stmt = db_prepare($mysql, "SELECT COUNT(*) AS c FROM `$table` WHERE user_id = ? AND assembly_id = ?", "ii", $uid, $assemblyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $mysql->close();
        return (int) ($row['c'] ?? 0);
    }

    private function deleteLink(string $table, int $uid, int $assemblyId): void
    {
        $allowed = ['favorites' => true, 'orders' => true];
        $this->assertArrayHasKey($table, $allowed);
        $mysql = connect();
        $stmt = db_prepare($mysql, "DELETE FROM `$table` WHERE user_id = ? AND assembly_id = ?", "ii", $uid, $assemblyId);
        $stmt->execute();
        $mysql->close();
    }
}
