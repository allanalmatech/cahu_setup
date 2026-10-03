-- =====================================================================
--  COOPERATIVE AGRIBUSINESS HUB UGANDA (CAHU)
--  Reference and demo data.
--
--  Apply after schema.sql:
--      mysql -u <user> -p <database> < database/seed.sql
--
--  WHAT IS SAFE TO KEEP IN PRODUCTION
--  -----------------------------------
--  Sections 1 through 8 are reference and configuration data: permissions,
--  roles, system settings, the Ugandan administrative hierarchy, knowledge
--  categories, crops, varieties, agronomic templates, seasons, input
--  categories, inputs, activity types and problem types. None of it refers
--  to a real person, a real farm or a real transaction, so all of it belongs
--  in a production installation.
--
--  WHAT IS DEMO ONLY - DELETE BEFORE GOING LIVE
--  --------------------------------------------
--  Sections 9 onwards are demo data: the seeded user accounts and their
--  known passwords, the field officers, farmer groups, farmers, gardens,
--  plantings, activities, inspections, visits, sprays, problem reports,
--  input issues and usage, harvests, batches, buyers, markets, sales,
--  deliveries, exports, notifications, API tokens and audit log rows.
--  Shipping these would put eight invented farmers and four sample
--  accounts in front of real users. Run this file, verify the application,
--  then delete the demo rows, or run only sections 1-8.
--
--  RE-RUNNABILITY
--  --------------
--  Every INSERT is idempotent: tables with a UNIQUE key use
--  INSERT ... ON DUPLICATE KEY UPDATE, join tables and the location tables
--  below district level use INSERT ... SELECT ... WHERE NOT EXISTS, and
--  role mappings use INSERT IGNORE ... SELECT. Running this file twice
--  changes nothing the second time, so there is never a reason to drop and
--  recreate the database.
--
--  TRANSACTIONS
--  ------------
--  The whole file runs inside one transaction. To take manual control of
--  when it is flushed, uncomment the two autocommit lines below and the
--  matching COMMIT in the footer: with autocommit off you can inspect
--  intermediate state and issue a ROLLBACK to abandon the whole load.
--  Note that MySQL implicitly commits around DDL, so a failure inside
--  this file may leave earlier sections behind - the file is safe to
--  simply run again.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';
SET time_zone = '+03:00';

-- SET autocommit = 0;   -- uncomment for manual commit control

START TRANSACTION;

-- ---------------------------------------------------------------------
-- 1. PERMISSIONS
--    Every code enforced by the route middleware in app/routes.php.
--    If a code is missing here the permission check denies it, so this
--    list is generated from that file and must stay in step with it.
-- ---------------------------------------------------------------------

