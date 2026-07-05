-- ============================================================
-- Migration: Faculty, PLO, Subject Approval system
-- Run this in phpMyAdmin on if0_42245357_ai_resume
-- ============================================================

-- 1. Faculty table (scalable: one per uni now, multi later)
CREATE TABLE IF NOT EXISTS faculties (
  faculty_id    INT AUTO_INCREMENT PRIMARY KEY,
  institution_id INT NULL,
  name          VARCHAR(255) NOT NULL,
  short_name    VARCHAR(80)  NULL,
  description   TEXT         NULL,
  verified      TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 2. Programme Outcomes (PLOs) per course
CREATE TABLE IF NOT EXISTS programme_outcomes (
  plo_id      INT AUTO_INCREMENT PRIMARY KEY,
  course_id   INT         NOT NULL,
  code        VARCHAR(20) NOT NULL,          -- e.g. PLO1, PO3, WK2
  description TEXT        NOT NULL,
  category    VARCHAR(100) NULL,             -- e.g. Technical, Communication, Ethics
  sort_order  INT         NOT NULL DEFAULT 0,
  created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_plo_course (course_id)
);

-- 3. Add faculty_id to courses (nullable for backward compat)
ALTER TABLE courses
  ADD COLUMN IF NOT EXISTS faculty_id INT NULL AFTER institution_id;

-- 4. Add PLO mapping to subjects
--    plo_mapping JSON: [{"clo_index":0,"plo_ids":[1,3]},{"clo_index":1,"plo_ids":[2]}]
ALTER TABLE subjects
  ADD COLUMN IF NOT EXISTS plo_mapping JSON NULL AFTER learning_outcomes;

-- 5. Approval workflow on user_subjects
ALTER TABLE user_subjects
  ADD COLUMN IF NOT EXISTS status      VARCHAR(20) NOT NULL DEFAULT 'approved' AFTER show_in_resume,
  ADD COLUMN IF NOT EXISTS approved_by INT NULL,
  ADD COLUMN IF NOT EXISTS approved_at TIMESTAMP NULL;

-- Backfill: existing enrolments are auto-approved
UPDATE user_subjects SET status = 'approved' WHERE status = 'approved' OR status IS NULL OR status = '';

-- 6. Optional: index for fast approval queries
ALTER TABLE user_subjects
  ADD INDEX IF NOT EXISTS idx_us_status (status);
