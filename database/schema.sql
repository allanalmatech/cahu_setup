-- =====================================================================
--  COOPERATIVE AGRIBUSINESS HUB UGANDA (CAHU)
--  Complete Agricultural Management, Farm Monitoring & Market
--  Traceability Platform
--
--  Database schema - MySQL 8.0+ / InnoDB / utf8mb4
--
--  Chain modelled end to end:
--  FARMER -> GARDEN -> CROP -> PLANTING -> ACTIVITIES -> INPUTS
--  -> INSPECTION -> HARVEST -> BATCH -> MARKET -> SALE -> EXPORT
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- ---------------------------------------------------------------------
-- No CREATE DATABASE and no USE here on purpose.
--
-- This file used to select `cahu_platform` itself, which meant it silently
-- overrode whatever database the caller had already chosen: install.php
-- connected to the database named in config/.env, ran USE on it, and then
-- had the connection switched out from under it and imported every table
-- into cahu_platform instead. The failure was invisible until a CREATE TABLE
-- collided with a table that already existed over there.
--
-- The database name is configuration, so it is chosen by whoever runs the
-- import and never by this file:
--     php install.php                 (reads config/.env)
--     mysql -u USER -p --database=NAME < database/schema.sql
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- 1. ACCESS CONTROL / AUTHENTICATION
-- ---------------------------------------------------------------------