INSERT INTO `permissions` (`module`, `name`, `code`, `description`) VALUES
  ('farmers',    'View farmers',     'farmers.view',    'See the farmer register, farmer records and farmer exports.'),
  ('farmers',    'Register farmers', 'farmers.create',  'Add new farmers to the register.'),
  ('farmers',    'Edit farmers',     'farmers.edit',    'Amend farmer details, groups and documents.'),
  ('farmers',    'Remove farmers',   'farmers.delete',  'Deactivate or remove a farmer from the register.'),
  ('groups',     'View groups',      'groups.view',     'See producer groups, cooperatives and their membership.'),
  ('groups',     'Manage groups',    'groups.manage',   'Create, edit and dissolve producer groups.'),
  ('officers',   'View officers',    'officers.view',   'See the field officer register and officer portfolios.'),
  ('officers',   'Manage officers',  'officers.manage', 'Create, edit and deactivate field officers.'),
  ('gardens',    'View gardens',     'gardens.view',    'See gardens, their boundaries and their history.'),
  ('gardens',    'Manage gardens',   'gardens.manage',  'Register gardens, capture GPS and edit plot details.'),
  ('map',        'View farm map',    'map.view',        'Open the GIS map and its data feed.'),
  ('crops',      'View crops',       'crops.view',      'See crops, varieties and seasons.'),
  ('crops',      'Manage crops',     'crops.manage',    'Maintain crops, varieties and the crop lifecycle schedule.'),
  ('seasons',    'View seasons',     'seasons.view',    'See the agricultural season calendar.'),
  ('seasons',    'Manage seasons',   'seasons.manage',  'Open, close and maintain seasons.'),
  ('plantings',  'View plantings',   'plantings.view',  'See plantings and their generated activity schedules.'),
  ('plantings',  'Manage plantings', 'plantings.manage','Record, edit and terminate plantings.'),
  ('activities', 'View activities',  'activities.view', 'See the activity register, calendar and mark work complete.'),
  ('activities', 'Manage activities','activities.manage','Create, edit and cancel crop activities.'),
  ('visits',     'View visits',      'visits.view',     'See field visits recorded by officers.'),
  ('visits',     'Manage visits',    'visits.manage',   'Plan and record field visits.'),
  ('inspections','View inspections', 'inspections.view','See crop inspections and the findings raised.'),
  ('inspections','Manage inspections','inspections.manage','Record, edit and review crop inspections.'),
  ('spraying',   'View spraying',    'spraying.view',   'See the spraying register.'),
  ('spraying',   'Manage spraying',  'spraying.manage', 'Record and edit spray applications.'),
  ('problems',   'View problems',    'problems.view',   'See reported field problems and their resolution.'),
  ('problems',   'Manage problems',  'problems.manage', 'Report, assign, resolve and close field problems.'),
  ('inputs',     'View inputs',      'inputs.view',     'See the input register, stock levels, issues and usage.'),
  ('inputs',     'Manage inputs',    'inputs.manage',   'Maintain the input catalogue, adjust stock and edit usage.'),
  ('inputs',     'Issue inputs',     'inputs.issue',    'Issue inputs to farmers and record what they use.'),
  ('harvest',    'View harvest',     'harvest.view',    'See harvest records, forecasts and batches.'),
  ('harvest',    'Manage harvest',   'harvest.manage',  'Record harvests, revise forecasts and close batches.'),
  ('batches',    'Manage batches',   'batches.manage',  'Create aggregation batches and trace their contents.'),
  ('markets',    'View markets',     'markets.view',    'See buyers, markets and the demand balance.'),
  ('markets',    'Manage markets',   'markets.manage',  'Maintain buyers, markets and market requirements.'),
  ('sales',      'Manage sales',     'sales.manage',    'Record sales, payments and deliveries.'),
  ('exports',    'Manage exports',   'exports.manage',  'Record export shipments, certificates and traceability.'),
  ('knowledge',  'View knowledge',   'knowledge.view',  'Read the Knowledge Centre.'),
  ('knowledge',  'Manage knowledge', 'knowledge.manage','Publish and edit Knowledge Centre material.'),
  ('reports',    'View reports',     'reports.view',    'Run, print and export the report suite and command centre.'),
  ('admin',      'Manage settings',  'admin.settings',  'Change system settings and reference data.'),
  ('admin',      'Manage users',     'admin.users',     'Create, edit, suspend and delete user accounts.'),
  ('admin',      'Manage roles',     'admin.roles',     'Create roles and assign permissions to them.'),
  ('admin',      'View audit log',   'admin.audit',     'Read and export the audit log.')
ON DUPLICATE KEY UPDATE
  `module`      = VALUES(`module`),
  `name`        = VALUES(`name`),
  `description` = VALUES(`description`);

-- ---------------------------------------------------------------------
-- 2. ROLES
--    is_system = 1 locks these rows against deletion by the admin
--    screen, because the application branches on their codes.
-- ---------------------------------------------------------------------

INSERT INTO `roles` (`name`, `code`, `description`, `is_system`) VALUES
  ('Super Administrator', 'super_admin',      'Unrestricted access to every module and to system administration.', 1),
  ('Manager',             'manager',          'Runs the cooperative: all operational modules and reporting, but not user or role administration.', 1),
  ('Field Officer',       'field_officer',    'Extension worker responsible for an assigned portfolio of farmers and gardens.', 1),
  ('Farmer',              'farmer',           'Producer. Sees only their own record, work and advisory material.', 1),
  ('Sales Manager',       'sales_manager',    'Handles buyers, markets, demand, sales, deliveries and exports.', 1),
  ('Extension Officer',   'extensions_officer','Agronomist maintaining crops, varieties, seasons and advisory content.', 1),
  ('Viewer',              'viewer',           'Read-only access across the platform for auditors and partner institutions.', 1)
ON DUPLICATE KEY UPDATE
  `name`        = VALUES(`name`),
  `description` = VALUES(`description`),
  `is_system`   = VALUES(`is_system`);

