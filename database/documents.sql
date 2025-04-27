-- Document categories for organization
CREATE TABLE `document_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert common document categories
INSERT INTO `document_categories` (`name`, `description`) VALUES
('Identity', 'Identity documents like passport, ID card'),
('Education', 'Educational certificates and transcripts'),
('Employment', 'Employment proof and work history'),
('Financial', 'Bank statements and financial documents'),
('Immigration', 'Previous visas and immigration history'),
('Medical', 'Medical certificates and health records'),
('Supporting', 'Supporting documents like cover letters, photos');

-- Document types master table
CREATE TABLE `document_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `idx_document_category` (`category_id`),
  CONSTRAINT `document_types_category_id_fk` FOREIGN KEY (`category_id`) REFERENCES `document_categories` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert common document types
INSERT INTO `document_types` (`category_id`, `name`, `description`) VALUES
(1, 'Passport', 'Valid passport with at least 6 months validity'),
(1, 'National ID Card', 'Government-issued national identification card'),
(2, 'Degree Certificate', 'University or college degree certificate'),
(2, 'Transcripts', 'Academic transcripts and mark sheets'),
(3, 'Employment Contract', 'Current employment contract'),
(3, 'Experience Letter', 'Work experience letter from employer'),
(4, 'Bank Statement', 'Bank statement for the last 6 months'),
(4, 'Income Tax Returns', 'Income tax returns for the last 3 years'),
(5, 'Previous Visa', 'Copy of previous visas'),
(6, 'Medical Certificate', 'Medical fitness certificate'),
(7, 'Photographs', 'Passport-sized photographs');

-- Required documents for each visa type
CREATE TABLE `visa_required_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `visa_type_id` int(11) NOT NULL,
  `document_type_id` int(11) NOT NULL,
  `is_mandatory` tinyint(1) NOT NULL DEFAULT 1,
  `additional_requirements` text DEFAULT NULL COMMENT 'Specific formatting or content requirements',
  `order_display` int(11) DEFAULT 0 COMMENT 'Display order for document checklist',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `visa_document_unique` (`visa_type_id`, `document_type_id`),
  KEY `idx_required_docs_visa` (`visa_type_id`),
  KEY `idx_required_docs_document` (`document_type_id`),
  CONSTRAINT `required_docs_visa_type_id_fk` FOREIGN KEY (`visa_type_id`) REFERENCES `visa_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `required_docs_document_type_id_fk` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Applications submitted by users
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

-- Document requests from professionals to applicants
CREATE TABLE `application_document_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `document_type_id` int(11) NOT NULL,
  `status` enum('requested','submitted','approved','rejected') NOT NULL DEFAULT 'requested',
  `request_notes` text DEFAULT NULL COMMENT 'Special instructions for this document',
  `rejection_reason` text DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `is_mandatory` tinyint(1) NOT NULL DEFAULT 1,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `submitted_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `application_document_unique` (`application_id`, `document_type_id`),
  KEY `idx_doc_requests_application` (`application_id`),
  KEY `idx_doc_requests_document` (`document_type_id`),
  KEY `idx_doc_requests_status` (`status`),
  CONSTRAINT `doc_requests_application_id_fk` FOREIGN KEY (`application_id`) REFERENCES `visa_applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `doc_requests_document_type_id_fk` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Uploaded documents from applicants
CREATE TABLE `application_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `file_size` int(11) NOT NULL COMMENT 'Size in bytes',
  `mime_type` varchar(100) NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `review_notes` text DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_documents_request` (`request_id`),
  CONSTRAINT `documents_request_id_fk` FOREIGN KEY (`request_id`) REFERENCES `application_document_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Custom document templates created by professionals
CREATE TABLE `professional_document_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `visa_type_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether template is shared with applicants',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_templates_entity` (`entity_id`),
  KEY `idx_templates_visa_type` (`visa_type_id`),
  CONSTRAINT `templates_entity_id_fk` FOREIGN KEY (`entity_id`) REFERENCES `professional_entities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `templates_visa_type_id_fk` FOREIGN KEY (`visa_type_id`) REFERENCES `visa_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;