-- Reverse of migration_sales_role.sql. Remove or re-role the Sales users first (users.role_id).
DROP TABLE IF EXISTS `sales_requests`;
DROP TABLE IF EXISTS `sales_targets`;
ALTER TABLE `clients` DROP COLUMN `created_by`, DROP COLUMN `created_at`;
DELETE FROM roles WHERE name = 'Sales' AND NOT EXISTS (SELECT 1 FROM users WHERE role_id = roles.id);
