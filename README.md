# Grading System (XAMPP)

## Install
1. Start **Apache** and **MySQL** in XAMPP.
2. Copy all files into `C:\xampp\htdocs\grading_system\` (replace existing files, keep the folder).
3. phpMyAdmin -> **Import** -> `database.sql` (only needed once; updates add new columns automatically).
4. Edit `config.php`: `DEVICE_TOKEN`, `ADMIN_SETUP_KEY`, and the `BIO_DB_*` settings if yours differ.
5. Open `http://localhost/grading_system/` and sign up. Hidden admin: `admin_setup.php`.

## Link the biometric departments (the department name format)

The biometric `departments.department_name` is the single source of truth for a class.
Write it in either format:

| Department name in the biometric system | Year level | Section | Subject code | Subject name |
|---|---|---|---|---|
| `3rd Year SIA101-F` | 3rd Year | F | SIA101 | (typed in when importing) |
| `4th Year OS101-A` | 4th Year | A | OS101 | (typed in when importing) |
| `BSIT 1C` | BSIT 1 | C | | |
| `BSIT 1 - C` | BSIT 1 | C | | |
| `BSIT 1 \| C \| OS101 \| Operating Systems` | BSIT 1 | C | OS101 | Operating Systems |

Use the `|` format when you also want the subject filled in automatically.

### Steps
1. In the biometric system, name each department using the format above, and set every student's department.
   (Or in phpMyAdmin, database `srpvhsdb`:)
   ```sql
   UPDATE departments SET department_name = 'BSIT 1 | C | OS101 | Operating Systems' WHERE id = 1;
   ```
2. Grading system -> **Manage Students** -> **Import from biometric system**. Year, section and subject are
   pre-filled from the department name. Tick the departments and click **Import selected**.
   Each section is now **linked** to its biometric department.
3. From then on it is automatic:
   - **First scan of a new student:** added to the linked section(s) of their department, and that scan is recorded.
   - **Sync now** (or just opening Manage Students) re-pulls the students of every linked section.
   - **Change a student's department in the biometric system:** on their next scan they are added to the section
     linked to the new department. Their old section keeps them (so grades are never deleted by accident) and shows
     a warning "Biometric department is now: ..." on the Students tab. Remove them there when you are ready.
4. A section created by hand is linked automatically on the first scan when its year level, section (and subject
   code, if the department name has one) match the department name. Otherwise Edit the section and choose the
   **Biometric department**.

## Tables (database.sql)
`teachers (role: teacher|admin)` -> `sections (bio_department_id, name_format)` -> `students (fingerprint_id)`;
`class_sessions`, `attendance (present|excused, am/pm in/out)`; `assessments` + `scores`;
`attendance_scans` (every scan and why it was or was not matched).

## Connect your biometric system
`getdata.php` calls `scan.php` after saving each scan (see the updated file). Token must match `DEVICE_TOKEN`.

## How grades work
Attendance 10% = (Present + Excused) / class days. Quizzes 10%, Boardwork/Recitation 10%, Project 20%,
Midterm 25%, Final 25%: points earned / points possible. A category with no scores counts as 0 (shown "pending").
The 1.00-5.00 equivalent table is `grade_equivalent()` in `config.php`.
