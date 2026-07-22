-- ZNP Development v5.2.0
-- Safe, rerunnable upgrade: project-specific non-vendor contacts.

CREATE TABLE IF NOT EXISTS construction_project_contacts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    construction_project_id INT NOT NULL,
    role_title VARCHAR(150) NOT NULL,
    organization_name VARCHAR(190) NOT NULL DEFAULT '',
    contact_name VARCHAR(190) NOT NULL DEFAULT '',
    phone VARCHAR(50) NOT NULL DEFAULT '',
    email VARCHAR(190) NOT NULL DEFAULT '',
    address TEXT NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by_admin_user_id INT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_project_contact_active (construction_project_id, is_active),
    KEY idx_project_contact_role (role_title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
