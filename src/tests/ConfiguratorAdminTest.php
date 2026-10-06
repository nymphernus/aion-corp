<?php
/**
 * ConfiguratorAdminTest — правка пресетов и операционных систем в
 * админке через живые POST-запросы.
 *
 * Тесты живые по одной причине: ошибка «Column count doesn't match
 * value count» объявляется только при выполнении запроса. php -l
 * такой код пропускает, и трижды за одну серию правок расхождение
 * между числом плейсхолдеров, длиной строки типов и числом значений
 * дошло до продакшена:
 *
 *   - 'ssis' вместо 'sisi' в миграции - иконки пресетов записались 0;
 *   - 'sisii' при четырёх колонках в UPDATE пресета;
 *   - VALUES с четырьмя плейсхолдерами при трёх колонках в INSERT ОС -
 *     Fatal error при добавлении операционной системы.
 *
 * Юнит-тест на уровне функции такую ошибку не увидит: он проверяет
 * намерение, а не форму запроса к базе.
 */

declare(strict_types=1);

final class ConfiguratorAdminTest extends AionTestCase
{
    private function loginAsAdmin(): void
    {
        $adminPass = getenv('ADMIN_PASSWORD');
        $this->assertNotEmpty($adminPass, 'ADMIN_PASSWORD не задан в окружении');
        $this->clearLoginAttempts('admin');
        $r = $this->loginAs('admin', (string) $adminPass);
        $this->assertSame(302, $r['code']);
    }

    /**
     * Свежий CSRF-токен на каждый POST.
     *
     * Токен одноразовый в рамках загрузки страницы: обработчик крутит
     * его через csrf_rotate() перед редиректом. Взятый один раз и
     * использованный дважды второй POST получает 403, и тест падает
     * не на той проверке, которую meant.
     *
     * @return string
     */
    private function freshToken(): string
    {
        $page = $this->httpGet('/admin.php?tab=configurator');
        $this->assertSame(200, $page['code']);
        return $this->extractCsrf($page['body']);
    }

    private function fetchOs(string $name): ?int
    {
        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT os_id FROM configurator_os WHERE os_name = ?', 's', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $mysql->close();
        return $row === null ? null : (int) $row['os_id'];
    }

    private function fetchPreset(string $name): ?int
    {
        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT preset_id FROM configurator_presets WHERE preset_name = ?', 's', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $mysql->close();
        return $row === null ? null : (int) $row['preset_id'];
    }

    private function deleteOs(int $id): void
    {
        $mysql = connect();
        $stmt = db_prepare($mysql, 'DELETE FROM configurator_os WHERE os_id = ?', 'i', $id);
        $stmt->execute();
        $mysql->close();
    }

    private function deletePreset(int $id): void
    {
        $mysql = connect();
        $stmt = db_prepare($mysql, 'DELETE FROM configurator_presets WHERE preset_id = ?', 'i', $id);
        $stmt->execute();
        $mysql->close();
    }

    private function assertNoFatal(string $body): void
    {
        $this->assertStringNotContainsString(
            'Fatal error',
            $body,
            'сервер упал с Fatal error: расхождение плейсхолдеров и колонок'
        );
        $this->assertStringNotContainsString(
            "mysqli_sql_exception",
            $body,
            'сервер вернул исключение mysqli: проверь число плейсхолдеров'
        );
        $this->assertStringNotContainsString(
            "Column count doesn't match value count",
            $body,
            'число колонок не совпало с числом значений'
        );
    }

    public function testAddOsInsertsRow(): void
    {
        $this->loginAsAdmin();
        $name = 'Тест ОС ' . substr((string) time(), -6);

        $this->assertNull($this->fetchOs($name), 'тестовая ОС не должна существовать заранее');

        $r = $this->httpPost('/admin.php?tab=configurator', [
            'csrf_token'   => $this->freshToken(),
            'osAction'     => 'save',
            'osId'         => '0',
            'os_name'      => $name,
            'os_price'     => '27000',
            'is_active'    => '1',
        ]);

        $this->assertNoFatal($r['body']);
        // Обработчик всегда отдаёт редирект; при несовпадении колонок
        // он не доходил до header() и отдавал 500 с текстом ошибки.
        $this->assertSame(302, $r['code'], 'обработчик должен перенаправить после сохранения');

        $osId = $this->fetchOs($name);
        $this->assertNotNull($osId, 'строка ОС не появилась в таблице после сохранения');

        // Цена сохранённая, а не нулевая: раньше ошибка типов приводила
        // к тому, что запрос не выполнялся вовсе.
        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT os_price FROM configurator_os WHERE os_id = ?', 'i', $osId);
        $stmt->execute();
        $price = (int) ($stmt->get_result()->fetch_assoc()['os_price'] ?? -1);
        $mysql->close();
        $this->assertSame(27000, $price, 'стоимость ОС должна сохраниться');

        $this->deleteOs($osId);
    }

