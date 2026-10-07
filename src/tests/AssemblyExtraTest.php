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
            $this->xpathCount($user['body'], '//*[@id="extraPickerModal"]'),
            'в блоке должна быть модалка выбора'
        );
        $this->assertSame(
            1,
            $this->xpathCount($user['body'], '//button[@data-action="open-extra-picker"]'),
            'добавление допов должно идти через кнопку, а не через постоянные селекты'
        );
        $this->assertSame(
            0,
            $this->xpathCount($user['body'], '//select[@name="extra_ssd_2_id"]'),
            'постоянного селекта быть не должно: список открывается по кнопке'
        );

        // Списки для модалки лежат в data-slots, а не в разметке: политика
        // безопасности запрещает инлайн-скрипты без nonce, и без атрибута
        // модалка открылась бы пустой.
        $this->assertMatchesRegularExpression(
            '/data-slots="\{&quot;ssd_2_id&quot;/',
            $user['body'],
            'список компонентов должен передаваться в data-slots'
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

        /* Снимок обязателен: остатки возвращает базовый tearDown, а он
           берёт значения из снимка, который снимает сам тест. Без этого
           каждый прогон списывал бы по единице на восьми компонентах
           дешёвых сборок, и склад тихо уезжал бы в ноль. */
        $row = $this->assemblyRow($id);
        $slotColumns = ['cpu_id', 'motherboard_id', 'ram_id', 'case_id', 'cooler_id',
                        'power_supply_id', 'ssd_id', 'gpu_id', 'ssd_2_id', 'hdd_id', 'dvd_id'];
        $ids = [(int) $ssd['component_id']];
        foreach ($slotColumns as $col) {
            if ((int) ($row[$col] ?? 0) > 0) {
                $ids[] = (int) $row[$col];
            }
        }
        $this->snapshotStock($ids);

        $stockBefore = $this->stockOf((int) $ssd['component_id']);

        $r = $this->httpPost('/assembly.php?id=' . $id, [
            'buy' => '1',
            'csrf_token' => $this->freshToken('/assembly.php?id=' . $id),
            'extra_ssd_2_id' => (string) $ssd['component_id'],
        ]);
        $this->assertSame(302, $r['code']);
        $this->assertStringNotContainsString('error=', $r['location'], 'покупка должна пройти');

        /* Админ после покупки идёт в админку: карточки «Мои заказы» в
           профиле у него нет, и section=orders откатился бы на личную
           информацию. */
        $this->assertStringContainsString(
            '/admin.php?tab=orders',
            $r['location'],
            'админ должен попасть в админку заказов'
        );

        $this->assertSame(
            $stockBefore - 1,
            $this->stockOf((int) $ssd['component_id']),
            'допкомпонент должен быть списан'
        );
    }

    /**
     * Имя компонента из базы не становится разметкой.
     *
     * Списки уходят в JS через data-slots, а тот строит карточки. Если бы
     * кто-то вернул innerHTML с подстановкой имени, строка вида
     * "<img src=x onerror=...>" выполнилась бы у покупателя прямо в списке
     * выбора. Проверяется от��ёт сервера: имя обязано быть экранировано, а
     * сам список собираться на клиенте - значит, сырых узлов в разметке
     * быть не должно вовсе.
     */
    public function testComponentNameIsEscapedInSlotData(): void
    {
        $this->loginAsAdmin();
        $id = $this->makeUserAssembly();

        $payload = '<img src=x onerror=alert(1)>';
        $mysql = connect();
        $stmt = db_prepare(
            $mysql,
            'INSERT INTO components (component_name, component_price, amount, category_id)
             VALUES (?, ?, ?, ?)',
            'siii',
            $payload,
            1000,
            5,
            9
        );
        $stmt->execute();
        $probeId = (int) $mysql->insert_id;
        $stmt->close();
        $mysql->close();

        try {
            $page = $this->httpGet('/assembly.php?id=' . $id);
            $this->assertSame(200, $page['code']);

            $this->assertStringNotContainsString(
                '<img src=x onerror',
                $page['body'],
                'имя компонента попало в разметку без экранирования'
            );
            $this->assertStringContainsString(
                '&lt;img src=x onerror',
                $page['body'],
                'имя должно передаваться экранированным, иначе JSON разберётся и соберёт узел'
            );
        } finally {
            $mysql = connect();
            $stmt = db_prepare($mysql, 'DELETE FROM components WHERE component_id = ?', 'i', $probeId);
            $stmt->execute();
            $stmt->close();
            $mysql->close();
        }
    }

    /**
     * Клиент не собирает разметку через innerHTML.
     *
     * Серверная экранировка закрыта проверкой выше, но она не спасает,
     * если JS начнёт вставлять имя компонента как разметку. Файл собирает
     * DOM только через createElement и textContent, поэтому innerHTML в
     * нём неуместен в принципе: его появление - это либо регрессия, либо
     * новая функциональность, и в обоих случаях тест должен остановить.
     */
    public function testClientScriptHasNoInnerHtml(): void
    {
        $file = dirname(__DIR__) . '/assets/js/assembly-extra.js';
        $this->assertFileExists($file, 'нет файла assembly-extra.js');

        $js = (string) file_get_contents($file);

        /* Комментарии вырезаются: объяснение, почему innerHTML нельзя,
           само слово содержит - и проверка ниже спотыкалась бы о текст
           рассуждения, а не о код. */
        $code = preg_replace('#/\*.*?\*/#s', '', $js);
        $code = preg_replace('#//[^\n]*#', '', (string) $code);

        $this->assertStringNotContainsString(
            'innerHTML',
            $code,
            'сборка списка через innerHTML выполнит имя компонента как разметку'
        );
        $this->assertStringContainsString(
            'textContent',
            $code,
            'списки должны собираться через textContent'
        );
        $this->assertStringContainsString(
            'createElement',
            $code,
            'списки должны собираться через createElement'
        );
    }

    /**
     * Обычный покупатель после покупки попадает в свои заказы.
     *
     * Обратная сторона предыдущего теста: одна проверка на админе оставила
     * бы ветку пользователя непроверенной, а поломался бы именно редирект -
     * страница бы ответила 200 и выглядела исправной.
     */
    public function testPlainUserLandsOnOwnOrders(): void
    {
        $this->loginAsAdmin();
        $id = $this->makeUserAssembly();

        $login = $this->uniqueLogin('buyer_');
        $this->trackCleanup($login);

        $mysql = connect();
        $stmt = db_prepare(
            $mysql,
            "INSERT INTO users (user_name, user_login, user_pass, user_group) VALUES (?, ?, ?, ?)",
            "ssss",
            'Покупатель',
            $login,
            password_hash('password123', PASSWORD_BCRYPT),
            'user'
        );
        $stmt->execute();
        $userId = (int) $mysql->insert_id;
        $stmt->close();

        $row = $this->assemblyRow($id);
        $ids = [];
        foreach (['cpu_id', 'motherboard_id', 'ram_id', 'case_id', 'cooler_id',
                  'power_supply_id', 'ssd_id', 'gpu_id', 'ssd_2_id', 'hdd_id', 'dvd_id'] as $col) {
            if ((int) ($row[$col] ?? 0) > 0) {
                $ids[] = (int) $row[$col];
            }
        }
        $this->snapshotStock($ids);
        $mysql->close();

        $this->clearLoginAttempts($login);
        $in = $this->loginAs($login, 'password123');
        $this->assertSame(302, $in['code']);

        $r = $this->httpPost('/assembly.php?id=' . $id, [
            'buy' => '1',
            'csrf_token' => $this->freshToken('/assembly.php?id=' . $id),
        ]);
        $this->assertSame(302, $r['code']);
        $this->assertStringNotContainsString('error=', $r['location'], 'покупка должна пройти');
        $this->assertStringContainsString(
            '/profile.php?section=orders',
            $r['location'],
            'обычный покупатель должен попасть в свои заказы'
        );

        $mysql = connect();
        $stmt = db_prepare($mysql, 'SELECT COUNT(*) AS c FROM orders WHERE user_id = ?', 'i', $userId);
        $stmt->execute();
        $found = (int) $stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();
        $mysql->close();

        $this->assertSame(1, $found, 'заказ должен быть записан на покупателя');
    }

    /**
     * Обработчик кнопок не должен попасть под ранний выход.
     *
     * Секции допкомпонентов у базовой сборки на странице нет, и
     * assembly-extra.js выходит сразу. Пока сохранение и покупка жили
     * в нём, у базовой сборки кнопки не имели обработчика: type="button"
     * без подтверждения, клик без отправки формы. Витрина ведёт именно
     * на базовые сборки, то есть покупка с неё не работала ни разу.
     *
     * Страницу теста на этом месте нет - тесты ходят по HTTP без JS,
     * поэтому проверяется код и то, что файл подключается.
     */
    public function testActionListenerIsNotGuarded(): void
    {
        $page = $this->httpGet('/assembly.php?id=' . $this->makeBaseAssembly());
        $this->assertSame(200, $page['code']);

        $this->assertSame(
            1,
            $this->xpathCount($page['body'], '//script[contains(@src, "assembly-actions.js")]'),
            'обработчик кнопок должен подключаться отдельным файлом: под ранним '
            . 'выходом assembly-extra.js он не регистрировался у базовой сборки'
        );

        $file = dirname(__DIR__) . '/assets/js/assembly-actions.js';
        $this->assertFileExists($file, 'нет файла assembly-actions.js');

        $js = (string) file_get_contents($file);
        $code = preg_replace('#/\*.*?\*/#s', '', $js);
        $code = preg_replace('#//[^\n]*#', '', (string) $code);

        $listenAt = strpos($code, 'document.addEventListener');
        $this->assertNotFalse(
            $listenAt,
            'assembly-actions.js должен вешать обработчик на документ'
        );

        /* Ранний выход до подписки - ровно та поломка, что описана в
           заголовке теста. return внутри функции таким выходом не
           является, поэтому разбирается вложенность: выходом из
           файла считается только return, случившийся до входа в
           тело какой-либо функции. */
        $head = substr($code, 0, $listenAt);
        $stack = [];
        $earlyExit = false;

        for ($i = 0, $len = strlen($head); $i < $len; $i++) {
            if ($head[$i] === '{') {
                $before = substr($head, max(0, $i - 200), 200);
                $isFunction = (bool) preg_match('/function[\s\S]*\)\s*$/', $before);
                // Первый блок - сама обёртка файла, вложенной функцией
                // она не считается: её выход как раз и есть ранний.
                $stack[] = $isFunction && count($stack) >= 1;
            } elseif ($head[$i] === '}') {
                array_pop($stack);
            } elseif ($head[$i] === 'r' && substr($head, $i, 6) === 'return') {
                if (!in_array(true, $stack, true)) {
                    $earlyExit = true;
                    break;
                }
            }
        }

        $this->assertFalse(
            $earlyExit,
            'подписка на клик стоит после раннего выхода: обработчик не дойдёт '
            . 'до конца файла и зарегистрируется не на всех сборках'
        );

        /* Страховка от возврата обработчика внутрь assembly-extra.js: там он
           вновь оказался бы под выходом по отсутствию секции допов. */
        $extra = (string) file_get_contents(dirname(__DIR__) . '/assets/js/assembly-extra.js');
        $this->assertStringNotContainsString(
            'confirm-buy',
            $extra,
            'подтверждение покупки не должно возвращаться в assembly-extra.js'
        );
    }

    /**
     * Кнопки сохранения и покупки спрашивают подтверждения.
     *
     * Кнопки объявлены type="button" намеренно: submit отправил бы форму
     * сразу, и браузер увёл бы со страницы до того, как человек ответит.
     * Тест ловит возврат к submit - тогда модалка просто не появится, а
     * страница перезагрузится, и заметить это вручную трудно.
     */
    public function testActionButtonsAreNotSubmit(): void
    {
        $this->loginAsAdmin();
        $id = $this->makeUserAssembly();

        /* Три режима страницы, и в каждом своя пара кнопок: у сборки в
           избранном активна покупка, у купленной - сохранение. Проверка
           одного режима пропустила бы откат в другом: так тест и обманул
           при первой попытке. */
        /* check-saved и check-purchased не дописываются к id, а заменяют
           его: id берётся из первого же подходящего параметра, и пустое
           значение дало бы ноль и редирект. */
        $modes = [
            ''                => ['confirm-save', 'confirm-buy'],
            'check-saved'     => ['confirm-buy'],
            'check-purchased' => ['confirm-save'],
        ];

        foreach ($modes as $mode => $expected) {
            $url = $mode === '' ? '/assembly.php?id=' . $id : '/assembly.php?' . $mode . '=' . $id;
            $page = $this->httpGet($url);
            $this->assertSame(200, $page['code'], "режим {$mode} недоступен");

            foreach (['confirm-save', 'confirm-buy'] as $action) {
                $count = $this->xpathCount(
                    $page['body'],
                    '//button[@data-action="' . $action . '"]'
                );
                if (in_array($action, $expected, true)) {
                    $this->assertSame(1, $count, "в режиме {$mode} должна быть кнопка {$action}");
                    $this->assertSame(
                        1,
                        $this->xpathCount(
                            $page['body'],
                            '//button[@data-action="' . $action . '"][@type="button"]'
                        ),
                        "кнопка {$action} должна быть type=button: submit отправила бы форму без вопроса"
                    );
                } else {
                    $this->assertSame(0, $count, "в режиме {$mode} кнопка {$action} должна быть отключена");
                }
            }

            $this->assertSame(
                0,
                $this->xpathCount($page['body'], '//button[@type="submit"][@name="save" or @name="buy"]'),
                "в режиме {$mode} не должно быть submit-кнопок отправки"
            );
        }

        /* Подтверждение должно быть доступно и на странице сборки: сам
           confirm.js подключён из подвала, а scripts.js на этой странице
           не подключается, и обработчик жил бы только там. */
        $page = $this->httpGet('/assembly.php?id=' . $id);
        $this->assertSame(
            1,
            $this->xpathCount($page['body'], '//script[contains(@src, "confirm.js")]'),
            'confirm.js должен подключаться глобально, иначе подтверждений не будет'
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