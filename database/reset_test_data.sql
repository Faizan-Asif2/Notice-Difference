-- DESTROYS all test data except administrator accounts. Make a backup first.
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE change_history;
TRUNCATE TABLE notifications;
TRUNCATE TABLE change_requests;
TRUNCATE TABLE lectures;
TRUNCATE TABLE enrollments;
TRUNCATE TABLE courses;
TRUNCATE TABLE sections;
TRUNCATE TABLE semesters;
TRUNCATE TABLE transport_settings;
DELETE FROM users WHERE role <> 'admin';
SET FOREIGN_KEY_CHECKS = 1;