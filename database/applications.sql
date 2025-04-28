CREATE TABLE `visa_applications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reference_number` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT 'The applicant user',
  `professional_service_id` int(11) NOT NULL COMMENT 'The specific service being applied for',
  `consultation_mode_id` int(11) NOT NULL,
  `status` enum('draft','submitted','in_review','document_requested','processing','approved','rejected','cancelled') NOT NULL DEFAULT 'draft',
  `applicant_notes` text DEFAULT NULL COMMENT 'Notes from the applicant',
  `professional_notes` text DEFAULT NULL COMMENT 'Private notes for professional',
  `rejection_reason` text DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_number` (`reference_number`),
  KEY `idx_applications_user` (`user_id`),
  KEY `idx_applications_service` (`professional_service_id`),
  KEY `idx_applications_mode` (`consultation_mode_id`),
  KEY `idx_applications_status` (`status`),
  CONSTRAINT `applications_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `applications_service_id_fk` FOREIGN KEY (`professional_service_id`) REFERENCES `professional_visa_services` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `applications_mode_id_fk` FOREIGN KEY (`consultation_mode_id`) REFERENCES `consultation_modes` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `application_team_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `team_member_id` int(11) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL, -- user_id of the assigner (optional)
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_assignment` (`application_id`, `team_member_id`),
  FOREIGN KEY (`application_id`) REFERENCES `visa_applications`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`team_member_id`) REFERENCES `team_members`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
