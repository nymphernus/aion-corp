# Аудит 1: неиспользуемые файлы

| Файл | Что это | Кто использует | Вердикт |
|---|---|---|---|
| `test_assembly.php` | Отладочный скрипт (подделывает POST/GET/COOKIE) | Никто; .htaccess отдаёт 403 | УДАЛИТЬ (подтвердить) |
| `base.css` | Общий CSS | `connect.php`, `header.php`, вкладки админки | ИСПОЛЬЗУЕТСЯ |
| `slider.js` | Лента сборок | `index.php` | ИСПОЛЬЗУЕТСЯ |
| `convert_webp_to_jpg.php` | Конвертация изображений | `restore_alpha_images.php` | ИСПОЛЬЗУЕТСЯ |
| `restore_alpha_images.php` | CLI-восстановление прозрачности | Вручную | ОСТАВИТЬ |
| `restore_contacts.php` | CLI-восстановление контактов из legacy_init.sql | Вручную | ОСТАВИТЬ |
| `restore_favicon.php` | CLI-восстановление favicon | Вручную | ОСТАВИТЬ |
| `migrate_address.php` | CLI-разбивка legacy user_address в поля БД | Вручную | ОСТАВИТЬ |

Только `test_assembly.php` — кандидат на удаление. Пользователь должен
подтвердить.
