-- Reverses database/migration_vessel_change_history.sql (Vessels Data change history).
-- Only run this when the Vessels Data module is being removed: it deletes the recorded
-- change history. No other table is touched; the surveys rows keep their current values.
-- Remove vessels_data.php / ajax/vessels_data.php / export_vessels_data.php first, otherwise
-- includes/vessels_data.php re-creates the (empty) table on its next use.

DROP TABLE IF EXISTS `vessel_change_history`;
DELETE FROM `list_grid_prefs` WHERE `page` = 'vessels_data';
