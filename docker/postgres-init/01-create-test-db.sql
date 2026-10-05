-- Banco exclusivo dos testes automatizados. O phpunit.xml força DB_DATABASE=ticketing_test,
-- então RefreshDatabase nunca toca no banco de desenvolvimento ("ticketing").
-- (Só roda em volume novo: em um volume antigo use `make test-db`.)
CREATE DATABASE ticketing_test OWNER ticketing;
