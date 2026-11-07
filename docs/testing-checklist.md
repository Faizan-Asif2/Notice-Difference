# Testing checklist

Record Pass or Fail and the date for each row.

## Access control

| # | Scenario | Expected | Result |
|---|---|---|---|
| 1 | Logged out, open `/home.php` | Redirected to login | |
| 2 | Logged out, open `/admin/users.php` | Redirected to login | |
| 3 | Student opens `/admin/users.php` | 403 Access denied, no user data | |
| 4 | Student opens `/instructor/request.php` | 403 Access denied | |
| 5 | Instructor opens `/admin/approvals.php` | 403 Access denied | |
| 6 | Admin opens `/student/timetable.php` | 403 Access denied | |

## Authentication

| # | Scenario | Expected | Result |
|---|---|---|---|
| 7 | Register with a new email | Account created, role is student | |
| 8 | Register with an existing email | "Already registered" message | |
| 9 | Register with a short password | Rejected | |
| 10 | Log in with a wrong password | One generic error message | |
| 11 | Log out, press Back | Protected page is not shown | |
| 12 | Password column in the database | Starts with `$2y$` | |

## 24-hour rule

| # | Scenario | Expected | Result |
|---|---|---|---|
| 13 | Request for a lecture more than 24 hours away | Saved, `is_late = 0` | |
| 14 | Request for a lecture under 24 hours away, no emergency text | Rejected with a message | |
| 15 | Same request with an emergency explanation | Saved, `is_late = 1` | |
| 16 | Second request for a lecture that already has a pending one | Rejected | |
| 17 | Instructor tries another instructor's lecture id | Rejected | |

## Approval and notifications

| # | Scenario | Expected | Result |
|---|---|---|---|
| 18 | Student dashboard while the request is pending | The change is not shown | |
| 19 | Admin approves | Request approved, lecture status changed, notification created | |
| 20 | Enrolled student after approval | Bell count, dashboard card and notification appear | |
| 21 | Student not enrolled in that course | Sees nothing | |
| 22 | Admin rejects without a reason | Rejected with a message | |
| 23 | Admin rejects with a reason | No notification created | |
| 24 | Approve the same request twice (two tabs) | Second attempt says already reviewed | |
| 25 | Student marks a notification as read | `is_read = 1` and `read_at` set | |

## History and integrity

| # | Scenario | Expected | Result |
|---|---|---|---|
| 26 | Student timetable for a changed lecture | Original struck through, new schedule shown | |
| 27 | Change history page | Timeline and notice message shown | |
| 28 | `UPDATE change_history ...` in MySQL | Error: read-only | |
| 29 | `DELETE FROM change_history ...` in MySQL | Error: read-only | |

## Security

| # | Scenario | Expected | Result |
|---|---|---|---|
| 30 | Log in with `' OR '1'='1` as the email | Login fails | |
| 31 | Register with the name `<script>alert(1)</script>` | Shown as plain text, no popup | |
| 32 | Submit a form without the CSRF token | Rejected (HTTP 400) | |
| 33 | Open `/config/database.php` in the browser | Not reachable | |