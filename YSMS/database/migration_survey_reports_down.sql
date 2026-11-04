-- Reverses database/migration_survey_reports.sql. Only for removing the Survey Reports module:
-- it deletes the stored report data and the template version list. The files under
-- uploads/report_templates/ and uploads/survey_reports/ are not deleted by this script.
-- Remove the module's PHP files first, otherwise rtEnsureTables() re-creates empty tables.

DROP TABLE IF EXISTS `survey_report_files`;
DROP TABLE IF EXISTS `survey_reports`;
DROP TABLE IF EXISTS `report_templates`;
