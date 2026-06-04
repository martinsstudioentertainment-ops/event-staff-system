-- Phase 40: Add PSA licence fields to staff table
-- This migration adds fields for PSA licence information and document images

ALTER TABLE staff 
ADD COLUMN psa_licence VARCHAR(100) NULL DEFAULT NULL AFTER bank_iban,
ADD COLUMN psa_expiry_date DATE NULL DEFAULT NULL AFTER psa_licence,
ADD COLUMN psa_front_image VARCHAR(255) NULL DEFAULT NULL AFTER psa_expiry_date,
ADD COLUMN psa_back_image VARCHAR(255) NULL DEFAULT NULL AFTER psa_front_image,
ADD COLUMN profile_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER psa_back_image,
ADD INDEX idx_psa_licence (psa_licence);
