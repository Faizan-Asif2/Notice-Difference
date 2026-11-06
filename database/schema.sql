-- NoticeDiff schema. Times are stored in UTC.

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(100) NOT NULL,
  email         VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('student','instructor','admin') NOT NULL DEFAULT 'student',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS semesters (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(50) NOT NULL,
  start_date DATE NOT NULL,
  end_date   DATE NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sections (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  semester_id INT UNSIGNED NOT NULL,
  name        VARCHAR(50) NOT NULL,
  UNIQUE (semester_id, name),
  FOREIGN KEY (semester_id) REFERENCES semesters(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS courses (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(20) NOT NULL UNIQUE,
  name          VARCHAR(150) NOT NULL,
  semester_id   INT UNSIGNED NOT NULL,
  instructor_id INT UNSIGNED NOT NULL,
  FOREIGN KEY (semester_id)   REFERENCES semesters(id),
  FOREIGN KEY (instructor_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS enrollments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id  INT UNSIGNED NOT NULL,
  course_id   INT UNSIGNED NOT NULL,
  section_id  INT UNSIGNED NOT NULL,
  enrolled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (student_id, course_id),
  FOREIGN KEY (student_id) REFERENCES users(id),
  FOREIGN KEY (course_id)  REFERENCES courses(id),
  FOREIGN KEY (section_id) REFERENCES sections(id)
) ENGINE=InnoDB;

-- The original_* columns are the official timetable and are never overwritten.
CREATE TABLE IF NOT EXISTS lectures (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id         INT UNSIGNED NOT NULL,
  instructor_id     INT UNSIGNED NOT NULL,
  section_id        INT UNSIGNED NOT NULL,
  original_date     DATE NOT NULL,
  original_start    TIME NOT NULL,
  original_end      TIME NOT NULL,
  original_room     VARCHAR(50) NOT NULL,
  original_mode     ENUM('on_campus','online') NOT NULL DEFAULT 'on_campus',
  original_start_at DATETIME NOT NULL COMMENT 'UTC; used for the 24-hour deadline',
  status            ENUM('scheduled','cancelled','rescheduled','completed') NOT NULL DEFAULT 'scheduled',
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (course_id)     REFERENCES courses(id),
  FOREIGN KEY (instructor_id) REFERENCES users(id),
  FOREIGN KEY (section_id)    REFERENCES sections(id)
) ENGINE=InnoDB;

-- Proposed changes live here, separate from the original lecture.
CREATE TABLE IF NOT EXISTS change_requests (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lecture_id          INT UNSIGNED NOT NULL,
  requested_by        INT UNSIGNED NOT NULL,
  change_type         ENUM('cancellation','reschedule','time_change','room_change','mode_change','additional') NOT NULL,
  proposed_date       DATE NULL,
  proposed_start      TIME NULL,
  proposed_end        TIME NULL,
  proposed_room       VARCHAR(50) NULL,
  proposed_mode       ENUM('on_campus','online') NULL,
  reason              TEXT NOT NULL,
  submitted_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deadline_at         DATETIME NOT NULL COMMENT 'original_start_at minus 24 hours',
  is_late             TINYINT(1) NOT NULL DEFAULT 0,
  late_justification  TEXT NULL,
  status              ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by         INT UNSIGNED NULL,
  reviewed_at         DATETIME NULL,
  review_note         TEXT NULL,
  FOREIGN KEY (lecture_id)   REFERENCES lectures(id),
  FOREIGN KEY (requested_by) REFERENCES users(id),
  FOREIGN KEY (reviewed_by)  REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  change_request_id INT UNSIGNED NOT NULL,
  message           TEXT NOT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_read           TINYINT(1) NOT NULL DEFAULT 0,
  read_at           DATETIME NULL,
  FOREIGN KEY (user_id)           REFERENCES users(id),
  FOREIGN KEY (change_request_id) REFERENCES change_requests(id)
) ENGINE=InnoDB;

-- Audit trail: rows can be added, never edited or deleted (see triggers below).
CREATE TABLE IF NOT EXISTS change_history (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lecture_id        INT UNSIGNED NOT NULL,
  change_request_id INT UNSIGNED NULL,
  action            ENUM('created','requested','approved','rejected','notified') NOT NULL,
  performed_by      INT UNSIGNED NULL,
  details           TEXT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (lecture_id)        REFERENCES lectures(id),
  FOREIGN KEY (change_request_id) REFERENCES change_requests(id),
  FOREIGN KEY (performed_by)      REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transport_settings (
  user_id         INT UNSIGNED PRIMARY KEY,
  transport_type  ENUM('university_bus','personal','public','hostel_walking','other') NOT NULL DEFAULT 'other',
  bus_departure   TIME NULL,
  bus_return      TIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

DROP TRIGGER IF EXISTS change_history_no_update;
DROP TRIGGER IF EXISTS change_history_no_delete;

DELIMITER //
CREATE TRIGGER change_history_no_update BEFORE UPDATE ON change_history
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'change_history is read-only';
END//
CREATE TRIGGER change_history_no_delete BEFORE DELETE ON change_history
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'change_history is read-only';
END//
DELIMITER ;
