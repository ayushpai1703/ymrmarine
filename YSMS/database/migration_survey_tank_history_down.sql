-- Reverse of migration_survey_tank_history.sql. DESTROYS the tank version history — only for
-- rolling back a deployment, after taking a backup. The app recreates an empty table at runtime
-- unless the code is rolled back as well.
DROP TABLE IF EXISTS `survey_tank_entry_history`;
