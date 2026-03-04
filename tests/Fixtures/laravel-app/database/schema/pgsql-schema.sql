-- PostgreSQL schema dump fixture for testing
-- This file simulates a Laravel schema dump with some metacommands to sanitize

\restrict

CREATE TABLE IF NOT EXISTS migrations (
    id serial PRIMARY KEY,
    migration varchar(255) NOT NULL,
    batch integer NOT NULL
);

\unrestrict all

CREATE TABLE IF NOT EXISTS users (
    id bigserial PRIMARY KEY,
    name varchar(255) NOT NULL,
    email varchar(255) NOT NULL UNIQUE,
    created_at timestamp NULL,
    updated_at timestamp NULL
);
