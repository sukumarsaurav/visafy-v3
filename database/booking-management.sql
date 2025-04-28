-- Time slots configuration table (for recurring timeslots)
CREATE TABLE `timeslot_configurations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_id` int(11) NOT NULL COMMENT 'Reference to professional_entities table',
  `day_of_week` tinyint(1) NOT NULL COMMENT '0=Sunday, 1=Monday, etc.',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Whether this timeslot is generally available',
  `slot_duration` int(11) NOT NULL DEFAULT 60 COMMENT 'Duration in minutes',
  `buffer_time` int(11) NOT NULL DEFAULT 0 COMMENT 'Buffer time between slots in minutes',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_timeslot_entity` (`entity_id`),
  KEY `idx_timeslot_day` (`day_of_week`),
  CONSTRAINT `timeslot_entity_id_fk` FOREIGN KEY (`entity_id`) REFERENCES `professional_entities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Specific date availability overrides
CREATE TABLE `availability_overrides` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_id` int(11) NOT NULL COMMENT 'Reference to professional_entities table',
  `date` date NOT NULL,
  `is_available` tinyint(1) NOT NULL COMMENT '0=Unavailable (day off), 1=Available',
  `reason` varchar(255) DEFAULT NULL COMMENT 'Reason for availability change',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `entity_date_unique` (`entity_id`, `date`),
  KEY `idx_override_entity` (`entity_id`),
  KEY `idx_override_date` (`date`),
  CONSTRAINT `availability_entity_id_fk` FOREIGN KEY (`entity_id`) REFERENCES `professional_entities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Specific timeslot overrides for individual dates
CREATE TABLE `timeslot_overrides` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_id` int(11) NOT NULL COMMENT 'Reference to professional_entities table',
  `date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_available` tinyint(1) NOT NULL COMMENT '0=Unavailable, 1=Available',
  `reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_timeslot_override_entity` (`entity_id`),
  KEY `idx_timeslot_override_date` (`date`),
  CONSTRAINT `timeslot_override_entity_id_fk` FOREIGN KEY (`entity_id`) REFERENCES `professional_entities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Company capacity settings
CREATE TABLE `company_booking_capacity` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL COMMENT 'Reference to company_professionals table',
  `max_concurrent_bookings` int(11) NOT NULL DEFAULT 1 COMMENT 'Maximum number of concurrent bookings',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_id` (`company_id`),
  CONSTRAINT `capacity_company_id_fk` FOREIGN KEY (`company_id`) REFERENCES `company_professionals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Service-specific booking settings
CREATE TABLE `service_booking_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service_id` int(11) NOT NULL COMMENT 'Reference to professional_visa_services table',
  `duration` int(11) NOT NULL DEFAULT 60 COMMENT 'Duration in minutes',
  `buffer_time` int(11) NOT NULL DEFAULT 0 COMMENT 'Buffer time between bookings in minutes',
  `advance_booking_days` int(11) NOT NULL DEFAULT 30 COMMENT 'How many days in advance clients can book',
  `min_booking_notice` int(11) NOT NULL DEFAULT 24 COMMENT 'Minimum notice required in hours',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_id` (`service_id`),
  CONSTRAINT `settings_service_id_fk` FOREIGN KEY (`service_id`) REFERENCES `professional_visa_services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Bookings table
CREATE TABLE `bookings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reference_number` varchar(20) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT 'Applicant who made the booking',
  `entity_id` int(11) NOT NULL COMMENT 'Professional entity being booked',
  `service_id` int(11) NOT NULL COMMENT 'Which service is being booked',
  `consultation_mode_id` int(11) NOT NULL COMMENT 'Mode of consultation',
  `booking_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `status` enum('pending','confirmed','rescheduled','cancelled','completed','no_show') NOT NULL DEFAULT 'pending',
  `cancellation_reason` text DEFAULT NULL,
  `cancellation_date` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL COMMENT 'Additional notes from applicant',
  `professional_notes` text DEFAULT NULL COMMENT 'Private notes for professional',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_number` (`reference_number`),
  KEY `idx_booking_user` (`user_id`),
  KEY `idx_booking_entity` (`entity_id`),
  KEY `idx_booking_service` (`service_id`),
  KEY `idx_booking_mode` (`consultation_mode_id`),
  KEY `idx_booking_date` (`booking_date`),
  KEY `idx_booking_status` (`status`),
  CONSTRAINT `booking_user_id_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `booking_entity_id_fk` FOREIGN KEY (`entity_id`) REFERENCES `professional_entities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `booking_service_id_fk` FOREIGN KEY (`service_id`) REFERENCES `professional_visa_services` (`id`) ON DELETE CASCADE,
  CONSTRAINT `booking_mode_id_fk` FOREIGN KEY (`consultation_mode_id`) REFERENCES `consultation_modes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Booking attendees (for company professionals with multiple team members)
CREATE TABLE `booking_attendees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `team_member_id` int(11) NOT NULL COMMENT 'Team member assigned to the booking',
  `is_primary` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether this is the primary attendee',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `booking_member_unique` (`booking_id`, `team_member_id`),
  KEY `idx_attendee_booking` (`booking_id`),
  KEY `idx_attendee_member` (`team_member_id`),
  CONSTRAINT `attendee_booking_id_fk` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendee_member_id_fk` FOREIGN KEY (`team_member_id`) REFERENCES `team_members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Booking reschedule history
