CREATE TABLE professional_clients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    professional_entity_id INT NOT NULL,
    applicant_id INT NOT NULL,
    status ENUM('pending', 'active', 'inactive', 'archived') DEFAULT 'pending',
    assigned_team_member_id INT NULL,
    source ENUM('booking', 'invitation', 'referral', 'direct') NOT NULL,
    source_details TEXT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (professional_entity_id) REFERENCES professional_entities(id),
    FOREIGN KEY (applicant_id) REFERENCES users(id),
    FOREIGN KEY (assigned_team_member_id) REFERENCES team_members(id),
    UNIQUE KEY (professional_entity_id, applicant_id)
);