CREATE TABLE `users` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`              CHAR(36)        NOT NULL,
  `username`          VARCHAR(60)     NOT NULL,
  `email`             VARCHAR(150)    NOT NULL,
  `phone`             VARCHAR(20)     NULL,
  `password_hash`     VARCHAR(255)    NOT NULL,
  `first_name`        VARCHAR(80)     NOT NULL,
  `last_name`         VARCHAR(80)     NULL,
  `profile_photo`     VARCHAR(255)    NULL,
  `status`            ENUM('pending','active','suspended','deactivated') NOT NULL DEFAULT 'pending',
  `must_change_password` TINYINT(1)   NOT NULL DEFAULT 0,
  `last_login_at`     DATETIME        NULL,
  `last_login_ip`     VARCHAR(45)     NULL,
  `failed_attempts`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`      DATETIME        NULL,
  `password_changed_at` DATETIME      NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`        DATETIME        NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_uuid` (`uuid`),
  KEY `idx_users_status` (`status`),
  KEY `idx_users_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `roles` (
  `id`            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(80)  NOT NULL,
  `code`          VARCHAR(50)  NOT NULL,
  `description`   VARCHAR(255) NULL,
  `is_system`     TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `permissions` (
  `id`            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module`        VARCHAR(60)  NOT NULL,
  `name`          VARCHAR(120) NOT NULL,
  `code`          VARCHAR(100) NOT NULL,
  `description`   VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_code` (`code`),
  KEY `idx_permissions_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `role_permissions` (
  `role_id`       SMALLINT UNSIGNED NOT NULL,
  `permission_id` SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `idx_rp_permission` (`permission_id`),
  CONSTRAINT `fk_rp_role`       FOREIGN KEY (`role_id`)       REFERENCES `roles`(`id`)       ON DELETE CASCADE,
  CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_roles` (
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `role_id`       SMALLINT UNSIGNED NOT NULL,
  `assigned_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `assigned_by`   BIGINT UNSIGNED NULL,
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `idx_ur_role` (`role_id`),
  CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_resets` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `token_hash`    CHAR(64)     NOT NULL,
  `expires_at`    DATETIME     NOT NULL,
  `used_at`       DATETIME     NULL,
  `ip_address`    VARCHAR(45)  NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pr_token` (`token_hash`),
  KEY `idx_pr_user` (`user_id`),
  CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_histories` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       BIGINT UNSIGNED NULL,
  `username_attempted` VARCHAR(150) NULL,
  `success`       TINYINT(1)   NOT NULL DEFAULT 0,
  `reason`        VARCHAR(120) NULL,
  `ip_address`    VARCHAR(45)  NOT NULL,
  `user_agent`    VARCHAR(255) NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lh_user` (`user_id`),
  KEY `idx_lh_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_sessions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `session_id`    VARCHAR(128) NOT NULL,
  `ip_address`    VARCHAR(45)  NULL,
  `user_agent`    VARCHAR(255) NULL,
  `payload`       TEXT         NULL,
  `last_activity` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_us_session` (`session_id`),
  KEY `idx_us_user` (`user_id`),
  CONSTRAINT `fk_us_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Personal/mobile API access tokens for the offline-first farmer application.
-- Only the SHA-256 hash is stored, so a database leak cannot be replayed.
CREATE TABLE `api_tokens` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `name`          VARCHAR(80)     NOT NULL,
  `token_hash`    CHAR(64)        NOT NULL,
  `scopes`        TEXT            NULL,
  `device_info`   VARCHAR(255)    NULL,
  `last_used_at`  DATETIME        NULL,
  `expires_at`    DATETIME        NULL,
  `revoked_at`    DATETIME        NULL,
  `created_by`    BIGINT UNSIGNED NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_token_hash` (`token_hash`),
  KEY `idx_api_token_user` (`user_id`),
  CONSTRAINT `fk_api_token_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_api_token_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. GEOGRAPHY
-- ---------------------------------------------------------------------

CREATE TABLE `regions` (
  `id`            TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(80) NOT NULL,
  `code`          VARCHAR(20) NOT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_regions_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `districts` (
  `id`            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `region_id`     TINYINT UNSIGNED NOT NULL,
  `name`          VARCHAR(100) NOT NULL,
  `code`          VARCHAR(20)  NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_districts_code` (`code`),
  KEY `idx_districts_region` (`region_id`),
  CONSTRAINT `fk_districts_region` FOREIGN KEY (`region_id`) REFERENCES `regions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sub_counties` (
  `id`            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `district_id`   SMALLINT UNSIGNED NOT NULL,
  `name`          VARCHAR(100) NOT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sc_district` (`district_id`),
  CONSTRAINT `fk_sc_district` FOREIGN KEY (`district_id`) REFERENCES `districts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `parishes` (
  `id`            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sub_county_id` SMALLINT UNSIGNED NOT NULL,
  `name`          VARCHAR(100) NOT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_parish_sc` (`sub_county_id`),
  CONSTRAINT `fk_parish_sc` FOREIGN KEY (`sub_county_id`) REFERENCES `sub_counties`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `villages` (
  `id`            MEDIUMINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parish_id`     SMALLINT UNSIGNED NOT NULL,
  `name`          VARCHAR(120) NOT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_village_parish` (`parish_id`),
  CONSTRAINT `fk_village_parish` FOREIGN KEY (`parish_id`) REFERENCES `parishes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. FARMERS, GROUPS, OFFICERS
-- ---------------------------------------------------------------------

CREATE TABLE `field_officers` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `officer_code`  VARCHAR(30)  NOT NULL,
  `user_id`       BIGINT UNSIGNED NULL,
  `full_name`     VARCHAR(150) NOT NULL,
  `phone`         VARCHAR(20)  NULL,
  `alt_phone`     VARCHAR(20)  NULL,
  `email`         VARCHAR(150) NULL,
  `employee_no`   VARCHAR(60)  NULL,
  `region_id`     TINYINT UNSIGNED NULL,
  `district_id`   SMALLINT UNSIGNED NULL,
  `status`        ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `photo`         VARCHAR(255) NULL,
  `notes`         TEXT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_officer_code` (`officer_code`),
  KEY `idx_officer_user` (`user_id`),
  KEY `idx_officer_status` (`status`),
  KEY `idx_officer_district` (`district_id`),
  CONSTRAINT `fk_officer_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`)        ON DELETE SET NULL,
  CONSTRAINT `fk_officer_region`   FOREIGN KEY (`region_id`)   REFERENCES `regions`(`id`)      ON DELETE SET NULL,
  CONSTRAINT `fk_officer_district` FOREIGN KEY (`district_id`) REFERENCES `districts`(`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `officer_assignments` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `officer_id`    BIGINT UNSIGNED NOT NULL,
  `entity_type`   ENUM('farmer','garden','group','project','district','sub_county') NOT NULL,
  `entity_id`     BIGINT UNSIGNED NOT NULL,
  `assigned_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `assigned_by`   BIGINT UNSIGNED NULL,
  `active`        TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_oa_unique` (`officer_id`,`entity_type`,`entity_id`),
  KEY `idx_oa_entity` (`entity_type`,`entity_id`),
  CONSTRAINT `fk_oa_officer` FOREIGN KEY (`officer_id`) REFERENCES `field_officers`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oa_user`    FOREIGN KEY (`assigned_by`) REFERENCES `users`(`id`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `farmer_groups` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_code`    VARCHAR(30)  NOT NULL,
  `name`          VARCHAR(150) NOT NULL,
  `group_type`    ENUM('cooperative','producer_group','farmer_association','village_group','other') NOT NULL DEFAULT 'cooperative',
  `registration_no` VARCHAR(80) NULL,
  `region_id`     TINYINT UNSIGNED NULL,
  `district_id`   SMALLINT UNSIGNED NULL,
  `sub_county_id` SMALLINT UNSIGNED NULL,
  `parish_id`     SMALLINT UNSIGNED NULL,
  `village_id`    MEDIUMINT UNSIGNED NULL,
  `address`       VARCHAR(255) NULL,
  `contact_person` VARCHAR(150) NULL,
  `phone`         VARCHAR(20)  NULL,
  `email`         VARCHAR(150) NULL,
  `latitude`      DECIMAL(10,7) NULL,
  `longitude`     DECIMAL(10,7) NULL,
  `registration_date` DATE NULL,
  `status`        ENUM('active','inactive','dissolved') NOT NULL DEFAULT 'active',
  `description`   TEXT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_group_code` (`group_code`),
  KEY `idx_group_district` (`district_id`),
  KEY `idx_group_status` (`status`),
  CONSTRAINT `fk_group_region`  FOREIGN KEY (`region_id`)   REFERENCES `regions`(`id`)      ON DELETE SET NULL,
  CONSTRAINT `fk_group_district` FOREIGN KEY (`district_id`) REFERENCES `districts`(`id`)    ON DELETE SET NULL,
  CONSTRAINT `fk_group_sc`      FOREIGN KEY (`sub_county_id`) REFERENCES `sub_counties`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_group_parish`  FOREIGN KEY (`parish_id`)   REFERENCES `parishes`(`id`)     ON DELETE SET NULL,
  CONSTRAINT `fk_group_village` FOREIGN KEY (`village_id`)  REFERENCES `villages`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `farmers` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `farmer_code`       VARCHAR(30)  NOT NULL,
  `user_id`           BIGINT UNSIGNED NULL,
  `full_name`         VARCHAR(150) NOT NULL,
  `gender`            ENUM('male','female','other') NULL,
  `date_of_birth`     DATE NULL,
  `national_id`       VARCHAR(60)  NULL,
  `phone`             VARCHAR(20)  NOT NULL,
  `alt_phone`         VARCHAR(20)  NULL,
  `email`             VARCHAR(150) NULL,
  `profile_photo`     VARCHAR(255) NULL,
  `region_id`         TINYINT UNSIGNED NULL,
  `district_id`       SMALLINT UNSIGNED NULL,
  `sub_county_id`     SMALLINT UNSIGNED NULL,
  `parish_id`         SMALLINT UNSIGNED NULL,
  `village_id`        MEDIUMINT UNSIGNED NULL,
  `village_name`      VARCHAR(120) NULL,
  `address`           VARCHAR(255) NULL,
  `latitude`          DECIMAL(10,7) NULL,
  `longitude`         DECIMAL(10,7) NULL,
  `registration_date` DATE NOT NULL,
  `status`            ENUM('active','inactive','pending','deceased','exited') NOT NULL DEFAULT 'active',
  `farmer_type`       ENUM('smallholder','commercial','youth','woman','special_needs') NOT NULL DEFAULT 'smallholder',
  `group_id`          BIGINT UNSIGNED NULL,
  `officer_id`        BIGINT UNSIGNED NULL,
  `project_code`      VARCHAR(60)  NULL,
  `education_level`   ENUM('none','primary','secondary','tertiary','other') NULL,
  `household_size`    SMALLINT UNSIGNED NULL,
  `bank_name`         VARCHAR(120) NULL,
  `bank_account`      VARCHAR(60)  NULL,
  `mobile_money`      VARCHAR(20)  NULL,
  `notes`             TEXT NULL,
  `created_by`        BIGINT UNSIGNED NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`        DATETIME NULL,
  `client_uuid`       CHAR(36) NULL COMMENT 'Offline sync deduplication key',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_farmer_code` (`farmer_code`),
  UNIQUE KEY `uq_farmer_client_uuid` (`client_uuid`),
  KEY `idx_farmer_user` (`user_id`),
  KEY `idx_farmer_phone` (`phone`),
  KEY `idx_farmer_nid` (`national_id`),
  KEY `idx_farmer_name` (`full_name`),
  KEY `idx_farmer_status` (`status`),
  KEY `idx_farmer_group` (`group_id`),
  KEY `idx_farmer_officer` (`officer_id`),
  KEY `idx_farmer_district` (`district_id`),
  KEY `idx_farmer_geo` (`latitude`,`longitude`),
  CONSTRAINT `fk_farmer_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`)           ON DELETE SET NULL,
  CONSTRAINT `fk_farmer_region`   FOREIGN KEY (`region_id`)   REFERENCES `regions`(`id`)         ON DELETE SET NULL,
  CONSTRAINT `fk_farmer_district` FOREIGN KEY (`district_id`) REFERENCES `districts`(`id`)       ON DELETE SET NULL,
  CONSTRAINT `fk_farmer_sc`       FOREIGN KEY (`sub_county_id`) REFERENCES `sub_counties`(`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_farmer_parish`   FOREIGN KEY (`parish_id`)   REFERENCES `parishes`(`id`)        ON DELETE SET NULL,
  CONSTRAINT `fk_farmer_village`  FOREIGN KEY (`village_id`)  REFERENCES `villages`(`id`)        ON DELETE SET NULL,
  CONSTRAINT `fk_farmer_group`    FOREIGN KEY (`group_id`)    REFERENCES `farmer_groups`(`id`)   ON DELETE SET NULL,
  CONSTRAINT `fk_farmer_officer`  FOREIGN KEY (`officer_id`)  REFERENCES `field_officers`(`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_farmer_creator`  FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `farmer_group_members` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id`      BIGINT UNSIGNED NOT NULL,
  `farmer_id`     BIGINT UNSIGNED NOT NULL,
  `role_in_group` ENUM('member','chairperson','treasurer','secretary','vice_chairperson') NOT NULL DEFAULT 'member',
  `joined_date`   DATE NULL,
  `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fgm_unique` (`group_id`,`farmer_id`),
  KEY `idx_fgm_farmer` (`farmer_id`),
  CONSTRAINT `fk_fgm_group`  FOREIGN KEY (`group_id`)  REFERENCES `farmer_groups`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fgm_farmer` FOREIGN KEY (`farmer_id`) REFERENCES `farmers`(`id`)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. GARDENS / GIS
-- ---------------------------------------------------------------------

CREATE TABLE `gardens` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `garden_code`   VARCHAR(30)  NOT NULL,
  `farmer_id`     BIGINT UNSIGNED NOT NULL,
  `name`          VARCHAR(150) NOT NULL,
  `acreage`       DECIMAL(10,3) NOT NULL DEFAULT 0.000,
  `latitude`      DECIMAL(10,7) NULL,
  `longitude`     DECIMAL(10,7) NULL,
  `altitude`      DECIMAL(8,2)  NULL,
  `region_id`     TINYINT UNSIGNED NULL,
  `district_id`   SMALLINT UNSIGNED NULL,
  `sub_county_id` SMALLINT UNSIGNED NULL,
  `parish_id`     SMALLINT UNSIGNED NULL,
  `village_id`    MEDIUMINT UNSIGNED NULL,
  `village_name`  VARCHAR(120) NULL,
  `land_ownership` ENUM('owned','rented','leased','communal','family','other') NOT NULL DEFAULT 'owned',
  `soil_type`     ENUM('clay','loam','sandy','silt','peat','chalk','red_laterite','black_cotton','other') NULL,
  `irrigation_status` ENUM('irrigated','rainfed','partially_irrigated','none') NOT NULL DEFAULT 'rainfed',
  `water_source`  ENUM('rain','river','lake','borehole','well','irrigation_canal','tap','other') NULL,
  `terrain`       ENUM('flat','gentle_slope','steep_slope','hilly','valley','other') NULL,
  `status`        ENUM('active','fallow','in_use','abandoned','disputed') NOT NULL DEFAULT 'active',
  `mapped`        TINYINT(1) NOT NULL DEFAULT 0,
  `notes`         TEXT NULL,
  `created_by`    BIGINT UNSIGNED NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME NULL,
  `client_uuid`   CHAR(36) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_garden_code` (`garden_code`),
  UNIQUE KEY `uq_garden_client_uuid` (`client_uuid`),
  KEY `idx_garden_farmer` (`farmer_id`),
  KEY `idx_garden_status` (`status`),
  KEY `idx_garden_district` (`district_id`),
  KEY `idx_garden_geo` (`latitude`,`longitude`),
  CONSTRAINT `fk_garden_farmer`  FOREIGN KEY (`farmer_id`)     REFERENCES `farmers`(`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_garden_region`  FOREIGN KEY (`region_id`)     REFERENCES `regions`(`id`)        ON DELETE SET NULL,
  CONSTRAINT `fk_garden_district` FOREIGN KEY (`district_id`)  REFERENCES `districts`(`id`)       ON DELETE SET NULL,
  CONSTRAINT `fk_garden_sc`      FOREIGN KEY (`sub_county_id`) REFERENCES `sub_counties`(`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_garden_parish`  FOREIGN KEY (`parish_id`)     REFERENCES `parishes`(`id`)       ON DELETE SET NULL,
  CONSTRAINT `fk_garden_village` FOREIGN KEY (`village_id`)    REFERENCES `villages`(`id`)       ON DELETE SET NULL,
  CONSTRAINT `fk_garden_creator` FOREIGN KEY (`created_by`)    REFERENCES `users`(`id`)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `garden_boundaries` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `garden_id`     BIGINT UNSIGNED NOT NULL,
  `geojson`       LONGTEXT NULL COMMENT 'Polygon geometry as GeoJSON',
  `area_acres`    DECIMAL(10,3) NULL,
  `mapped_by`     BIGINT UNSIGNED NULL,
  `mapped_at`     DATETIME NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gb_garden` (`garden_id`),
  CONSTRAINT `fk_gb_garden` FOREIGN KEY (`garden_id`) REFERENCES `gardens`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gb_user`   FOREIGN KEY (`mapped_by`) REFERENCES `users`(`id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only log of changes that affect a plot's identity or measurement.
-- Traceability disputes are settled from this table, so rows are never updated.
CREATE TABLE `garden_history` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `garden_id`    BIGINT UNSIGNED NOT NULL,
  `farmer_id`    BIGINT UNSIGNED NULL,
  `event_type`   VARCHAR(50)     NOT NULL,
  `from_value`   VARCHAR(255)    NULL,
  `to_value`     VARCHAR(255)    NULL,
  `reason`       VARCHAR(255)    NULL,
  `changed_by`   BIGINT UNSIGNED NULL,
  `created_by`   BIGINT UNSIGNED NULL,
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gh_garden` (`garden_id`),
  KEY `idx_gh_farmer` (`farmer_id`),
  KEY `idx_gh_type`   (`event_type`),
  CONSTRAINT `fk_gh_garden` FOREIGN KEY (`garden_id`)  REFERENCES `gardens`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_gh_farmer` FOREIGN KEY (`farmer_id`)  REFERENCES `farmers`(`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_gh_user`   FOREIGN KEY (`changed_by`)  REFERENCES `users`(`id`)    ON DELETE SET NULL,
  CONSTRAINT `fk_gh_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. CROPS, VARIETIES, SEASONS, LIFECYCLE
-- ---------------------------------------------------------------------

CREATE TABLE `crops` (
  `id`                SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `crop_code`         VARCHAR(30)  NOT NULL,
  `name`              VARCHAR(120) NOT NULL,
  `description`       TEXT NULL,
  `category`          ENUM('cereal','legume','beverage','vegetable','fruit','root_tuber','oilseed','fibre','other') NOT NULL DEFAULT 'other',
  `lifecycle_days`    SMALLINT UNSIGNED NULL,
  `expected_yield_per_acre` DECIMAL(12,3) NULL,
  `yield_unit`        VARCHAR(20) NULL,
  `primary_unit`      VARCHAR(20) NULL,
  `is_perishable`     TINYINT(1) NOT NULL DEFAULT 1,
  `status`            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_crop_code` (`crop_code`),
  KEY `idx_crop_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `crop_varieties` (
  `id`                SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `crop_id`           SMALLINT UNSIGNED NOT NULL,
  `variety_code`      VARCHAR(30)  NOT NULL,
  `name`              VARCHAR(120) NOT NULL,
  `maturity_days`     SMALLINT UNSIGNED NULL,
  `expected_yield_per_acre` DECIMAL(12,3) NULL,
  `yield_unit`        VARCHAR(20) NULL,
  `description`       TEXT NULL,
  `recommended_areas` VARCHAR(255) NULL,
  `status`            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_variety_code` (`variety_code`),
  KEY `idx_variety_crop` (`crop_id`),
  CONSTRAINT `fk_variety_crop` FOREIGN KEY (`crop_id`) REFERENCES `crops`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `crop_activity_templates` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `crop_id`       SMALLINT UNSIGNED NULL COMMENT 'NULL = applies to all crops',
  `variety_id`    SMALLINT UNSIGNED NULL COMMENT 'NULL = applies to all varieties of the crop',
  `activity_type` VARCHAR(80)  NOT NULL,
  `description`   VARCHAR(255) NULL,
  `day_offset`    SMALLINT NOT NULL COMMENT 'Days after planting',
  `duration_days` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `assign_role`   ENUM('farmer','officer','both') NOT NULL DEFAULT 'farmer',
  `is_mandatory`  TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `active`        TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_cat_crop` (`crop_id`,`variety_id`,`active`),
  CONSTRAINT `fk_cat_crop`    FOREIGN KEY (`crop_id`)    REFERENCES `crops`(`id`)           ON DELETE CASCADE,
  CONSTRAINT `fk_cat_variety` FOREIGN KEY (`variety_id`) REFERENCES `crop_varieties`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `seasons` (
  `id`            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `season_code`   VARCHAR(20)  NOT NULL,
  `name`          VARCHAR(120) NOT NULL,
  `start_date`    DATE NOT NULL,
  `end_date`      DATE NOT NULL,
  `status`        ENUM('upcoming','open','closed') NOT NULL DEFAULT 'open',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_season_code` (`season_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `plantings` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `planting_code`     VARCHAR(30)  NOT NULL,
  `farmer_id`         BIGINT UNSIGNED NOT NULL,
  `garden_id`         BIGINT UNSIGNED NOT NULL,
  `crop_id`           SMALLINT UNSIGNED NOT NULL,
  `variety_id`        SMALLINT UNSIGNED NULL,
  `season_id`         SMALLINT UNSIGNED NOT NULL,
  `acreage_planted`   DECIMAL(10,3) NOT NULL DEFAULT 0.000,
  `planting_date`     DATE NOT NULL,
  `seed_quantity`     DECIMAL(12,3) NULL,
  `seed_unit`         VARCHAR(20) NULL,
  `planting_method`   ENUM('row','broadcast','transplanting','seedling','other') NULL,
  `expected_harvest_date` DATE NULL,
  `expected_yield`    DECIMAL(14,3) NULL,
  `yield_unit`        VARCHAR(20) NULL,
  `growth_stage`      ENUM('germination','vegetative','flowering','fruiting','maturity','harvested','failed') NULL,
  `officer_id`        BIGINT UNSIGNED NULL,
  `schedule_generated` TINYINT(1) NOT NULL DEFAULT 0,
  `status`            ENUM('planned','planted','harvested','failed','terminated') NOT NULL DEFAULT 'planted',
  `notes`             TEXT NULL,
  `created_by`        BIGINT UNSIGNED NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `client_uuid`       CHAR(36) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_planting_code` (`planting_code`),
  UNIQUE KEY `uq_planting_client_uuid` (`client_uuid`),
  KEY `idx_planting_farmer` (`farmer_id`),
  KEY `idx_planting_garden` (`garden_id`),
  KEY `idx_planting_crop` (`crop_id`),
  KEY `idx_planting_season` (`season_id`),
  KEY `idx_planting_date` (`planting_date`),
  KEY `idx_planting_garden_season` (`garden_id`,`season_id`),
  CONSTRAINT `fk_planting_farmer`  FOREIGN KEY (`farmer_id`)   REFERENCES `farmers`(`id`)          ON DELETE CASCADE,
  CONSTRAINT `fk_planting_garden`  FOREIGN KEY (`garden_id`)   REFERENCES `gardens`(`id`)          ON DELETE CASCADE,
  CONSTRAINT `fk_planting_crop`    FOREIGN KEY (`crop_id`)     REFERENCES `crops`(`id`)            ON DELETE RESTRICT,
  CONSTRAINT `fk_planting_variety` FOREIGN KEY (`variety_id`)  REFERENCES `crop_varieties`(`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_planting_season`  FOREIGN KEY (`season_id`)   REFERENCES `seasons`(`id`)          ON DELETE RESTRICT,
  CONSTRAINT `fk_planting_officer` FOREIGN KEY (`officer_id`)  REFERENCES `field_officers`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_planting_user`    FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. CROP ACTIVITIES / TASKS
-- ---------------------------------------------------------------------

CREATE TABLE `crop_activities` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_code` VARCHAR(30)  NOT NULL,
  `planting_id`   BIGINT UNSIGNED NULL,
  `farmer_id`     BIGINT UNSIGNED NOT NULL,
  `garden_id`     BIGINT UNSIGNED NOT NULL,
  `crop_id`       SMALLINT UNSIGNED NOT NULL,
  `variety_id`    SMALLINT UNSIGNED NULL,
  `season_id`     SMALLINT UNSIGNED NOT NULL,
  `activity_type` VARCHAR(80)  NOT NULL,
  `description`   VARCHAR(255) NULL,
  `planned_date`  DATE NOT NULL,
  `due_date`      DATE NOT NULL,
  `assignee_type` ENUM('farmer','officer','both') NOT NULL DEFAULT 'farmer',
  `officer_id`    BIGINT UNSIGNED NULL,
  `status`        ENUM('pending','due','completed','overdue','cancelled') NOT NULL DEFAULT 'pending',
  `completed_at`  DATETIME NULL,
  `completed_by`  BIGINT UNSIGNED NULL,
  `completion_remarks` TEXT NULL,
  `evidence`      VARCHAR(255) NULL,
  `priority`      ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal',
  `reminder_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `client_uuid`   CHAR(36) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_activity_code` (`activity_code`),
  UNIQUE KEY `uq_activity_client_uuid` (`client_uuid`),
  KEY `idx_activity_due` (`status`,`due_date`),
  KEY `idx_activity_farmer` (`farmer_id`,`status`),
  KEY `idx_activity_garden` (`garden_id`),
  KEY `idx_activity_officer` (`officer_id`,`status`),
  KEY `idx_activity_season` (`season_id`),
  KEY `idx_activity_planting` (`planting_id`),
  KEY `idx_activity_planned` (`planned_date`),
  CONSTRAINT `fk_activity_planting` FOREIGN KEY (`planting_id`) REFERENCES `plantings`(`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_activity_farmer`   FOREIGN KEY (`farmer_id`)   REFERENCES `farmers`(`id`)          ON DELETE CASCADE,
  CONSTRAINT `fk_activity_garden`   FOREIGN KEY (`garden_id`)   REFERENCES `gardens`(`id`)          ON DELETE CASCADE,
  CONSTRAINT `fk_activity_crop`     FOREIGN KEY (`crop_id`)     REFERENCES `crops`(`id`)            ON DELETE RESTRICT,
  CONSTRAINT `fk_activity_variety`  FOREIGN KEY (`variety_id`)  REFERENCES `crop_varieties`(`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_activity_season`   FOREIGN KEY (`season_id`)   REFERENCES `seasons`(`id`)          ON DELETE RESTRICT,
  CONSTRAINT `fk_activity_officer`  FOREIGN KEY (`officer_id`)  REFERENCES `field_officers`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_activity_user`     FOREIGN KEY (`completed_by`) REFERENCES `users`(`id`)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `activity_completions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id`   BIGINT UNSIGNED NOT NULL,
  `completed_by`  BIGINT UNSIGNED NULL,
  `completed_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `latitude`      DECIMAL(10,7) NULL,
  `longitude`     DECIMAL(10,7) NULL,
  `remarks`       TEXT NULL,
  `photo`         VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ac_activity` (`activity_id`),
  CONSTRAINT `fk_ac_activity` FOREIGN KEY (`activity_id`) REFERENCES `crop_activities`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ac_user`     FOREIGN KEY (`completed_by`) REFERENCES `users`(`id`)             ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `activity_types` (
  `id`            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(80) NOT NULL,
  `code`          VARCHAR(50) NOT NULL,
  `category`      VARCHAR(60) NULL,
  `requires_photo` TINYINT(1) NOT NULL DEFAULT 0,
  `active`        TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_atype_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. INSPECTIONS, VISITS, SPRAYING, PROBLEMS
-- ---------------------------------------------------------------------

CREATE TABLE `field_visits` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visit_code`    VARCHAR(30)  NOT NULL,
  `officer_id`    BIGINT UNSIGNED NULL,
  `farmer_id`     BIGINT UNSIGNED NULL,
  `garden_id`     BIGINT UNSIGNED NULL,
  `group_id`      BIGINT UNSIGNED NULL,
  `visit_date`    DATE NOT NULL,
  `visit_purpose` VARCHAR(150) NULL,
  `latitude`      DECIMAL(10,7) NULL,
  `longitude`     DECIMAL(10,7) NULL,
  `people_met`    SMALLINT UNSIGNED NULL,
  `advisories_given` TEXT NULL,
  `status`        ENUM('planned','completed','cancelled','missed') NOT NULL DEFAULT 'completed',
  `created_by`    BIGINT UNSIGNED NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `client_uuid`   CHAR(36) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_visit_code` (`visit_code`),
  UNIQUE KEY `uq_visit_client_uuid` (`client_uuid`),
  KEY `idx_visit_officer` (`officer_id`,`visit_date`),
  KEY `idx_visit_farmer` (`farmer_id`),
  KEY `idx_visit_date` (`visit_date`),
  CONSTRAINT `fk_visit_officer` FOREIGN KEY (`officer_id`) REFERENCES `field_officers`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_visit_farmer`  FOREIGN KEY (`farmer_id`)  REFERENCES `farmers`(`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_visit_garden`  FOREIGN KEY (`garden_id`)  REFERENCES `gardens`(`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_visit_group`   FOREIGN KEY (`group_id`)   REFERENCES `farmer_groups`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_visit_user`    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inspections` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inspection_code` VARCHAR(30) NOT NULL,
  `visit_id`      BIGINT UNSIGNED NULL,
  `farmer_id`     BIGINT UNSIGNED NOT NULL,
  `garden_id`     BIGINT UNSIGNED NOT NULL,
  `planting_id`   BIGINT UNSIGNED NULL,
  `crop_id`       SMALLINT UNSIGNED NULL,
  `season_id`     SMALLINT UNSIGNED NULL,
  `officer_id`    BIGINT UNSIGNED NULL,
  `inspection_date` DATE NOT NULL,
  `latitude`      DECIMAL(10,7) NULL,
  `longitude`     DECIMAL(10,7) NULL,
  `crop_condition` ENUM('excellent','good','fair','poor','critical') NULL,
  `pest_status`   ENUM('none','low','moderate','high','severe') NULL,
  `disease_status` ENUM('none','low','moderate','high','severe') NULL,
  `weed_status`   ENUM('none','low','moderate','high','severe') NULL,
  `soil_condition` ENUM('excellent','good','fair','poor') NULL,
  `weather_condition` ENUM('sunny','cloudy','rainy','stormy','dry','foggy') NULL,
  `growth_stage`  VARCHAR(60) NULL,
  `field_coverage` DECIMAL(5,2) NULL,
  `estimated_yield_per_acre` DECIMAL(12,3) NULL,
  `recommendations` TEXT NULL,
  `remarks`       TEXT NULL,
  `status`        ENUM('draft','submitted','reviewed') NOT NULL DEFAULT 'submitted',
  `created_by`    BIGINT UNSIGNED NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `client_uuid`   CHAR(36) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inspection_code` (`inspection_code`),
  UNIQUE KEY `uq_inspection_client_uuid` (`client_uuid`),
  KEY `idx_inspection_farmer` (`farmer_id`,`inspection_date`),
  KEY `idx_inspection_garden` (`garden_id`),
  KEY `idx_inspection_officer` (`officer_id`),
  KEY `idx_inspection_date` (`inspection_date`),
  CONSTRAINT `fk_inspection_visit`   FOREIGN KEY (`visit_id`)    REFERENCES `field_visits`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inspection_farmer`  FOREIGN KEY (`farmer_id`)   REFERENCES `farmers`(`id`)         ON DELETE CASCADE,
  CONSTRAINT `fk_inspection_garden`  FOREIGN KEY (`garden_id`)   REFERENCES `gardens`(`id`)         ON DELETE CASCADE,
  CONSTRAINT `fk_inspection_planting` FOREIGN KEY (`planting_id`) REFERENCES `plantings`(`id`)      ON DELETE SET NULL,
  CONSTRAINT `fk_inspection_crop`    FOREIGN KEY (`crop_id`)     REFERENCES `crops`(`id`)           ON DELETE SET NULL,
  CONSTRAINT `fk_inspection_season`  FOREIGN KEY (`season_id`)   REFERENCES `seasons`(`id`)         ON DELETE SET NULL,
  CONSTRAINT `fk_inspection_officer` FOREIGN KEY (`officer_id`)  REFERENCES `field_officers`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inspection_user`    FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inspection_forms` (
  `id`            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(120) NOT NULL,
  `crop_id`       SMALLINT UNSIGNED NULL,
  `fields_json`   LONGTEXT NOT NULL COMMENT 'Dynamic field definition',
  `active`        TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_if_crop` (`crop_id`),
  CONSTRAINT `fk_if_crop` FOREIGN KEY (`crop_id`) REFERENCES `crops`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inspection_findings` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inspection_id` BIGINT UNSIGNED NOT NULL,
  `finding_type`  ENUM('pest','disease','weed','nutrient','water','other') NOT NULL,
  `name`          VARCHAR(150) NOT NULL,
  `severity`      ENUM('none','low','moderate','high','severe') NOT NULL DEFAULT 'low',
  `affected_area` DECIMAL(5,2) NULL,
  `notes`         TEXT NULL,
  `photo`         VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ifind_inspection` (`inspection_id`),
  CONSTRAINT `fk_ifind_inspection` FOREIGN KEY (`inspection_id`) REFERENCES `inspections`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `problem_types` (
  `id`            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(120) NOT NULL,
  `code`          VARCHAR(50) NOT NULL,
  `category`      ENUM('pest','disease','weather','input','soil','theft','other') NOT NULL DEFAULT 'other',
  `is_critical`   TINYINT(1) NOT NULL DEFAULT 0,
  `active`        TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ptype_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `problem_reports` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `problem_code`  VARCHAR(30)  NOT NULL,
  `problem_type_id` SMALLINT UNSIGNED NOT NULL,
  `farmer_id`     BIGINT UNSIGNED NOT NULL,
  `garden_id`     BIGINT UNSIGNED NULL,
  `planting_id`   BIGINT UNSIGNED NULL,
  `crop_id`       SMALLINT UNSIGNED NULL,
  `season_id`     SMALLINT UNSIGNED NULL,
  `region_id`     TINYINT UNSIGNED NULL,
  `district_id`   SMALLINT UNSIGNED NULL,
  `latitude`      DECIMAL(10,7) NULL,
  `longitude`     DECIMAL(10,7) NULL,
  `report_date`   DATE NOT NULL,
  `severity`      ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `description`   TEXT NOT NULL,
  `affected_area` DECIMAL(10,3) NULL,
  `reported_by`   BIGINT UNSIGNED NULL,
  `reported_by_type` ENUM('farmer','officer','manager') NOT NULL DEFAULT 'farmer',
  `assigned_officer_id` BIGINT UNSIGNED NULL,
  `action_taken`  TEXT NULL,
  `resolution`    TEXT NULL,
  `resolved_at`   DATETIME NULL,
  `resolved_by`   BIGINT UNSIGNED NULL,
  `status`        ENUM('reported','assigned','investigating','action_taken','resolved','closed') NOT NULL DEFAULT 'reported',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `client_uuid`   CHAR(36) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_problem_code` (`problem_code`),
  UNIQUE KEY `uq_problem_client_uuid` (`client_uuid`),
  KEY `idx_problem_status` (`status`,`severity`),
  KEY `idx_problem_farmer` (`farmer_id`),
  KEY `idx_problem_garden` (`garden_id`),
  KEY `idx_problem_officer` (`assigned_officer_id`,`status`),
  KEY `idx_problem_date` (`report_date`),
  KEY `idx_problem_geo` (`latitude`,`longitude`),
  CONSTRAINT `fk_problem_type`    FOREIGN KEY (`problem_type_id`) REFERENCES `problem_types`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_problem_farmer`  FOREIGN KEY (`farmer_id`)    REFERENCES `farmers`(`id`)          ON DELETE CASCADE,
  CONSTRAINT `fk_problem_garden`  FOREIGN KEY (`garden_id`)    REFERENCES `gardens`(`id`)          ON DELETE CASCADE,
  CONSTRAINT `fk_problem_planting` FOREIGN KEY (`planting_id`) REFERENCES `plantings`(`id`)       ON DELETE SET NULL,
  CONSTRAINT `fk_problem_crop`    FOREIGN KEY (`crop_id`)      REFERENCES `crops`(`id`)            ON DELETE SET NULL,
  CONSTRAINT `fk_problem_season`  FOREIGN KEY (`season_id`)    REFERENCES `seasons`(`id`)          ON DELETE SET NULL,
  CONSTRAINT `fk_problem_region`  FOREIGN KEY (`region_id`)    REFERENCES `regions`(`id`)         ON DELETE SET NULL,
  CONSTRAINT `fk_problem_district` FOREIGN KEY (`district_id`) REFERENCES `districts`(`id`)       ON DELETE SET NULL,
  CONSTRAINT `fk_problem_officer` FOREIGN KEY (`assigned_officer_id`) REFERENCES `field_officers`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_problem_reporter` FOREIGN KEY (`reported_by`) REFERENCES `users`(`id`)          ON DELETE SET NULL,
  CONSTRAINT `fk_problem_resolver` FOREIGN KEY (`resolved_by`) REFERENCES `users`(`id`)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `spraying_records` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `spray_code`    VARCHAR(30)  NOT NULL,
  `farmer_id`     BIGINT UNSIGNED NOT NULL,
  `garden_id`     BIGINT UNSIGNED NOT NULL,
  `crop_id`       SMALLINT UNSIGNED NULL,
  `variety_id`    SMALLINT UNSIGNED NULL,
  `planting_id`   BIGINT UNSIGNED NULL,
  `season_id`     SMALLINT UNSIGNED NULL,
  `input_id`      SMALLINT UNSIGNED NULL COMMENT 'Product used from inventory',
  `product_name`  VARCHAR(150) NOT NULL,
  `active_ingredient` VARCHAR(150) NULL,
  `quantity_used` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `unit`          VARCHAR(20) NOT NULL DEFAULT 'L',
  `dosage`        VARCHAR(120) NULL,
  `application_date` DATE NOT NULL,
  `next_application_date` DATE NULL,
  `pest_disease_targeted` VARCHAR(255) NULL,
  `application_method` ENUM('knapsack','tractor','manual','other') NULL,
  `applicator_name` VARCHAR(150) NULL,
  `officer_id`    BIGINT UNSIGNED NULL,
  `weather_conditions` VARCHAR(120) NULL,
  `remarks`       TEXT NULL,
  `created_by`    BIGINT UNSIGNED NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `client_uuid`   CHAR(36) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_spray_code` (`spray_code`),
  UNIQUE KEY `uq_spray_client_uuid` (`client_uuid`),
  KEY `idx_spray_farmer` (`farmer_id`),
  KEY `idx_spray_garden` (`garden_id`),
  KEY `idx_spray_date` (`application_date`),
  KEY `idx_spray_next` (`next_application_date`),
  CONSTRAINT `fk_spray_farmer`  FOREIGN KEY (`farmer_id`)   REFERENCES `farmers`(`id`)            ON DELETE CASCADE,
  CONSTRAINT `fk_spray_garden`  FOREIGN KEY (`garden_id`)   REFERENCES `gardens`(`id`)            ON DELETE CASCADE,
  CONSTRAINT `fk_spray_crop`    FOREIGN KEY (`crop_id`)     REFERENCES `crops`(`id`)              ON DELETE SET NULL,
  CONSTRAINT `fk_spray_variety` FOREIGN KEY (`variety_id`)  REFERENCES `crop_varieties`(`id`)    ON DELETE SET NULL,
  CONSTRAINT `fk_spray_planting` FOREIGN KEY (`planting_id`) REFERENCES `plantings`(`id`)         ON DELETE SET NULL,
  CONSTRAINT `fk_spray_season`  FOREIGN KEY (`season_id`)   REFERENCES `seasons`(`id`)            ON DELETE SET NULL,
  CONSTRAINT `fk_spray_officer` FOREIGN KEY (`officer_id`)  REFERENCES `field_officers`(`id`)     ON DELETE SET NULL,
  CONSTRAINT `fk_spray_user`    FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`)              ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `photos` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type`   VARCHAR(60) NOT NULL,
  `entity_id`     BIGINT UNSIGNED NOT NULL,
  `path`          VARCHAR(255) NOT NULL,
  `caption`       VARCHAR(255) NULL,
  `is_geo_tagged` TINYINT(1) NOT NULL DEFAULT 0,
  `latitude`      DECIMAL(10,7) NULL,
  `longitude`     DECIMAL(10,7) NULL,
  `uploaded_by`   BIGINT UNSIGNED NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_photo_entity` (`entity_type`,`entity_id`),
  CONSTRAINT `fk_photo_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `farmer_documents` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `farmer_id`     BIGINT UNSIGNED NOT NULL,
  `doc_type`      ENUM('national_id','registration','certificate','contract','consent','other') NOT NULL DEFAULT 'other',
  `title`         VARCHAR(150) NULL,
  `file_path`     VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NULL,
  `file_size`     INT UNSIGNED NULL,
  `mime_type`     VARCHAR(120) NULL,
  `expires_at`    DATE NULL,
  `uploaded_by`   BIGINT UNSIGNED NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fdoc_farmer` (`farmer_id`,`doc_type`),
  CONSTRAINT `fk_fdoc_farmer` FOREIGN KEY (`farmer_id`)   REFERENCES `farmers`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fdoc_user`   FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. INPUT / INVENTORY MANAGEMENT
-- ---------------------------------------------------------------------

CREATE TABLE `input_categories` (
  `id`            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(80) NOT NULL,
  `code`          VARCHAR(50) NOT NULL,
  `description`   VARCHAR(255) NULL,
  `active`        TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_icat_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inputs` (
  `id`                SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `input_code`        VARCHAR(30) NOT NULL,
  `category_id`       SMALLINT UNSIGNED NOT NULL,
  `name`              VARCHAR(150) NOT NULL,
  `description`       TEXT NULL,
  `unit`              VARCHAR(20) NOT NULL DEFAULT 'KG',
  `brand`             VARCHAR(100) NULL,
  `active_ingredient` VARCHAR(150) NULL,
  `reorder_level`     DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `current_stock`     DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `unit_cost`         DECIMAL(12,2) NULL,
  `crop_id`           SMALLINT UNSIGNED NULL COMMENT 'Crop specific input',
  `status`            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_input_code` (`input_code`),
  KEY `idx_input_category` (`category_id`),
  KEY `idx_input_status` (`status`),
  CONSTRAINT `fk_input_category` FOREIGN KEY (`category_id`) REFERENCES `input_categories`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_input_crop`     FOREIGN KEY (`crop_id`)     REFERENCES `crops`(`id`)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `input_transactions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_code` VARCHAR(30) NOT NULL,
  `input_id`      SMALLINT UNSIGNED NOT NULL,
  `transaction_type` ENUM('opening','receipt','issue','usage','adjustment','return','damage','expired') NOT NULL,
  `quantity`      DECIMAL(14,3) NOT NULL,
  `balance_after` DECIMAL(14,3) NOT NULL,
  `unit`          VARCHAR(20) NOT NULL DEFAULT 'KG',
  `supplier`      VARCHAR(150) NULL,
  `reference_no`  VARCHAR(60) NULL,
  `notes`         VARCHAR(255) NULL,
  `transaction_date` DATE NOT NULL,
  `created_by`    BIGINT UNSIGNED NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_input_txn_code` (`transaction_code`),
  KEY `idx_txn_input` (`input_id`,`transaction_date`),
  KEY `idx_txn_type` (`transaction_type`),
  CONSTRAINT `fk_txn_input` FOREIGN KEY (`input_id`)   REFERENCES `inputs`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_txn_user`  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `input_issues` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `issue_code`    VARCHAR(30) NOT NULL,
  `input_id`      SMALLINT UNSIGNED NOT NULL,
  `farmer_id`     BIGINT UNSIGNED NULL,
  `garden_id`     BIGINT UNSIGNED NULL,
  `crop_id`       SMALLINT UNSIGNED NULL,
  `season_id`     SMALLINT UNSIGNED NULL,
  `quantity`      DECIMAL(14,3) NOT NULL,
  `quantity_used` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `unit`          VARCHAR(20) NOT NULL DEFAULT 'KG',
  `issue_date`    DATE NOT NULL,
  `issued_by`     BIGINT UNSIGNED NULL,
  `officer_id`    BIGINT UNSIGNED NULL,
  `reference_no`  VARCHAR(60) NULL,
  `purpose`       VARCHAR(255) NULL,
  `status`        ENUM('issued','partially_used','fully_used','returned','cancelled') NOT NULL DEFAULT 'issued',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `client_uuid`   CHAR(36) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_issue_code` (`issue_code`),
  UNIQUE KEY `uq_issue_client_uuid` (`client_uuid`),
  KEY `idx_issue_input` (`input_id`),
  KEY `idx_issue_farmer` (`farmer_id`),
  KEY `idx_issue_date` (`issue_date`),
  CONSTRAINT `fk_issue_input`   FOREIGN KEY (`input_id`)   REFERENCES `inputs`(`id`)           ON DELETE RESTRICT,
  CONSTRAINT `fk_issue_farmer`  FOREIGN KEY (`farmer_id`)  REFERENCES `farmers`(`id`)          ON DELETE CASCADE,
  CONSTRAINT `fk_issue_garden`  FOREIGN KEY (`garden_id`)  REFERENCES `gardens`(`id`)          ON DELETE CASCADE,
  CONSTRAINT `fk_issue_crop`    FOREIGN KEY (`crop_id`)    REFERENCES `crops`(`id`)            ON DELETE SET NULL,
  CONSTRAINT `fk_issue_season`  FOREIGN KEY (`season_id`)  REFERENCES `seasons`(`id`)          ON DELETE SET NULL,
  CONSTRAINT `fk_issue_user`    FOREIGN KEY (`issued_by`)  REFERENCES `users`(`id`)            ON DELETE SET NULL,
  CONSTRAINT `fk_issue_officer` FOREIGN KEY (`officer_id`) REFERENCES `field_officers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `input_usage` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usage_code`    VARCHAR(30) NOT NULL,
  `issue_id`      BIGINT UNSIGNED NULL,
  `input_id`      SMALLINT UNSIGNED NOT NULL,
  `farmer_id`     BIGINT UNSIGNED NOT NULL,
  `garden_id`     BIGINT UNSIGNED NULL,
  `crop_id`       SMALLINT UNSIGNED NULL,
  `season_id`     SMALLINT UNSIGNED NULL,
  `activity_id`   BIGINT UNSIGNED NULL,
  `quantity_used` DECIMAL(14,3) NOT NULL,
  `unit`          VARCHAR(20) NOT NULL DEFAULT 'KG',
  `application_date` DATE NOT NULL,
  `purpose`       VARCHAR(255) NULL,
  `recorded_by`   BIGINT UNSIGNED NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `client_uuid`   CHAR(36) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usage_code` (`usage_code`),
  UNIQUE KEY `uq_usage_client_uuid` (`client_uuid`),
  KEY `idx_usage_farmer` (`farmer_id`),
  KEY `idx_usage_issue` (`issue_id`),
  KEY `idx_usage_date` (`application_date`),
  CONSTRAINT `fk_usage_issue`    FOREIGN KEY (`issue_id`)    REFERENCES `input_issues`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_usage_input`    FOREIGN KEY (`input_id`)    REFERENCES `inputs`(`id`)         ON DELETE RESTRICT,
  CONSTRAINT `fk_usage_farmer`   FOREIGN KEY (`farmer_id`)   REFERENCES `farmers`(`id`)         ON DELETE CASCADE,
  CONSTRAINT `fk_usage_garden`   FOREIGN KEY (`garden_id`)   REFERENCES `gardens`(`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_usage_crop`     FOREIGN KEY (`crop_id`)     REFERENCES `crops`(`id`)          ON DELETE SET NULL,
  CONSTRAINT `fk_usage_season`   FOREIGN KEY (`season_id`)   REFERENCES `seasons`(`id`)        ON DELETE SET NULL,
  CONSTRAINT `fk_usage_activity` FOREIGN KEY (`activity_id`) REFERENCES `crop_activities`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_usage_user`     FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. HARVEST / PRODUCTION
-- ---------------------------------------------------------------------

CREATE TABLE `harvest_forecasts` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `planting_id`   BIGINT UNSIGNED NOT NULL,
  `farmer_id`     BIGINT UNSIGNED NOT NULL,
  `garden_id`     BIGINT UNSIGNED NOT NULL,
  `crop_id`       SMALLINT UNSIGNED NOT NULL,
  `variety_id`    SMALLINT UNSIGNED NULL,
  `season_id`     SMALLINT UNSIGNED NOT NULL,
  `forecast_date` DATE NOT NULL,
  `acreage`       DECIMAL(10,3) NOT NULL,
  `expected_yield_per_acre` DECIMAL(12,3) NOT NULL,
  `expected_production` DECIMAL(14,3) NOT NULL,
  `revised_yield_per_acre` DECIMAL(12,3) NULL,
  `revised_production` DECIMAL(14,3) NULL,
  `unit`          VARCHAR(20) NULL,
  `revision_reason` VARCHAR(255) NULL,
  `confidence`    DECIMAL(5,2) NULL,
  `actual_production` DECIMAL(14,3) NULL,
  `variance`      DECIMAL(14,3) NULL,
  `variance_pct`  DECIMAL(7,2) NULL,
  `generated_by`  ENUM('system','manual') NOT NULL DEFAULT 'system',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_forecast_planting` (`planting_id`),
  KEY `idx_forecast_farmer` (`farmer_id`),
  KEY `idx_forecast_season` (`season_id`),
  KEY `idx_forecast_crop` (`crop_id`),
  CONSTRAINT `fk_forecast_planting` FOREIGN KEY (`planting_id`) REFERENCES `plantings`(`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_forecast_farmer`   FOREIGN KEY (`farmer_id`)   REFERENCES `farmers`(`id`)         ON DELETE CASCADE,
  CONSTRAINT `fk_forecast_garden`   FOREIGN KEY (`garden_id`)   REFERENCES `gardens`(`id`)         ON DELETE CASCADE,
  CONSTRAINT `fk_forecast_crop`     FOREIGN KEY (`crop_id`)     REFERENCES `crops`(`id`)           ON DELETE CASCADE,
  CONSTRAINT `fk_forecast_variety`  FOREIGN KEY (`variety_id`)  REFERENCES `crop_varieties`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_forecast_season`   FOREIGN KEY (`season_id`)   REFERENCES `seasons`(`id`)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `harvest_records` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `harvest_code`  VARCHAR(30) NOT NULL,
  `planting_id`   BIGINT UNSIGNED NULL,
  `farmer_id`     BIGINT UNSIGNED NOT NULL,
  `garden_id`     BIGINT UNSIGNED NOT NULL,
  `crop_id`       SMALLINT UNSIGNED NOT NULL,
  `variety_id`    SMALLINT UNSIGNED NULL,
  `season_id`     SMALLINT UNSIGNED NOT NULL,
  `harvest_date`  DATE NOT NULL,
  `acreage_harvested` DECIMAL(10,3) NOT NULL DEFAULT 0.000,
  `quantity_harvested` DECIMAL(14,3) NOT NULL,
  `unit`          VARCHAR(20) NOT NULL,
  `quality`       ENUM('premium','grade1','grade2','grade3','reject') NULL,
  `grade`         VARCHAR(40) NULL,
  `moisture_content` DECIMAL(5,2) NULL,
  `losses`        DECIMAL(14,3) NULL,
  `loss_unit`     VARCHAR(20) NULL,
  `storage_location` VARCHAR(150) NULL,
  `officer_id`    BIGINT UNSIGNED NULL,
  `status`        ENUM('recorded','aggregated','sold','cancelled') NOT NULL DEFAULT 'recorded',
  `notes`         TEXT NULL,
  `created_by`    BIGINT UNSIGNED NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `client_uuid`   CHAR(36) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_harvest_code` (`harvest_code`),
  UNIQUE KEY `uq_harvest_client_uuid` (`client_uuid`),
  KEY `idx_harvest_farmer` (`farmer_id`,`harvest_date`),
  KEY `idx_harvest_garden` (`garden_id`),
  KEY `idx_harvest_crop_season` (`crop_id`,`season_id`),
  KEY `idx_harvest_date` (`harvest_date`),
  KEY `idx_harvest_status` (`status`),
  CONSTRAINT `fk_harvest_planting` FOREIGN KEY (`planting_id`) REFERENCES `plantings`(`id`)       ON DELETE SET NULL,
  CONSTRAINT `fk_harvest_farmer`   FOREIGN KEY (`farmer_id`)   REFERENCES `farmers`(`id`)          ON DELETE CASCADE,
  CONSTRAINT `fk_harvest_garden`   FOREIGN KEY (`garden_id`)   REFERENCES `gardens`(`id`)          ON DELETE CASCADE,
  CONSTRAINT `fk_harvest_crop`     FOREIGN KEY (`crop_id`)     REFERENCES `crops`(`id`)            ON DELETE RESTRICT,
  CONSTRAINT `fk_harvest_variety`  FOREIGN KEY (`variety_id`)  REFERENCES `crop_varieties`(`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_harvest_season`   FOREIGN KEY (`season_id`)   REFERENCES `seasons`(`id`)          ON DELETE RESTRICT,
  CONSTRAINT `fk_harvest_officer`  FOREIGN KEY (`officer_id`)  REFERENCES `field_officers`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_harvest_user`     FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `harvest_batches` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_code`    VARCHAR(30) NOT NULL,
  `crop_id`       SMALLINT UNSIGNED NOT NULL,
  `variety_id`    SMALLINT UNSIGNED NULL,
  `season_id`     SMALLINT UNSIGNED NOT NULL,
  `batch_date`    DATE NOT NULL,
  `total_quantity` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  `unit`          VARCHAR(20) NOT NULL,
  `grade`         VARCHAR(40) NULL,
  `quality`       VARCHAR(40) NULL,
  `storage_location` VARCHAR(150) NULL,
  `contributor_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `status`        ENUM('open','aggregated','allocated','sold','exported','closed') NOT NULL DEFAULT 'open',
  `notes`         TEXT NULL,
  `created_by`    BIGINT UNSIGNED NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_batch_code` (`batch_code`),
  KEY `idx_batch_crop_season` (`crop_id`,`season_id`),
  KEY `idx_batch_date` (`batch_date`),
  KEY `idx_batch_status` (`status`),
  CONSTRAINT `fk_batch_crop`    FOREIGN KEY (`crop_id`)    REFERENCES `crops`(`id`)           ON DELETE RESTRICT,
  CONSTRAINT `fk_batch_variety` FOREIGN KEY (`variety_id`) REFERENCES `crop_varieties`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_batch_season`  FOREIGN KEY (`season_id`)  REFERENCES `seasons`(`id`)         ON DELETE RESTRICT,
  CONSTRAINT `fk_batch_user`    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `harvest_batch_items` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id`      BIGINT UNSIGNED NOT NULL,
  `harvest_id`    BIGINT UNSIGNED NOT NULL,
  `quantity`      DECIMAL(14,3) NOT NULL,
  `unit`          VARCHAR(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hbi_harvest` (`harvest_id`),
  KEY `idx_hbi_batch` (`batch_id`),
  CONSTRAINT `fk_hbi_batch`   FOREIGN KEY (`batch_id`)   REFERENCES `harvest_batches`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hbi_harvest` FOREIGN KEY (`harvest_id`) REFERENCES `harvest_records`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. MARKET / SALES / TRACEABILITY
-- ---------------------------------------------------------------------

CREATE TABLE `buyers` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `buyer_code`    VARCHAR(30)  NOT NULL,
  `name`          VARCHAR(150) NOT NULL,
  `buyer_type`    ENUM('local','regional','processor','exporter','wholesaler','retailer','cooperative','institutional') NOT NULL DEFAULT 'local',
  `country`       VARCHAR(80)  NULL,
  `region_id`     TINYINT UNSIGNED NULL,
  `district_id`   SMALLINT UNSIGNED NULL,
  `address`       VARCHAR(255) NULL,
  `contact_person` VARCHAR(150) NULL,
  `phone`         VARCHAR(20)  NULL,
  `email`         VARCHAR(150) NULL,
  `quality_requirements` VARCHAR(255) NULL,
  `default_price` DECIMAL(12,2) NULL,
  `price_unit`    VARCHAR(20) NULL,
  `contract_ref`  VARCHAR(80)  NULL,
  `status`        ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `notes`         TEXT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_buyer_code` (`buyer_code`),
  KEY `idx_buyer_status` (`status`),
  KEY `idx_buyer_name` (`name`),
  CONSTRAINT `fk_buyer_region`  FOREIGN KEY (`region_id`)  REFERENCES `regions`(`id`)   ON DELETE SET NULL,
  CONSTRAINT `fk_buyer_district` FOREIGN KEY (`district_id`) REFERENCES `districts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `markets` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `market_code`   VARCHAR(30)  NOT NULL,
  `name`          VARCHAR(150) NOT NULL,
  `market_type`   ENUM('local','regional','national','export') NOT NULL DEFAULT 'local',
  `region_id`     TINYINT UNSIGNED NULL,
  `district_id`   SMALLINT UNSIGNED NULL,
  `address`       VARCHAR(255) NULL,
  `contact_person` VARCHAR(150) NULL,
  `phone`         VARCHAR(20)  NULL,
  `capacity_notes` TEXT NULL,
  `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_market_code` (`market_code`),
  CONSTRAINT `fk_market_region`  FOREIGN KEY (`region_id`)  REFERENCES `regions`(`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_market_district` FOREIGN KEY (`district_id`) REFERENCES `districts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `market_requirements` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `requirement_code` VARCHAR(30) NOT NULL,
  `buyer_id`      BIGINT UNSIGNED NOT NULL,
  `market_id`     BIGINT UNSIGNED NULL,
  `crop_id`       SMALLINT UNSIGNED NOT NULL,
  `variety_id`    SMALLINT UNSIGNED NULL,
  `quantity_required` DECIMAL(14,3) NOT NULL,
  `unit`          VARCHAR(20) NOT NULL DEFAULT 'KG',
  `quality_required` VARCHAR(120) NULL,
  `delivery_location` VARCHAR(180) NULL,
  `required_date` DATE NOT NULL,
  `offered_price` DECIMAL(12,2) NULL,
  `price_unit`    VARCHAR(20) NULL,
  `contract_terms` TEXT NULL,
  `status`        ENUM('open','partially_filled','fulfilled','cancelled','expired') NOT NULL DEFAULT 'open',
  `created_by`    BIGINT UNSIGNED NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_req_code` (`requirement_code`),
  KEY `idx_req_crop` (`crop_id`,`status`),
  KEY `idx_req_buyer` (`buyer_id`),
  KEY `idx_req_date` (`required_date`),
  CONSTRAINT `fk_req_buyer`  FOREIGN KEY (`buyer_id`)  REFERENCES `buyers`(`id`)           ON DELETE CASCADE,
  CONSTRAINT `fk_req_market` FOREIGN KEY (`market_id`) REFERENCES `markets`(`id`)          ON DELETE SET NULL,
  CONSTRAINT `fk_req_crop`   FOREIGN KEY (`crop_id`)   REFERENCES `crops`(`id`)            ON DELETE CASCADE,
  CONSTRAINT `fk_req_variety` FOREIGN KEY (`variety_id`) REFERENCES `crop_varieties`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_req_user`   FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sales` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_code`     VARCHAR(30) NOT NULL,
  `buyer_id`      BIGINT UNSIGNED NOT NULL,
  `market_id`     BIGINT UNSIGNED NULL,
  `sale_date`     DATE NOT NULL,
  `total_value`   DECIMAL(16,2) NOT NULL DEFAULT 0.00,
  `currency`      VARCHAR(10) NOT NULL DEFAULT 'UGX',
  `payment_status` ENUM('unpaid','partial','paid','overdue') NOT NULL DEFAULT 'unpaid',
  `amount_paid`   DECIMAL(16,2) NOT NULL DEFAULT 0.00,
  `payment_due_date` DATE NULL,
  `status`        ENUM('draft','confirmed','fulfilled','cancelled') NOT NULL DEFAULT 'confirmed',
  `notes`         TEXT NULL,
  `created_by`    BIGINT UNSIGNED NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sale_code` (`sale_code`),
  KEY `idx_sale_buyer` (`buyer_id`),
  KEY `idx_sale_date` (`sale_date`),
  KEY `idx_sale_payment` (`payment_status`),
  CONSTRAINT `fk_sale_buyer`  FOREIGN KEY (`buyer_id`)  REFERENCES `buyers`(`id`)  ON DELETE RESTRICT,
  CONSTRAINT `fk_sale_market` FOREIGN KEY (`market_id`) REFERENCES `markets`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sale_user`   FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sale_items` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id`       BIGINT UNSIGNED NOT NULL,
  `batch_id`      BIGINT UNSIGNED NULL,
  `crop_id`       SMALLINT UNSIGNED NOT NULL,
  `variety_id`    SMALLINT UNSIGNED NULL,
  `quantity`      DECIMAL(14,3) NOT NULL,
  `unit`          VARCHAR(20) NOT NULL,
  `unit_price`    DECIMAL(12,2) NOT NULL,
  `total_value`   DECIMAL(16,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_si_sale` (`sale_id`),
  KEY `idx_si_batch` (`batch_id`),
  KEY `idx_si_crop` (`crop_id`),
  CONSTRAINT `fk_si_sale`   FOREIGN KEY (`sale_id`)  REFERENCES `sales`(`id`)          ON DELETE CASCADE,
  CONSTRAINT `fk_si_batch`  FOREIGN KEY (`batch_id`) REFERENCES `harvest_batches`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_si_crop`   FOREIGN KEY (`crop_id`)  REFERENCES `crops`(`id`)           ON DELETE RESTRICT,
  CONSTRAINT `fk_si_variety` FOREIGN KEY (`variety_id`) REFERENCES `crop_varieties`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `deliveries` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `delivery_code` VARCHAR(30) NOT NULL,
  `sale_id`       BIGINT UNSIGNED NOT NULL,
  `dispatch_date` DATE NULL,
  `expected_date` DATE NULL,
  `delivered_date` DATE NULL,
  `delivery_location` VARCHAR(180) NULL,
  `status`        ENUM('pending','in_transit','delivered','partially_delivered','cancelled') NOT NULL DEFAULT 'pending',
  `transporter_name` VARCHAR(150) NULL,
  `vehicle_number` VARCHAR(40) NULL,
  `tracking_number` VARCHAR(80) NULL,
  `notes`         TEXT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_delivery_code` (`delivery_code`),
  KEY `idx_delivery_sale` (`sale_id`),
  KEY `idx_delivery_status` (`status`),
  CONSTRAINT `fk_delivery_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `exports` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `export_code`   VARCHAR(30) NOT NULL,
  `sale_id`       BIGINT UNSIGNED NULL,
  `batch_id`      BIGINT UNSIGNED NULL,
  `buyer_id`      BIGINT UNSIGNED NULL,
  `destination_country` VARCHAR(80) NOT NULL,
  `quantity`      DECIMAL(14,3) NULL,
  `unit`          VARCHAR(20) NULL,
  `shipment_date` DATE NULL,
  `eta`           DATE NULL,
  `certificate_no` VARCHAR(80) NULL,
  `quality_certificate_no` VARCHAR(80) NULL,
  `phytosanitary_no` VARCHAR(80) NULL,
  `status`        ENUM('draft','in_transit','arrived','delivered','cancelled') NOT NULL DEFAULT 'draft',
  `notes`         TEXT NULL,
  `created_by`    BIGINT UNSIGNED NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_export_code` (`export_code`),
  KEY `idx_export_batch` (`batch_id`),
  KEY `idx_export_buyer` (`buyer_id`),
  CONSTRAINT `fk_export_sale`  FOREIGN KEY (`sale_id`)  REFERENCES `sales`(`id`)          ON DELETE SET NULL,
  CONSTRAINT `fk_export_batch` FOREIGN KEY (`batch_id`) REFERENCES `harvest_batches`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_export_buyer` FOREIGN KEY (`buyer_id`) REFERENCES `buyers`(`id`)          ON DELETE SET NULL,
  CONSTRAINT `fk_export_user`  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. KNOWLEDGE CENTRE
-- ---------------------------------------------------------------------

CREATE TABLE `knowledge_categories` (
  `id`            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(100) NOT NULL,
  `slug`          VARCHAR(100) NOT NULL,
  `parent_id`     SMALLINT UNSIGNED NULL,
  `active`        TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kcat_slug` (`slug`),
  CONSTRAINT `fk_kcat_parent` FOREIGN KEY (`parent_id`) REFERENCES `knowledge_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `knowledge_content` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id`   SMALLINT UNSIGNED NULL,
  `title`         VARCHAR(200) NOT NULL,
  `slug`          VARCHAR(220) NOT NULL,
  `content_type`  ENUM('article','guide','pdf','video','image','calendar','pest_guide','disease_guide','fertilizer_guide','spraying_guide','gap') NOT NULL DEFAULT 'article',
  `summary`       VARCHAR(500) NULL,
  `body`          LONGTEXT NULL,
  `file_path`     VARCHAR(255) NULL,
  `external_url`  VARCHAR(500) NULL,
  `crop_id`       SMALLINT UNSIGNED NULL,
  `variety_id`    SMALLINT UNSIGNED NULL,
  `farming_stage` VARCHAR(80) NULL,
  `problem_type_id` SMALLINT UNSIGNED NULL,
  `language`      VARCHAR(10) NOT NULL DEFAULT 'en',
  `is_published`  TINYINT(1) NOT NULL DEFAULT 1,
  `view_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `published_at`  DATETIME NULL,
  `author_id`     BIGINT UNSIGNED NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kcontent_slug` (`slug`),
  KEY `idx_kcontent_category` (`category_id`),
  KEY `idx_kcontent_crop` (`crop_id`),
  KEY `idx_kcontent_stage` (`farming_stage`),
  KEY `idx_kcontent_published` (`is_published`),
  CONSTRAINT `fk_kcontent_category` FOREIGN KEY (`category_id`) REFERENCES `knowledge_categories`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_kcontent_crop`     FOREIGN KEY (`crop_id`)     REFERENCES `crops`(`id`)             ON DELETE SET NULL,
  CONSTRAINT `fk_kcontent_variety`  FOREIGN KEY (`variety_id`)  REFERENCES `crop_varieties`(`id`)    ON DELETE SET NULL,
  CONSTRAINT `fk_kcontent_problem`  FOREIGN KEY (`problem_type_id`) REFERENCES `problem_types`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_kcontent_author`   FOREIGN KEY (`author_id`)   REFERENCES `users`(`id`)              ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. NOTIFICATIONS / COMMUNICATION
-- ---------------------------------------------------------------------

CREATE TABLE `notifications` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       BIGINT UNSIGNED NULL,
  `role_id`       SMALLINT UNSIGNED NULL COMMENT 'Broadcast to a role when user_id is NULL',
  `farmer_id`     BIGINT UNSIGNED NULL,
  `officer_id`    BIGINT UNSIGNED NULL,
  `type`          VARCHAR(50) NOT NULL,
  `title`         VARCHAR(200) NOT NULL,
  `message`       TEXT NOT NULL,
  `link`          VARCHAR(255) NULL,
  `entity_type`   VARCHAR(60) NULL,
  `entity_id`     BIGINT UNSIGNED NULL,
  `is_read`       TINYINT(1) NOT NULL DEFAULT 0,
  `read_at`       DATETIME NULL,
  `channel`       ENUM('system','sms','email','push') NOT NULL DEFAULT 'system',
  `dedupe_key`    VARCHAR(120) NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`,`is_read`,`created_at`),
  KEY `idx_notif_farmer` (`farmer_id`,`is_read`),
  KEY `idx_notif_officer` (`officer_id`,`is_read`),
  KEY `idx_notif_type` (`type`),
  KEY `idx_notif_dedupe` (`dedupe_key`),
  CONSTRAINT `fk_notif_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)           ON DELETE CASCADE,
  CONSTRAINT `fk_notif_role`    FOREIGN KEY (`role_id`)    REFERENCES `roles`(`id`)           ON DELETE CASCADE,
  CONSTRAINT `fk_notif_farmer`  FOREIGN KEY (`farmer_id`)  REFERENCES `farmers`(`id`)         ON DELETE CASCADE,
  CONSTRAINT `fk_notif_officer` FOREIGN KEY (`officer_id`) REFERENCES `field_officers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sms_logs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone`         VARCHAR(20) NOT NULL,
  `message`       TEXT NOT NULL,
  `provider`      VARCHAR(50) NULL,
  `provider_message_id` VARCHAR(120) NULL,
  `recipient_type` ENUM('user','farmer','officer','other') NOT NULL DEFAULT 'other',
  `recipient_id`  BIGINT UNSIGNED NULL,
  `status`        ENUM('queued','sent','delivered','failed') NOT NULL DEFAULT 'queued',
  `error_message` VARCHAR(255) NULL,
  `related_type`  VARCHAR(60) NULL,
  `related_id`    BIGINT UNSIGNED NULL,
  `sent_at`       DATETIME NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sms_phone` (`phone`),
  KEY `idx_sms_status` (`status`,`created_at`),
  CONSTRAINT `fk_sms_user` FOREIGN KEY (`recipient_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. SYSTEM / AUDIT
-- ---------------------------------------------------------------------

CREATE TABLE `system_settings` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key`   VARCHAR(80) NOT NULL,
  `setting_value` TEXT NULL,
  `setting_group` VARCHAR(60) NOT NULL DEFAULT 'general',
  `description`   VARCHAR(255) NULL,
  `is_secret`     TINYINT(1) NOT NULL DEFAULT 0,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_logs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       BIGINT UNSIGNED NULL,
  `user_name`     VARCHAR(150) NULL,
  `action`        VARCHAR(60) NOT NULL,
  `module`        VARCHAR(60) NOT NULL,
  `entity_type`   VARCHAR(60) NULL,
  `entity_id`     BIGINT UNSIGNED NULL,
  `entity_label`  VARCHAR(150) NULL,
  `old_values`    TEXT NULL,
  `new_values`    TEXT NULL,
  `ip_address`    VARCHAR(45) NULL,
  `user_agent`    VARCHAR(255) NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_module` (`module`,`created_at`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_action` (`action`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- MOBILE ATTACHMENTS
-- Photo evidence captured by the field app. Kept separate from
-- farmer_documents, which is scoped to a single farmer and constrained to
-- identity documents; an inspection, a spray record or a problem report all
-- need a picture too, and they belong to different parents.
--
-- parent_id is deliberately a loose integer with no foreign key: the parent
-- may be created by the very same offline sync that uploads the photo, in
-- which case there is nothing yet for the key to point at. parent_type plus
-- the natural code recorded alongside it is what makes the row findable.
-- ---------------------------------------------------------------------

CREATE TABLE `attachments` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`          CHAR(36)     NOT NULL,
  `client_uuid`   CHAR(36)     NOT NULL COMMENT 'Client-generated id, so a replayed upload is not duplicated',
  `parent_type`   ENUM('farmer','garden','inspection','problem','activity','spraying','harvest') NOT NULL,
  `parent_id`     BIGINT UNSIGNED NULL,
  `parent_code`   VARCHAR(30)  NULL,
  `file_path`     VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NULL,
  `mime_type`     VARCHAR(120) NULL,
  `file_size`     INT UNSIGNED  NULL,
  `caption`       VARCHAR(255) NULL,
  `latitude`      DECIMAL(10,7) NULL,
  `longitude`     DECIMAL(10,7) NULL,
  `captured_at`   DATETIME     NULL,
  `uploaded_by`   BIGINT UNSIGNED NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attachment_client_uuid` (`client_uuid`),
  KEY `idx_attachment_parent` (`parent_type`,`parent_id`),
  KEY `idx_attachment_code` (`parent_code`),
  CONSTRAINT `fk_attachment_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `id_counters` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity`        VARCHAR(40) NOT NULL,
  `period`        VARCHAR(10) NOT NULL DEFAULT '',
  `current_value` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_counter` (`entity`,`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- DEFERRED CONSTRAINTS
-- spraying_records.input_id references inputs, which is defined later in
-- this script. Added here so the dependency is explicit.
-- ---------------------------------------------------------------------

ALTER TABLE `spraying_records`
  ADD CONSTRAINT `fk_spray_input` FOREIGN KEY (`input_id`) REFERENCES `inputs`(`id`) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;