CREATE TABLE `booking_reschedule_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `previous_date` date NOT NULL,
  `previous_start_time` time NOT NULL,
  `previous_end_time` time NOT NULL,
  `new_date` date NOT NULL,
  `new_start_time` time NOT NULL,
  `new_end_time` time NOT NULL,
  `rescheduled_by` enum('client','professional') NOT NULL,
  `reason` text DEFAULT NULL,
  `rescheduled_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_reschedule_booking` (`booking_id`),
  CONSTRAINT `reschedule_booking_id_fk` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Booking follow-ups
CREATE TABLE `booking_follow_ups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `follow_up_date` date NOT NULL,
  `status` enum('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL COMMENT 'User who created the follow-up',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_followup_booking` (`booking_id`),
  KEY `idx_followup_date` (`follow_up_date`),
  KEY `idx_followup_status` (`status`),
  CONSTRAINT `followup_booking_id_fk` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `followup_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Team member availability (for company professionals)
CREATE TABLE `team_member_availability` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `team_member_id` int(11) NOT NULL,
  `day_of_week` tinyint(1) NOT NULL COMMENT '0=Sunday, 1=Monday, etc.',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_team_availability_member` (`team_member_id`),
  KEY `idx_team_availability_day` (`day_of_week`),
  CONSTRAINT `availability_member_id_fk` FOREIGN KEY (`team_member_id`) REFERENCES `team_members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Triggers to enforce booking constraints

-- Trigger to prevent double-booking for individual professionals
DELIMITER $$
CREATE TRIGGER check_individual_double_booking BEFORE INSERT ON bookings
FOR EACH ROW
BEGIN
    DECLARE entity_type VARCHAR(20);
    
    -- Get the entity type of the professional
    SELECT pe.entity_type INTO entity_type
    FROM professional_entities pe
    WHERE pe.id = NEW.entity_id;
    
    -- For individual professionals, check for overlapping bookings
    IF entity_type = 'individual' THEN
        IF EXISTS (
            SELECT 1 
            FROM bookings b
            WHERE b.entity_id = NEW.entity_id
              AND b.booking_date = NEW.booking_date
              AND b.status NOT IN ('cancelled', 'no_show')
              AND (
                  (NEW.start_time BETWEEN b.start_time AND b.end_time)
                  OR (NEW.end_time BETWEEN b.start_time AND b.end_time)
                  OR (NEW.start_time <= b.start_time AND NEW.end_time >= b.end_time)
              )
        ) THEN
            SIGNAL SQLSTATE '45000' 
            SET MESSAGE_TEXT = 'There is already a booking at this time for this professional';
        END IF;
    END IF;
END$$
DELIMITER ;

-- Trigger to check company booking capacity
DELIMITER $$
CREATE TRIGGER check_company_booking_capacity BEFORE INSERT ON bookings
FOR EACH ROW
BEGIN
    DECLARE entity_type VARCHAR(20);
    DECLARE company_id INT;
    DECLARE max_bookings INT;
    DECLARE current_bookings INT;
    
    -- Get the entity type of the professional
    SELECT pe.entity_type INTO entity_type
    FROM professional_entities pe
    WHERE pe.id = NEW.entity_id;
    
    -- For company professionals, check booking capacity
    IF entity_type = 'company' THEN
        -- Get the company ID
        SELECT cp.id INTO company_id
        FROM company_professionals cp
        JOIN professional_entities pe ON cp.entity_id = pe.id
        WHERE pe.id = NEW.entity_id;
        
        -- Get the maximum concurrent bookings
        SELECT COALESCE(cbc.max_concurrent_bookings, 1) INTO max_bookings
        FROM company_booking_capacity cbc
        WHERE cbc.company_id = company_id;
        
        -- Count current bookings for this timeslot
        SELECT COUNT(*) INTO current_bookings
        FROM bookings b
        WHERE b.entity_id = NEW.entity_id
          AND b.booking_date = NEW.booking_date
          AND b.status NOT IN ('cancelled', 'no_show')
          AND (
              (NEW.start_time BETWEEN b.start_time AND b.end_time)
              OR (NEW.end_time BETWEEN b.start_time AND b.end_time)
              OR (NEW.start_time <= b.start_time AND NEW.end_time >= b.end_time)
          );
        
        -- Check if adding this booking would exceed capacity
        IF current_bookings >= max_bookings THEN
            SIGNAL SQLSTATE '45000' 
            SET MESSAGE_TEXT = 'This company has reached maximum booking capacity for this timeslot';
        END IF;
    END IF;
END$$
DELIMITER ;
