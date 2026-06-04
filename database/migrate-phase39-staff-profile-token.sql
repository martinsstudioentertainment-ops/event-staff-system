-- Phase 39: Add profile_token to staff table for public profile access
-- This migration adds a unique token that allows staff to access their profile page

ALTER TABLE staff 
ADD COLUMN profile_token VARCHAR(64) NULL UNIQUE AFTER blacklist_reason,
ADD INDEX idx_profile_token (profile_token);

-- Generate profile tokens for existing staff
UPDATE staff 
SET profile_token = CONCAT(SHA2(CONCAT(id, email, created_at, RAND()), 256), SUBSTRING(MD5(RAND()), 1, 8))
WHERE profile_token IS NULL;
