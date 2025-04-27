-- Countries table
CREATE TABLE `countries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` char(2) NOT NULL COMMENT 'ISO 3166-1 alpha-2 country code',
  `flag_image` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_countries_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Visa types table (linked to countries)
CREATE TABLE `visa_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `country_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) DEFAULT NULL COMMENT 'Country-specific visa code if applicable',
  `description` text DEFAULT NULL,
  `processing_time` varchar(100) DEFAULT NULL COMMENT 'Typical processing time range',
  `validity_period` varchar(100) DEFAULT NULL COMMENT 'How long visa is typically valid',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `country_visa_unique` (`country_id`, `name`),
  KEY `idx_visa_types_country` (`country_id`),
  KEY `idx_visa_types_active` (`is_active`),
  CONSTRAINT `visa_types_country_id_fk` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Service types available in the system
CREATE TABLE `service_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default service types
INSERT INTO `service_types` (`name`, `description`) VALUES
('Complete', 'Full service offering where professional handles everything'),
('Guidance', 'Advisory service where professional provides direction and advice'),
('Do It Yourself', 'Self-service option with professional resources and support'),
('Consultation', 'One-time advisory session'),
('Review', 'Professional review of client-provided materials');

-- Professional visa services 
CREATE TABLE `professional_visa_services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_id` int(11) NOT NULL COMMENT 'Reference to professional_entities table',
  `country_id` int(11) NOT NULL,
  `visa_type_id` int(11) NOT NULL,
  `service_type_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `estimated_processing_days` int(11) DEFAULT NULL,
  `success_rate` decimal(5,2) DEFAULT NULL COMMENT 'Optional success rate percentage',
  `requirements` text DEFAULT NULL COMMENT 'Requirements for this visa application',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `entity_country_visa_service_unique` (`entity_id`, `country_id`, `visa_type_id`, `service_type_id`),
  KEY `idx_visa_services_entity` (`entity_id`),
  KEY `idx_visa_services_country` (`country_id`),
  KEY `idx_visa_services_visa_type` (`visa_type_id`),
  KEY `idx_visa_services_service_type` (`service_type_id`),
  KEY `idx_visa_services_active` (`is_active`),
  CONSTRAINT `visa_services_entity_id_fk` FOREIGN KEY (`entity_id`) REFERENCES `professional_entities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `visa_services_country_id_fk` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `visa_services_visa_type_id_fk` FOREIGN KEY (`visa_type_id`) REFERENCES `visa_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `visa_services_service_type_id_fk` FOREIGN KEY (`service_type_id`) REFERENCES `service_types` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Consultation modes available in the system
CREATE TABLE `consultation_modes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `icon` varchar(100) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default consultation modes
INSERT INTO `consultation_modes` (`name`, `description`) VALUES
('Email', 'Consultation over email'),
('Google Meet', 'Video meeting through Google Meet'),
('Phone Call', 'Voice call over phone'),
('In-person', 'Physical in-person meeting'),
('Zoom', 'Video meeting through Zoom'),
('Custom', 'Custom consultation method');

-- Link visa services to available consultation modes
CREATE TABLE `visa_service_consultation_modes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `visa_service_id` int(11) NOT NULL,
  `mode_id` int(11) NOT NULL,
  `price_adjustment` decimal(10,2) DEFAULT 0.00 COMMENT 'Price adjustment for this mode (+ or -)',
  `additional_info` text DEFAULT NULL COMMENT 'Any mode-specific details',
  `is_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Is this the default consultation mode',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `visa_service_mode_unique` (`visa_service_id`, `mode_id`),
  KEY `idx_visa_service_modes_service_id` (`visa_service_id`),
  KEY `idx_visa_service_modes_mode_id` (`mode_id`),
  CONSTRAINT `visa_service_modes_service_id_fk` FOREIGN KEY (`visa_service_id`) REFERENCES `professional_visa_services` (`id`) ON DELETE CASCADE,
  CONSTRAINT `visa_service_modes_mode_id_fk` FOREIGN KEY (`mode_id`) REFERENCES `consultation_modes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Professional expertise in countries (for search/filter functionality)
CREATE TABLE `professional_country_expertise` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_id` int(11) NOT NULL,
  `country_id` int(11) NOT NULL,
  `expertise_level` enum('beginner','intermediate','expert') DEFAULT 'intermediate',
  `years_experience` int(11) DEFAULT NULL,
  `success_rate` decimal(5,2) DEFAULT NULL,
  `applications_count` int(11) DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `entity_country_unique` (`entity_id`, `country_id`),
  KEY `idx_expertise_entity` (`entity_id`),
  KEY `idx_expertise_country` (`country_id`),
  KEY `idx_expertise_level_success` (`expertise_level`, `success_rate`),
  CONSTRAINT `country_expertise_entity_id_fk` FOREIGN KEY (`entity_id`) REFERENCES `professional_entities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `country_expertise_country_id_fk` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;