<?php
/**
 * ConfiguratorTest — подбор комплектующих в конфигураторе (задача «вопрос 4»).
 */

declare(strict_types=1);

final class ConfiguratorTest extends AionTestCase
{
    /**
     * Компонент с нулевым остатком не должен попадать в подбор.
     *
     * Смысл проверки: пользователь собирает конфигурацию и видит готовый
     * ПК, который нельзя купить. Подбор шёл по всем компонентам категории,
     * поэтому amount = 0 проходил наравне с остальными.
     *
     * Фикстура своя: два процессора с разной ценой, оба с видеоядром.
     * Подбор берёт самый дешёвый доступный - значит при нулевом остатке у
     * дешёвого должен выбраться дорогой.
     */
    public function testZeroStockComponentIsNotPicked(): void
    {
        require_once dirname(__DIR__) . '/modules/connect.php';
        require_once dirname(__DIR__) . '/modules/configurator.php';

        $mysql = connect();
        mysqli_set_charset($mysql, 'utf8');

        // отвязываемся от текущей сессии: здесь нужна обычная функция,
        // а не HTTP-обвязка админки
        $ids = [];
        try {
            foreach ([['Cheap Stock Probe', 100, 5], ['Pricier Stock Probe', 500, 5]] as [$name, $price, $amount]) {
                $ins = db_prepare($mysql,
                    "INSERT INTO components (component_name, category_id, video_core, component_price, amount, manufacturer, model)
                     VALUES (?, 1, 1, ?, ?, 'Probe', 'Probe')",
                    "sii", $name, $price, $amount);
                $ins->execute();
                $ids[] = (int) $ins->insert_id;
            }

            // 1) оба в наличии - берётся самый дешёвый
            $pick = cfg_cheapest($mysql, 'category_id = 1 AND video_core = 1 AND manufacturer = ?', 's', ['Probe']);
            $this->assertNotNull($pick, 'подбор должен что-то вернуть');
            $this->assertSame('Cheap Stock Probe', $pick['component_name'], 'при нормальном остатке берётся самый дешёвый');

            // 2) дешёвый закончился - должен выбраться более дорогой
            db_prepare($mysql, "UPDATE components SET amount = 0 WHERE component_id = ?", "i", $ids[0])->execute();
            $pick2 = cfg_cheapest($mysql, 'category_id = 1 AND video_core = 1 AND manufacturer = ?', 's', ['Probe']);
            $this->assertNotNull($pick2);
            $this->assertSame(
                'Pricier Stock Probe',
                $pick2['component_name'],
                'компонент с нулевым остатком не должен выбираться'
            );
            $this->assertGreaterThan(
                0,
                (int) $pick2['amount'],
                'выбранный компонент должен быть в наличии'
            );

            // 3) закончилось всё - откат на любой, лишь бы сборка собралась
            db_prepare($mysql, "UPDATE components SET amount = 0 WHERE manufacturer = 'Probe'", "")->execute();
            $pick3 = cfg_cheapest($mysql, 'category_id = 1 AND video_core = 1 AND manufacturer = ?', 's', ['Probe']);
            $this->assertNotNull(
                $pick3,
                'если в наличии нет ничего, подбор не должен падать - иначе конфигуратор перестанет собирать сборки'
            );
        } finally {
            foreach ($ids as $id) {
                db_prepare($mysql, "DELETE FROM components WHERE component_id = ?", "i", $id)->execute();
            }
            $mysql->close();
        }
    }

    /**
     * cfg_pick тоже учитывает наличие: дешёвый остаётся, если он в
     * наличии и укладывается в лимит, иначе берётся другой.
     */
    public function testPickSkipsOutOfStock(): void
    {
        require_once dirname(__DIR__) . '/modules/connect.php';
        require_once dirname(__DIR__) . '/modules/configurator.php';

        $mysql = connect();
        mysqli_set_charset($mysql, 'utf8');

        $ids = [];
        try {
            foreach ([['Pick Expensive Probe', 900, 5], ['Pick Cheap Probe', 200, 5]] as [$name, $price, $amount]) {
                $ins = db_prepare($mysql,
                    "INSERT INTO components (component_name, category_id, video_core, component_price, amount, manufacturer, model)
                     VALUES (?, 1, 1, ?, ?, 'PickProbe', 'Probe')",
                    "sii", $name, $price, $amount);
                $ins->execute();
                $ids[] = (int) $ins->insert_id;
            }

            // лимит 1000: подходит любой, выбирается самый дорогой в наличии
            $pick = cfg_pick($mysql, 1000, 'category_id = 1 AND manufacturer = ?', 's', ['PickProbe']);
            $this->assertNotNull($pick);
            $this->assertSame('Pick Expensive Probe', $pick['component_name']);

            // дорогой закончился - остаётся дешёвый
            db_prepare($mysql, "UPDATE components SET amount = 0 WHERE component_name = 'Pick Expensive Probe'", "")->execute();
            $pick2 = cfg_pick($mysql, 1000, 'category_id = 1 AND manufacturer = ?', 's', ['PickProbe']);
            $this->assertNotNull($pick2);
            $this->assertSame(
                'Pick Cheap Probe',
                $pick2['component_name'],
                'cfg_pick не должен возвращать товар без остатка'
            );
        } finally {
            foreach ($ids as $id) {
                db_prepare($mysql, "DELETE FROM components WHERE component_id = ?", "i", $id)->execute();
            }
            $mysql->close();
        }
    }
}
