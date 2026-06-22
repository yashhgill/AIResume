-- PostgreSQL schema for ai_resume_db, for deployment on Render (whose free
-- tier offers managed Postgres, not MySQL).
--
-- This is the PostgreSQL equivalent of ai_resume_db.sql, with
-- migration_add_template.sql and migration_add_google_auth.sql already
-- merged in (Postgres's ALTER TABLE has no "AFTER column" positional
-- syntax like MySQL, and column order doesn't affect correctness anyway,
-- so they're folded directly into each CREATE TABLE here instead of run
-- as separate migrations).
--
-- All primary keys (user_id, resume_id, log_id) are client-generated UUID
-- strings (bin2hex(random_bytes(16)) in PHP) - never DB auto-increment -
-- so no SERIAL/IDENTITY columns are needed anywhere in this schema.
--
-- Import via Render's "psql" connect command, or any Postgres client
-- (pgAdmin, DBeaver, etc.) pointed at your Render Postgres instance.

BEGIN;

CREATE TABLE users (
  user_id varchar(36) NOT NULL PRIMARY KEY,
  name varchar(100),
  email varchar(100) UNIQUE,
  phone varchar(20),
  password_hash varchar(255),
  google_id varchar(255),
  auth_provider varchar(20) NOT NULL DEFAULT 'local',
  avatar_url varchar(500),
  register_date timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_google_id ON users (google_id);

CREATE TABLE resumes (
  resume_id varchar(36) NOT NULL PRIMARY KEY,
  user_id varchar(36) REFERENCES users (user_id),
  field varchar(100),
  education text,
  skills text,
  experience text,
  tone varchar(50),
  template varchar(50),
  ai_prompt text,
  ai_result_resume text,
  ai_result_letter text,
  pdf_url text,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_resumes_user_id ON resumes (user_id);

CREATE TABLE logs (
  log_id varchar(36) NOT NULL PRIMARY KEY,
  user_id varchar(36) REFERENCES users (user_id),
  action varchar(100),
  status varchar(20),
  details text,
  "timestamp" timestamp DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_logs_user_id ON logs (user_id);

-- Optional: the one seed row from the original MySQL dump had an empty
-- user_id (''), which can't work as a primary key value here in any
-- meaningful way - skipped. Sign up through the app instead to create a
-- real account with a proper generated user_id.

COMMIT;
