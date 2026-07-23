-- ============================================================
-- AI Resume — PostgreSQL schema (Render managed Postgres)
-- Boolean-like flags use SMALLINT (0/1) to match the app's
-- existing (int)/(bool) casts with zero PHP changes.
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
  user_id        VARCHAR(36) PRIMARY KEY,
  name           VARCHAR(150),
  email          VARCHAR(150) UNIQUE,
  phone          VARCHAR(30),
  password_hash  VARCHAR(255),
  google_id      VARCHAR(255),
  auth_provider  VARCHAR(20) NOT NULL DEFAULT 'local',
  avatar_url     VARCHAR(500),
  is_admin       SMALLINT NOT NULL DEFAULT 0,
  register_date  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS logs (
  log_id     VARCHAR(36) PRIMARY KEY,
  user_id    VARCHAR(36),
  action     VARCHAR(100),
  status     VARCHAR(20),
  details    TEXT,
  timestamp  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS resumes (
  resume_id         VARCHAR(36) PRIMARY KEY,
  user_id           VARCHAR(36),
  field             VARCHAR(150),
  education         TEXT,
  skills            TEXT,
  experience        TEXT,
  tone              VARCHAR(50),
  template          VARCHAR(50),
  ai_prompt         TEXT,
  ai_result_resume  TEXT,
  ai_result_letter  TEXT,
  pdf_url           TEXT,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS institutions (
  institution_id  SERIAL PRIMARY KEY,
  name            VARCHAR(255) NOT NULL,
  short_name      VARCHAR(80),
  country         VARCHAR(100),
  city            VARCHAR(100),
  type            VARCHAR(50),
  verified        SMALLINT NOT NULL DEFAULT 0,
  created_by      VARCHAR(36),
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS faculties (
  faculty_id      SERIAL PRIMARY KEY,
  institution_id  INTEGER,
  name            VARCHAR(255) NOT NULL,
  short_name      VARCHAR(80),
  description     TEXT,
  verified        SMALLINT NOT NULL DEFAULT 0,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS courses (
  course_id       SERIAL PRIMARY KEY,
  institution_id  INTEGER,
  faculty_id      INTEGER,
  name            VARCHAR(255) NOT NULL,
  code            VARCHAR(20),
  level           VARCHAR(50),
  faculty         VARCHAR(255),
  verified        SMALLINT NOT NULL DEFAULT 0,
  created_by      VARCHAR(36),
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS subjects (
  subject_id         SERIAL PRIMARY KEY,
  course_id          INTEGER,
  name               VARCHAR(255) NOT NULL,
  code               VARCHAR(20),
  semester           INTEGER,
  learning_outcomes  JSONB,
  plo_mapping        JSONB,
  skills_inferred    JSONB,
  verified           SMALLINT NOT NULL DEFAULT 0,
  created_by         VARCHAR(36),
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_subject_course_name UNIQUE (course_id, name)
);

CREATE TABLE IF NOT EXISTS programme_outcomes (
  plo_id      SERIAL PRIMARY KEY,
  course_id   INTEGER NOT NULL,
  code        VARCHAR(20) NOT NULL,
  description TEXT NOT NULL,
  category    VARCHAR(100),
  sort_order  INTEGER NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_education (
  id                SERIAL PRIMARY KEY,
  user_id           VARCHAR(36) NOT NULL,
  institution_id    INTEGER,
  institution_name  VARCHAR(255),
  course_id         INTEGER,
  course_name       VARCHAR(255),
  course_level      VARCHAR(50),
  cgpa              NUMERIC(4,2),
  start_year        INTEGER,
  end_year          INTEGER,
  is_current        SMALLINT NOT NULL DEFAULT 0,
  sort_order        INTEGER NOT NULL DEFAULT 0,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_skills (
  id                 SERIAL PRIMARY KEY,
  user_id            VARCHAR(36) NOT NULL,
  skill_name         VARCHAR(100) NOT NULL,
  category           VARCHAR(100),
  proficiency        VARCHAR(50),
  source             VARCHAR(20) DEFAULT 'manual',
  certification_name VARCHAR(255),
  certification_url  VARCHAR(500),
  show_in_resume     SMALLINT NOT NULL DEFAULT 1,
  sort_order         INTEGER NOT NULL DEFAULT 0,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_user_skill UNIQUE (user_id, skill_name)
);

CREATE TABLE IF NOT EXISTS user_subjects (
  id              SERIAL PRIMARY KEY,
  user_id         VARCHAR(36) NOT NULL,
  subject_id      INTEGER NOT NULL,
  show_in_resume  SMALLINT NOT NULL DEFAULT 1,
  grade           VARCHAR(10),
  status          VARCHAR(20) NOT NULL DEFAULT 'approved',
  approved_by     VARCHAR(36),
  approved_at     TIMESTAMP,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_user_subject UNIQUE (user_id, subject_id)
);

CREATE INDEX IF NOT EXISTS idx_courses_institution   ON courses(institution_id);
CREATE INDEX IF NOT EXISTS idx_subjects_course       ON subjects(course_id);
CREATE INDEX IF NOT EXISTS idx_plo_course            ON programme_outcomes(course_id);
CREATE INDEX IF NOT EXISTS idx_ue_user               ON user_education(user_id);
CREATE INDEX IF NOT EXISTS idx_us_user               ON user_skills(user_id);
CREATE INDEX IF NOT EXISTS idx_usub_status           ON user_subjects(status);
