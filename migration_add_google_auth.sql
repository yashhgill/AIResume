-- Migration: Add Google Sign-In support to users table
-- Run this against your LOCAL ai_resume_db (phpMyAdmin > SQL tab, or mysql CLI)

ALTER TABLE `users`
  ADD COLUMN `google_id` varchar(255) DEFAULT NULL AFTER `password_hash`,
  ADD COLUMN `auth_provider` varchar(20) NOT NULL DEFAULT 'local' AFTER `google_id`,
  ADD COLUMN `avatar_url` varchar(500) DEFAULT NULL AFTER `auth_provider`;

-- Speeds up "find user by google_id" lookups during Google login
ALTER TABLE `users` ADD INDEX `idx_google_id` (`google_id`);

-- Note: `email` already has a UNIQUE key in ai_resume_db.sql, which is what
-- lets Google sign-in safely match/link to an existing local account by email.
