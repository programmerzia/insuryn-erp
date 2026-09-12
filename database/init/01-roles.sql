-- Runs once, as the image superuser, when the Postgres volume is first created.
-- Neither application role may bypass row-level security (design §8.6.6):
--   erp_owner owns the schema and runs migrations; FORCE ROW LEVEL SECURITY applies to it as owner.
--   erp_app is the runtime role.
CREATE ROLE erp_owner LOGIN PASSWORD 'erp' NOSUPERUSER NOBYPASSRLS NOCREATEROLE;
CREATE ROLE erp_app LOGIN PASSWORD 'erp' NOSUPERUSER NOBYPASSRLS NOCREATEROLE;
-- The tenant-isolation test switches to the runtime role with SET ROLE erp_app.
GRANT erp_app TO erp_owner;

CREATE DATABASE erp OWNER erp_owner;
CREATE DATABASE erp_test OWNER erp_owner;

-- Privileges are per database: apply them to both.
\connect erp
GRANT CONNECT ON DATABASE erp TO erp_app;
GRANT USAGE ON SCHEMA public TO erp_app;
ALTER DEFAULT PRIVILEGES FOR ROLE erp_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO erp_app;
ALTER DEFAULT PRIVILEGES FOR ROLE erp_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO erp_app;

\connect erp_test
GRANT CONNECT ON DATABASE erp_test TO erp_app;
GRANT USAGE ON SCHEMA public TO erp_app;
ALTER DEFAULT PRIVILEGES FOR ROLE erp_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO erp_app;
ALTER DEFAULT PRIVILEGES FOR ROLE erp_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO erp_app;