-- ---------------------------------------------------------------------
-- 3. ROLE PERMISSIONS
--    Driven from the permission code so that adding a code in section 1
--    and widening a role here is a one-line change each.
-- ---------------------------------------------------------------------

-- Super administrator: every permission there is. The application also
-- short-circuits this role to '*', but the rows are written so the
-- role editor shows the truth.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.`code` = 'super_admin';

-- Manager: everything except the two account-administration screens and
-- the audit log, which stay with the super administrator.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.`code` = 'manager'
  AND p.`code` NOT IN ('admin.users', 'admin.roles', 'admin.audit');

-- Field officer: the farmer, garden, planting and field-operations
-- modules in full, plus reading of everything needed to answer a query.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.`code` = 'field_officer'
  AND (
       p.`code` LIKE 'farmers.%'
    OR p.`code` LIKE 'gardens.%'
    OR p.`code` LIKE 'plantings.%'
    OR p.`code` LIKE 'activities.%'
    OR p.`code` LIKE 'visits.%'
    OR p.`code` LIKE 'inspections.%'
    OR p.`code` LIKE 'spraying.%'
    OR p.`code` LIKE 'problems.%'
    OR p.`code` IN (
         'groups.view', 'officers.view', 'map.view', 'crops.view', 'seasons.view',
         'inputs.view', 'inputs.issue', 'harvest.view', 'reports.view', 'knowledge.view'
       )
  );

-- Farmer: read their own work, report problems, record usage, and no
-- create or manage rights anywhere.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.`code` = 'farmer'
  AND p.`code` IN (
       'farmers.view', 'farmers.edit', 'gardens.view', 'groups.view', 'map.view',
       'crops.view', 'seasons.view', 'plantings.view', 'activities.view',
       'visits.view', 'problems.view', 'inputs.view', 'harvest.view', 'knowledge.view'
     );

-- Sales manager: the whole market chain, read access to production so
-- availability can be judged, and nothing to do with agronomy.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.`code` = 'sales_manager'
  AND (
       p.`code` IN (
            'markets.view', 'markets.manage', 'sales.manage', 'exports.manage',
            'batches.manage', 'harvest.view', 'reports.view', 'knowledge.view',
            'map.view', 'farmers.view', 'gardens.view', 'groups.view',
            'crops.view', 'seasons.view', 'plantings.view', 'activities.view',
            'inputs.view', 'inspections.view', 'problems.view'
          )
  );

-- Extension officer: owns the crop catalogue, the lifecycle schedules
-- and the Knowledge Centre; reads the field data it advises on.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.`code` = 'extensions_officer'
  AND (
       p.`code` LIKE 'crops.%'
    OR p.`code` LIKE 'seasons.%'
    OR p.`code` LIKE 'knowledge.%'
    OR p.`code` IN (
         'farmers.view', 'gardens.view', 'groups.view', 'map.view',
         'plantings.view', 'activities.view', 'inspections.view', 'spraying.view',
         'problems.view', 'visits.view', 'inputs.view', 'harvest.view', 'reports.view'
       )
  );

-- Viewer: read-only everywhere.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.`code` = 'viewer'
  AND p.`code` LIKE '%.view';-- ---------------------------------------------------------------------
