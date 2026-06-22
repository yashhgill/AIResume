-- Migration: Add template field to resumes table
-- Run this SQL to add template support

ALTER TABLE `resumes` 
ADD COLUMN `template` varchar(50) DEFAULT NULL AFTER `tone`;

