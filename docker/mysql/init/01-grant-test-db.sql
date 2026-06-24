-- Allow the application user to create/use the test database (app_test).
-- The pattern app\_% matches databases whose name starts with "app_".
GRANT ALL PRIVILEGES ON `app\_%`.* TO 'app'@'%';
FLUSH PRIVILEGES;