    public function testEditOsUpdatesRow(): void
    {
        $this->loginAsAdmin();
        $before = 'Тест до ' . substr((string) time(), -6);
        $after = 'Тест после';

        $add = $this->httpPost('/admin.php?tab=configurator', [
            'csrf_token' => $this->freshToken(),
            'osAction'   => 'save',
            'osId'       => '0',
            'os_name'    => $before,
            'os_price'   => '1000',
            'is_active'  => '1',
        ]);
        $this->assertNoFatal($add['body']);
        $osId = $this->fetchOs($before);
        $this->assertNotNull($osId, 'строка для правки не создалась');

        $edit = $this->httpPost('/admin.php?tab=configurator', [
            'csrf_token' => $this->freshToken(),
            'osAction'   => 'save',
            'osId'       => (string) $osId,
            'os_name'    => $after,
            'os_price'   => '2500',
            'is_active'  => '1',
        ]);
        $this->assertNoFatal($edit['body']);
        $this->assertSame(302, $edit['code']);

        $this->assertNull($this->fetchOs($before), 'старое название должно исчезнуть');
        $this->assertSame($osId, $this->fetchOs($after), 'правка должна менять ту же строку, а не создавать новую');

        $this->deleteOs($osId);
    }

    public function testAddPresetInsertsRow(): void
    {
        $this->loginAsAdmin();
        $name = 'Тест пресет ' . substr((string) time(), -6);

        $this->assertNull($this->fetchPreset($name), 'тестовый пресет не должен существовать заранее');

        $r = $this->httpPost('/admin.php?tab=configurator', [
            'csrf_token'     => $this->freshToken(),
            'presetAction'   => 'save',
            'presetId'       => '0',
            'preset_name'    => $name,
            'preset_budget'  => '88000',
            'preset_icon'    => 'cpu',
            'is_active'      => '1',
        ]);

        $this->assertNoFatal($r['body']);
        $this->assertSame(302, $r['code']);

        $presetId = $this->fetchPreset($name);
        $this->assertNotNull($presetId, 'строка пресета не появилась после сохранения');

        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT preset_budget, preset_icon FROM configurator_presets WHERE preset_id = ?', 'i', $presetId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $mysql->close();

        $this->assertSame(88000, (int) ($row['preset_budget'] ?? -1), 'бюджет должен сохраниться');
        $this->assertSame('cpu', (string) ($row['preset_icon'] ?? ''), 'иконка должна сохраниться: именно она съезжала в 0 при ошибке типов');

        $this->deletePreset($presetId);
    }

    public function testEditPresetUpdatesRow(): void
    {
        $this->loginAsAdmin();
        $before = 'Пресет до ' . substr((string) time(), -6);
        $after = 'Пресет после';

        $add = $this->httpPost('/admin.php?tab=configurator', [
            'csrf_token'    => $this->freshToken(),
            'presetAction'  => 'save',
            'presetId'      => '0',
            'preset_name'   => $before,
            'preset_budget' => '20000',
            'preset_icon'   => 'monitor',
            'is_active'     => '1',
        ]);
        $this->assertNoFatal($add['body']);
        $presetId = $this->fetchPreset($before);
        $this->assertNotNull($presetId, 'строка для правки не создалась');

        $edit = $this->httpPost('/admin.php?tab=configurator', [
            'csrf_token'    => $this->freshToken(),
            'presetAction'  => 'save',
            'presetId'      => (string) $presetId,
            'preset_name'   => $after,
            'preset_budget' => '45000',
            'preset_icon'   => 'zap',
            'is_active'     => '1',
        ]);
        $this->assertNoFatal($edit['body']);
        $this->assertSame(302, $edit['code']);

        $this->assertNull($this->fetchPreset($before), 'старое название должно исчезнуть');
        $this->assertSame($presetId, $this->fetchPreset($after), 'правка должна менять ту же строку');

        $this->deletePreset($presetId);
    }

    /**
     * Сортировка sort_order не должна выпадать из запросов.
     *
     * Когда из форм убрали поле «Порядок», его убрали и из колонок
     * INSERT, но VALUES остались с четырьмя плейсхолдерами. Тест
     * ловит это на первом же добавлении.
     */
    public function testPresetAndOsSqlHaveMatchingPlaceholderCount(): void
    {
        $this->loginAsAdmin();

        $code = (string) file_get_contents(__DIR__ . '/../admin.php');
        $pattern = "/db_prepare\(\s*\\\$mysql,\s*'(?<sql>[^']+)',\s*'(?<types>[a-z]*)',\s*(?<args>.*?)\s*\);/s";

        $this->assertGreaterThan(0, preg_match_all($pattern, $code, $m, PREG_SET_ORDER), 'разбор вызовов db_prepare не нашёл ни одного');

        foreach ($m as $one) {
            $sql = $one['sql'];
            $types = $one['types'];
            $args = trim($one['args']);

            if ($sql === '' || !str_contains($sql, '?')) {
                continue;
            }

            $placeholders = substr_count($sql, '?');
            $this->assertSame(
                $placeholders,
                strlen($types),
                "число плейсхолдеров и длина типов разошлись в: " . preg_replace('/\s+/', ' ', $sql)
            );
            $this->assertNotSame(
                '',
                $args,
                'у запроса с плейсхолдерами не должно быть пустого списка значений'
            );
        }
    }
}