-- Add missing columns to visa_applications table
ALTER TABLE `visa_applications` 
ADD COLUMN `invitation_status` ENUM('pending', 'sent', 'accepted', 'rejected') DEFAULT 'pending' AFTER `status`,
ADD COLUMN `invitation_sent_at` DATETIME NULL AFTER `invitation_status`; 