-- 4. SYSTEM SETTINGS
--    Every key the application reads through Setting::get() or the
--    setting() helper, plus the configuration the settings screen lets an
--    administrator change. Credentials are flagged is_secret = 1 so the
--    settings screen never echoes them.
-- ---------------------------------------------------------------------

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_group`, `description`, `is_secret`) VALUES
  ('app.name', 'CAHU Agribusiness Platform', 'general', 'Institution display name.', 0),
  ('app.short_name', 'CAHU', 'general', 'Short name used in page titles and report headers.', 0),
  ('app.currency', 'UGX', 'general', 'Default currency for money formatting.', 0),
  ('app.timezone', 'Africa/Kampala', 'general', 'Application timezone.', 0),
  ('app.locale', 'en_UG', 'general', 'Default locale.', 0),
  ('app.version', '1.0.0', 'general', 'Application version shown in the footer.', 0),
  ('app.support_email', 'support@cahu.ug', 'general', 'Contact address shown on the login and help screens.', 0),
  ('app.default_password', 'Cahu@2026', 'general', 'Default password issued to newly registered users. Users are forced to change it.', 0),
  ('app.registration_open', '1', 'general', 'Allow new farmer self-registration through the public register screen.', 0),
  ('app.allow_registration', '1', 'general', 'Toggles the public registration route.', 0),
  ('score.weight.activity_completion', '40', 'general', 'Weight of activity completion in the farmer performance score.', 0),
  ('score.weight.inspection_uptake', '15', 'general', 'Weight of inspection uptake in the farmer performance score.', 0),
  ('score.weight.input_usage', '10', 'general', 'Weight of input usage reporting in the farmer performance score.', 0),
  ('score.weight.problem_reporting', '10', 'general', 'Weight of problem reporting in the farmer performance score.', 0),
  ('score.weight.harvest_submission', '15', 'general', 'Weight of harvest submission in the farmer performance score.', 0),
  ('score.weight.field_visit', '10', 'general', 'Weight of field visits received in the farmer performance score.', 0),
  ('map.default_lat', '1.3733', 'map', 'Default map centre latitude.', 0),
  ('map.default_lng', '32.8883', 'map', 'Default map centre longitude.', 0),
  ('map.default_zoom', '7', 'map', 'Default map zoom level.', 0),
  ('map.tile_url', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', 'map', 'Map tile URL template.', 0),
  ('map.attribution', '&copy; OpenStreetMap contributors', 'map', 'Map tile attribution text.', 0),
  ('map.harvest_ready_days', '21', 'map', 'Days after planting before a plot is flagged harvest-ready.', 0),
  ('forecast.factor.excellent', '1.15', 'forecast', 'Yield adjustment factor when crop condition is excellent.', 0),
  ('forecast.factor.good', '1.05', 'forecast', 'Yield adjustment factor when crop condition is good.', 0),
  ('forecast.factor.fair', '0.90', 'forecast', 'Yield adjustment factor when crop condition is fair.', 0),
  ('forecast.factor.poor', '0.70', 'forecast', 'Yield adjustment factor when crop condition is poor.', 0),
  ('forecast.factor.critical', '0.45', 'forecast', 'Yield adjustment factor when crop condition is critical.', 0),
  ('sms.enabled', '0', 'sms', 'Enable sending SMS through the configured provider.', 1),
  ('sms.provider', 'generic', 'sms', 'SMS provider identifier.', 0),
  ('sms.api_url', '', 'sms', 'SMS provider HTTP API endpoint. Leave blank to queue only.', 1),
  ('sms.api_key', '', 'sms', 'SMS provider API key or token.', 1),
  ('sms.sender_id', 'CAHU', 'sms', 'Sender identity shown to recipients.', 0),
  ('email.transport', 'smtp', 'email', 'Mail transport: smtp, sendmail or log.', 0),
  ('email.host', 'mail.shopiespot.com', 'email', 'SMTP server hostname.', 0),
  ('email.port', '465', 'email', 'SMTP server port.', 0),
  ('email.encryption', 'ssl', 'email', 'SMTP encryption: ssl or tls.', 0),
  ('email.username', '', 'email', 'SMTP username.', 1),
  ('email.password', '', 'email', 'SMTP password.', 1),
  ('email.from_email', 'noreply@cahu.ug', 'email', 'From address for platform mail.', 0),
  ('email.from_name', 'CAHU', 'email', 'From name for platform mail.', 0),
  ('security.session_lifetime', '1800', 'security', 'Session lifetime in seconds before automatic logout.', 0),
  ('security.max_login_attempts', '5', 'security', 'Failed logins before an account is locked for fifteen minutes.', 0),
  ('security.password_min_length', '8', 'security', 'Minimum accepted password length.', 0),
  ('security.password_require_digit', '1', 'security', 'Require at least one digit in user passwords.', 0),
  ('security.password_require_letter', '1', 'security', 'Require at least one letter in user passwords.', 0),
  ('security.account_lock_minutes', '15', 'security', 'Minutes an account stays locked after too many failed logins.', 0),
  ('uploads.path', '/storage/uploads', 'uploads', 'Server path where uploaded files are stored.', 0),
  ('uploads.url', '/storage/uploads', 'uploads', 'URL prefix used to serve uploaded files.', 0),
  ('uploads.max_image_kb', '4096', 'uploads', 'Maximum size in KB for an uploaded image.', 0),
  ('uploads.max_doc_kb', '10240', 'uploads', 'Maximum size in KB for an uploaded document.', 0),
  ('uploads.max_video_kb', '102400', 'uploads', 'Maximum size in KB for an uploaded video.', 0),
  ('notifications.enabled', '1', 'notifications', 'Enable in-system notifications.', 0),
  ('notifications.sms_fallback', '0', 'notifications', 'Send an SMS copy when an in-system notification is created.', 0),
  ('notifications.dedupe_window_hours', '24', 'notifications', 'Hours during which a duplicate dedupe key is suppressed.', 0)
ON DUPLICATE KEY UPDATE
  `setting_value` = VALUES(`setting_value`),
  `setting_group` = VALUES(`setting_group`),
  `description`   = VALUES(`description`),
  `is_secret`     = VALUES(`is_secret`);
-- ---------------------------------------------------------------------
-- 4. SYSTEM SETTINGS
--    Every key the application reads through Setting::get() or the
--    setting() helper, plus the configuration the settings screen lets an
--    administrator change. Credentials are flagged is_secret = 1 so the
--    settings screen never echoes them.
-- ---------------------------------------------------------------------

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_group`, `description`, `is_secret`) VALUES
  ('app.name', 'CAHU Agribusiness Platform', 'general', 'Institution display name.', 0),
  ('app.short_name', 'CAHU', 'general', 'Short name used in page titles and report headers.', 0),
  ('app.currency', 'UGX', 'general', 'Default currency for money formatting.', 0),
  ('app.timezone', 'Africa/Kampala', 'general', 'Application timezone.', 0),
  ('app.locale', 'en_UG', 'general', 'Default locale.', 0),
  ('app.version', '1.0.0', 'general', 'Application version shown in the footer.', 0),
  ('app.support_email', 'support@cahu.ug', 'general', 'Contact address shown on the login and help screens.', 0),
  ('app.default_password', 'Cahu@2026', 'general', 'Default password issued to newly registered users. Users are forced to change it.', 0),
  ('app.registration_open', '1', 'general', 'Allow new farmer self-registration through the public register screen.', 0),
  ('app.allow_registration', '1', 'general', 'Toggles the public registration route.', 0),
  ('score.weight.activity_completion', '40', 'general', 'Weight of activity completion in the farmer performance score.', 0),
  ('score.weight.inspection_uptake', '15', 'general', 'Weight of inspection uptake in the farmer performance score.', 0),
  ('score.weight.input_usage', '10', 'general', 'Weight of input usage reporting in the farmer performance score.', 0),
  ('score.weight.problem_reporting', '10', 'general', 'Weight of problem reporting in the farmer performance score.', 0),
  ('score.weight.harvest_submission', '15', 'general', 'Weight of harvest submission in the farmer performance score.', 0),
  ('score.weight.field_visit', '10', 'general', 'Weight of field visits received in the farmer performance score.', 0),
  ('map.default_lat', '1.3733', 'map', 'Default map centre latitude.', 0),
  ('map.default_lng', '32.8883', 'map', 'Default map centre longitude.', 0),
  ('map.default_zoom', '7', 'map', 'Default map zoom level.', 0),
  ('map.tile_url', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', 'map', 'Map tile URL template.', 0),
  ('map.attribution', '&copy; OpenStreetMap contributors', 'map', 'Map tile attribution text.', 0),
  ('map.harvest_ready_days', '21', 'map', 'Days after planting before a plot is flagged harvest-ready.', 0),
  ('forecast.factor.excellent', '1.15', 'forecast', 'Yield adjustment factor when crop condition is excellent.', 0),
  ('forecast.factor.good', '1.05', 'forecast', 'Yield adjustment factor when crop condition is good.', 0),
  ('forecast.factor.fair', '0.90', 'forecast', 'Yield adjustment factor when crop condition is fair.', 0),
  ('forecast.factor.poor', '0.70', 'forecast', 'Yield adjustment factor when crop condition is poor.', 0),
  ('forecast.factor.critical', '0.45', 'forecast', 'Yield adjustment factor when crop condition is critical.', 0),
  ('sms.enabled', '0', 'sms', 'Enable sending SMS through the configured provider.', 1),
  ('sms.provider', 'generic', 'sms', 'SMS provider identifier.', 0),
  ('sms.api_url', '', 'sms', 'SMS provider HTTP API endpoint. Leave blank to queue only.', 1),
  ('sms.api_key', '', 'sms', 'SMS provider API key or token.', 1),
  ('sms.sender_id', 'CAHU', 'sms', 'Sender identity shown to recipients.', 0),
  ('email.transport', 'smtp', 'email', 'Mail transport: smtp, sendmail or log.', 0),
  ('email.host', 'mail.shopiespot.com', 'email', 'SMTP server hostname.', 0),
  ('email.port', '465', 'email', 'SMTP server port.', 0),
  ('email.encryption', 'ssl', 'email', 'SMTP encryption: ssl or tls.', 0),
  ('email.username', '', 'email', 'SMTP username.', 1),
  ('email.password', '', 'email', 'SMTP password.', 1),
  ('email.from_email', 'noreply@cahu.ug', 'email', 'From address for platform mail.', 0),
  ('email.from_name', 'CAHU', 'email', 'From name for platform mail.', 0),
  ('security.session_lifetime', '1800', 'security', 'Session lifetime in seconds before automatic logout.', 0),
  ('security.max_login_attempts', '5', 'security', 'Failed logins before an account is locked for fifteen minutes.', 0),
  ('security.password_min_length', '8', 'security', 'Minimum accepted password length.', 0),
  ('security.password_require_digit', '1', 'security', 'Require at least one digit in user passwords.', 0),
  ('security.password_require_letter', '1', 'security', 'Require at least one letter in user passwords.', 0),
  ('security.account_lock_minutes', '15', 'security', 'Minutes an account stays locked after too many failed logins.', 0),
  ('uploads.path', '/storage/uploads', 'uploads', 'Server path where uploaded files are stored.', 0),
  ('uploads.url', '/storage/uploads', 'uploads', 'URL prefix used to serve uploaded files.', 0),
  ('uploads.max_image_kb', '4096', 'uploads', 'Maximum size in KB for an uploaded image.', 0),
  ('uploads.max_doc_kb', '10240', 'uploads', 'Maximum size in KB for an uploaded document.', 0),
  ('uploads.max_video_kb', '102400', 'uploads', 'Maximum size in KB for an uploaded video.', 0),
  ('notifications.enabled', '1', 'notifications', 'Enable in-system notifications.', 0),
  ('notifications.sms_fallback', '0', 'notifications', 'Send an SMS copy when an in-system notification is created.', 0),
  ('notifications.dedupe_window_hours', '24', 'notifications', 'Hours during which a duplicate dedupe key is suppressed.', 0)
ON DUPLICATE KEY UPDATE
  `setting_value` = VALUES(`setting_value`),
  `setting_group` = VALUES(`setting_group`),
  `description`   = VALUES(`description`),
  `is_secret`     = VALUES(`is_secret`);
-- ---------------------------------------------------------------------
-- 5. USERS
--    One account per system role, plus eight demo farmers. Passwords are
--    real BCrypt hashes produced by password_hash(); the plaintext is in
--    the demo-only block header above and must be rotated before going
--    live. UUIDs are v4 and written literally.
-- ---------------------------------------------------------------------

INSERT INTO `users` (`uuid`, `username`, `email`, `phone`, `password_hash`, `first_name`, `last_name`, `status`, `must_change_password`, `last_login_at`, `last_login_ip`, `created_at`) VALUES
  ('a1c158ea-7ae8-4afb-9a96-77cc0ac94cac', 'admin', 'admin@cahu.ug', '+256700000001', '$2y$10$DjKRy0cIwDy24HasC4YfSujZbJaqu5TNOZEcDZcqLb0kA1g1pANlK', 'System', 'Administrator', 'active', 0, '2026-09-30 16:05:00', '192.168.1.10', '2026-01-01 08:00:00'),
  ('a848f526-64cb-4c36-9baa-2f040e36e5c4', 'manager', 'manager@cahu.ug', '+256700000002', '$2y$10$VARisNhAdlQPIMXsfenFTOetTtKHCaw5q//IJ6HDKE30fA184fV/e', 'Sarah', 'Nakato', 'active', 0, '2026-09-30 15:45:00', '192.168.1.11', '2026-01-02 08:00:00'),
  ('99ec6df3-045c-45b1-8a7f-87d988ab35a9', 'officer', 'officer@cahu.ug', '+256700000003', '$2y$10$pezKyGe/rX0YSpmHxHbAmed0GFcuUtK.BlqdfOf6psOAuqf7NT50y', 'Musa', 'Ssemwanga', 'active', 0, '2026-09-30 14:20:00', '192.168.1.12', '2026-01-03 08:00:00'),
  ('1a2d0a1a-3be2-43ea-b4de-729cdf4dd29f', 'sales', 'sales@cahu.ug', '+256700000004', '$2y$10$GTZdLJiTBMYl0r1JMOmLveciw3D2BdS8Vd0Wx0B.tF/3c9Hv5N77i', 'Acme', 'Exports Ltd', 'active', 0, '2026-09-29 11:00:00', '192.168.1.13', '2026-01-04 08:00:00'),
  ('6c4acb19-1f04-4ac2-9602-a0f55cb6773f', 'extension', 'extension@cahu.ug', '+256700000005', '$2y$10$PhGT4qQKTgdDnvKGloXweu2HLrmDMxwG7W50bka7slF2pJryw1/h6', 'Dr. Alice', 'Acheng', 'active', 0, '2026-09-28 09:30:00', '192.168.1.14', '2026-01-05 08:00:00'),
  ('0e89578c-8935-45ed-a687-4739f89b57a9', 'viewer', 'viewer@cahu.ug', '+256700000006', '$2y$10$cjOL5aAAoXfLoQ/WUm9Og.KtmzkaFwVFMtW.RTVoH/FgG27PQD.US', 'Audit', 'Team', 'active', 0, NULL, NULL, '2026-01-06 08:00:00')
ON DUPLICATE KEY UPDATE
  `email`            = VALUES(`email`),
  `phone`            = VALUES(`phone`),
  `password_hash`    = VALUES(`password_hash`),
  `first_name`       = VALUES(`first_name`),
  `last_name`        = VALUES(`last_name`),
  `status`           = VALUES(`status`),
  `must_change_password` = VALUES(`must_change_password`),
  `last_login_at`    = VALUES(`last_login_at`),
  `last_login_ip`    = VALUES(`last_login_ip`);
INSERT INTO `users` (`uuid`, `username`, `email`, `phone`, `password_hash`, `first_name`, `last_name`, `status`, `must_change_password`, `last_login_at`, `last_login_ip`, `created_at`) VALUES
  ('44b35461-fb37-434a-be75-8812fd791e1a', 'farmer1', 'farmer1@cahu.ug', '+256701000001', '$2y$10$C2sRYavaF8VT2LIWxtpsn.R7V83p6KrFBEx9so8pFSnsep.J.RzqG', 'Kato', 'Nakato', 'active', 0, '2026-09-29 08:10:00', '192.168.2.5', '2026-02-01 08:00:00'),
  ('1133f480-cc97-4707-b5c0-45533d77f5d7', 'farmer2', 'farmer2@cahu.ug', '+256701000002', '$2y$10$kiSQiV12ppJnbMOmHZHWmObAVoY7UgQ3HWrStQiNsKqA6BaKpiMym', 'Ssebo', 'Mukasa', 'active', 0, '2026-09-29 08:12:00', '192.168.2.6', '2026-02-02 08:00:00'),
  ('8fd096e4-21b1-4166-9a1c-32493b15b063', 'farmer3', 'farmer3@cahu.ug', '+256701000003', '$2y$10$1JwRr.9dJOA/gV56qOygJ.rqz0j36qFjMJmTStwxZTZdBd7Ip0jnm', 'Aisha', 'Nassolo', 'active', 0, '2026-09-29 08:15:00', '192.168.2.7', '2026-02-03 08:00:00'),
  ('a972a33f-5c7a-434a-8ea5-e01b7c4641f3', 'farmer4', 'farmer4@cahu.ug', '+256701000004', '$2y$10$KLMfpEHmNsAudiDjRfmXXOUCoiiq.xXAfXnGzl9IpdEHaSCaRCbgi', 'John', 'Kampala', 'active', 0, '2026-09-29 08:18:00', '192.168.2.8', '2026-02-04 08:00:00'),
  ('ed07575c-f98b-4ada-ae62-3d2511859419', 'farmer5', 'farmer5@cahu.ug', '+256701000005', '$2y$10$oiLuY30L0xiE2peTyj9FVOHkLyXIeNio/Qf1uGQc0/do9Vg2Zk79O', 'Mary', 'Nambooza', 'active', 0, '2026-09-29 08:20:00', '192.168.2.9', '2026-02-05 08:00:00'),
  ('f844b49a-8bb5-4360-8dde-8a73de6d380a', 'farmer6', 'farmer6@cahu.ug', '+256701000006', '$2y$10$T502haviia5vtxXPBk6MIu0J7zFnIrZhRT3y15zC3ZiMv78tYqPJ.', 'Paul', 'Ssewankambo', 'active', 0, '2026-09-29 08:22:00', '192.168.2.10', '2026-02-06 08:00:00'),
  ('98a5c59e-6710-4675-94b8-ed358ed6729e', 'farmer7', 'farmer7@cahu.ug', '+256701000007', '$2y$10$IIm24Qa2JIfcOaor88VCZOaD3lrXfg8FvlvcjLOsCrhbpnr0ucFP2', 'Jane', 'Nalubega', 'active', 0, '2026-09-29 08:25:00', '192.168.2.11', '2026-02-07 08:00:00'),
  ('bee2d7f5-e01a-4529-8aba-f4a259a97542', 'farmer8', 'farmer8@cahu.ug', '+256701000008', '$2y$10$hL736Ue/v0nhzuQazqwSHeILyxtG5PqUgjAO.yOoGmFVIDsW7k8mS', 'Sam', 'Mugisha', 'active', 0, '2026-09-29 08:27:00', '192.168.2.12', '2026-02-08 08:00:00')
ON DUPLICATE KEY UPDATE
  `email`                = VALUES(`email`),
  `phone`                = VALUES(`phone`),
  `password_hash`        = VALUES(`password_hash`),
  `last_login_at`        = VALUES(`last_login_at`),
  `last_login_ip`        = VALUES(`last_login_ip`);
-- ---------------------------------------------------------------------
-- 6. LOGIN HISTORIES
--    A few rows so the admin audit screen is not empty on a fresh
--    install. The user_id values reference the users inserted above.
-- ---------------------------------------------------------------------

INSERT INTO `login_histories` (`user_id`, `username_attempted`, `success`, `reason`, `ip_address`, `user_agent`, `created_at`) VALUES
  (1, 'admin', 1, NULL, '192.168.1.10', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0', '2026-09-30 16:05:00'),
  (1, 'admin', 1, NULL, '192.168.1.10', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0', '2026-09-29 09:00:00'),
  (2, 'manager', 1, NULL, '192.168.1.11', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/17.0', '2026-09-30 15:45:00'),
  (3, 'officer', 1, NULL, '192.168.1.12', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148', '2026-09-30 14:20:00'),
  (3, 'officer', 0, 'Wrong password', '192.168.1.12', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148', '2026-09-30 14:18:00'),
  (4, 'sales', 1, NULL, '192.168.1.13', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Edge/128.0', '2026-09-29 11:00:00'),
  (5, 'extension', 1, NULL, '192.168.1.14', 'Mozilla/5.0 (X11; Linux x86_64) Firefox/129.0', '2026-09-28 09:30:00'),
  (7, 'farmer1', 1, NULL, '192.168.2.5', 'Mozilla/5.0 (Android 14) Chrome/128.0', '2026-09-29 08:10:00'),
  (8, 'farmer2', 1, NULL, '192.168.2.6', 'Mozilla/5.0 (Android 14) Chrome/128.0', '2026-09-29 08:12:00'),
  (9, 'farmer3', 1, NULL, '192.168.2.7', 'Mozilla/5.0 (Android 14) Chrome/128.0', '2026-09-29 08:15:00'),
  (10, 'farmer4', 1, NULL, '192.168.2.8', 'Mozilla/5.0 (Android 14) Chrome/128.0', '2026-09-29 08:18:00'),
  (11, 'farmer5', 1, NULL, '192.168.2.9', 'Mozilla/5.0 (Android 14) Chrome/128.0', '2026-09-29 08:20:00'),
  (12, 'farmer6', 1, NULL, '192.168.2.10', 'Mozilla/5.0 (Android 14) Chrome/128.0', '2026-09-29 08:22:00'),
  (13, 'farmer7', 1, NULL, '192.168.2.11', 'Mozilla/5.0 (Android 14) Chrome/128.0', '2026-09-29 08:25:00'),
  (14, 'farmer8', 1, NULL, '192.168.2.12', 'Mozilla/5.0 (Android 14) Chrome/128.0', '2026-09-29 08:27:00'),
  (NULL, 'unknown@example.com', 0, 'Invalid credentials', '203.0.113.5', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0', '2026-09-30 10:00:00'),
  (NULL, 'admin', 0, 'Invalid credentials', '203.0.113.7', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0', '2026-09-30 10:04:00');
