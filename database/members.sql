CREATE TABLE `members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `professional_id` int(11) NOT NULL COMMENT 'The professional who created this member',
  `position` varchar(100) DEFAULT NULL COMMENT 'Job title or position',
  `access_level` enum('limited','standard','advanced') NOT NULL DEFAULT 'standard' COMMENT 'Permission level within the professional account',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `professional_id` (`professional_id`),
  KEY `idx_members_is_active` (`is_active`),
  CONSTRAINT `members_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `members_professional_id_fk` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `team_roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Indicates if this is a system-defined role',
  `professional_id` int(11) DEFAULT NULL COMMENT 'NULL for system roles, professional ID for custom roles',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_role_name_per_professional` (`name`, `professional_id`),
  KEY `professional_id` (`professional_id`),
  KEY `idx_member_roles_is_active` (`is_active`),
  CONSTRAINT `member_roles_professional_id_fk` FOREIGN KEY (`professional_id`) REFERENCES `professionals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert system-defined member roles
INSERT INTO `member_roles` (name, description, is_system) VALUES
('Case Manager', 'Manages visa applications from start to finish, coordinates with applicants and other team members', 1),
('Document Creator', 'Prepares and reviews all required legal documents for applications', 1),
('Career Consultant', 'Advises applicants on career opportunities and prepares related documentation', 1),
('Business Plan Creator', 'Develops comprehensive business plans for business/investor visa applications', 1),
('Immigration Assistant', 'Handles administrative tasks related to immigration applications', 1),
('Social Media Manager', 'Manages social media presence and marketing campaigns', 1),
('Leads & CRM Manager', 'Handles lead generation, follow-ups and maintains the CRM system', 1),
('Custom Role', 'Customizable role with specific permissions', 1);