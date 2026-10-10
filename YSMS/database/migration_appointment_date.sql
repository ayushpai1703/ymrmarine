-- Adds an Appointment Date to surveys, set from assign_vessel.php (mandatory
-- there for every new/edited assignment). Safe to run on an existing live
-- database: nullable column (existing rows have none), no data loss.

ALTER TABLE `surveys`
    ADD COLUMN `appointment_date` DATE NULL DEFAULT NULL AFTER `assign_date`;
