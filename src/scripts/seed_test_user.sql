-- Восстановление тестовой фикстуры после down -v: dethtaker, которого
-- использует IconsTest. Пароль совпадает с тестовым в наборах.
INSERT INTO users
  (user_name, user_surname, user_login, user_pass, user_group,
   user_email, user_number, email_verification_requested,
   phone_verification_requested, email_verified, phone_verified)
SELECT 'Dethtaker', 'Tester', 'dethtaker',
       '$2y$10$8kVZ0h8Qz4oVYjj0hqE0eVf4v1QhRc7OWCGnBP4e1/0FZm6k1PhOC',
       'user', 'dethtaker@test.local', '+79990000001', 0, 0, 0, 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM users WHERE user_login = 'dethtaker');