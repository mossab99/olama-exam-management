<?php
/**
 * Exam Hall Distribution System – Core Class
 *
 * All student queries route grade through sections table because
 * olama_student_enrollment has NO grade_id column – only section_id.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Exam_Hall
{
    /** Cache: does olama_exam_hall_assignments have a semester_id column? */
    private static $has_sem_col   = null;
    /** Guard so maybe_migrate() only runs once per PHP request */
    private static $migrated      = false;

    /**
     * Run inline schema migration for the exam-hall tables.
     * Called lazily before any write/read that depends on semester_id.
     * Safe to call multiple times – the guard prevents duplicate work.
     */
    public static function maybe_migrate()
    {
        if (self::$migrated) return;
        self::$migrated = true;

        global $wpdb;

        // Map: table_base => [ col_to_add => col_definition, after_col => hint or null ]
        $migrations = [
            'olama_exam_hall_assignments' => [
                'col'   => 'semester_id',
                'def'   => 'semester_id mediumint(9) NOT NULL DEFAULT 0',
                'after' => 'academic_year_id',
            ],
            'olama_exam_hall_attendance'  => [
                'col'   => 'academic_year_id',
                'def'   => 'academic_year_id mediumint(9) NOT NULL DEFAULT 0',
                'after' => 'hall_id',
            ],
            'olama_exam_hall_attendance_sem' => [
                'table' => 'olama_exam_hall_attendance',
                'col'   => 'semester_id',
                'def'   => 'semester_id mediumint(9) NOT NULL DEFAULT 0',
                'after' => 'academic_year_id',
            ],
            'olama_exam_hall_notes'       => [
                'col'   => 'semester_id',
                'def'   => 'semester_id mediumint(9) NOT NULL DEFAULT 0',
                'after' => 'exam_date',
            ],
            'olama_exam_hall_invigilators' => [
                'col'   => 'assigned_by',
                'def'   => 'assigned_by bigint(20) NOT NULL DEFAULT 0',
                'after' => 'semester_id',
            ],
        ];

        // Ensure attendance table has 'session_student' UNIQUE KEY.
        // NOTE: only add if no unique key covering those columns already exists
        // (the table may have been created with 'session_student' already).
        $attendance_table = $wpdb->prefix . 'olama_exam_hall_attendance';
        if ($wpdb->get_var("SHOW TABLES LIKE '$attendance_table'") === $attendance_table) {
            $has_session_student = $wpdb->get_results("SHOW INDEX FROM $attendance_table WHERE Key_name = 'session_student'");
            $has_unique_att      = $wpdb->get_results("SHOW INDEX FROM $attendance_table WHERE Key_name = 'unique_attendance'");
            // Only add our key if NEITHER version exists
            if (empty($has_session_student) && empty($has_unique_att)) {
                $wpdb->query("ALTER TABLE $attendance_table ADD UNIQUE KEY session_student (hall_id, student_id, exam_date, session_label)");
            }
        }

        foreach ($migrations as $key => $m) {
            $base  = $m['table'] ?? $key;
            $table = $wpdb->prefix . $base;

            // Skip if table doesn't exist yet
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) continue;

            // Skip if column already exists
            if (!empty($wpdb->get_results("SHOW COLUMNS FROM $table LIKE '{$m['col']}'"))) continue;

            // Check if the AFTER reference column exists in this table
            $after_clause = '';
            if (!empty($m['after'])) {
                $ref_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE '{$m['after']}'");
                if (!empty($ref_exists)) {
                    $after_clause = " AFTER {$m['after']}";
                }
            }

            $wpdb->query("ALTER TABLE $table ADD COLUMN {$m['def']}{$after_clause}");
        }

        // Create invigilators table if missing
        $inv_table = $wpdb->prefix . 'olama_exam_hall_invigilators';
        if ($wpdb->get_var("SHOW TABLES LIKE '$inv_table'") !== $inv_table) {
            $charset = $wpdb->get_charset_collate();
            $wpdb->query("CREATE TABLE $inv_table (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                hall_id mediumint(9) NOT NULL,
                invigilator_id bigint(20) UNSIGNED NOT NULL,
                academic_year_id mediumint(9) NOT NULL,
                semester_id mediumint(9) NOT NULL DEFAULT 0,
                assigned_by bigint(20) NOT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY inv_context (invigilator_id, academic_year_id, semester_id),
                KEY hall_context (hall_id, academic_year_id, semester_id)
            ) $charset");
        }

        // Refresh the cached flag after migration
        self::$has_sem_col = null;
    }

    /**
     * Returns true if olama_exam_hall_assignments.semester_id exists.
     * Checks only once per request.
     */
    private static function has_semester_col()
    {
        if (self::$has_sem_col === null) {
            global $wpdb;
            $cols = $wpdb->get_results(
                "SHOW COLUMNS FROM {$wpdb->prefix}olama_exam_hall_assignments LIKE 'semester_id'"
            );
            self::$has_sem_col = !empty($cols);
        }
        return self::$has_sem_col;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // HALLS
    // ──────────────────────────────────────────────────────────────────────────

    public static function get_halls($academic_year_id = 0)
    {
        global $wpdb;
        if (!$academic_year_id) {
            $year = Olama_School_Academic::get_active_year();
            $academic_year_id = $year ? $year->id : 0;
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}olama_exam_halls
             WHERE academic_year_id = %d AND is_active = 1
             ORDER BY hall_name ASC",
            $academic_year_id
        ));
    }

    public static function get_hall($hall_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}olama_exam_halls WHERE id = %d",
            $hall_id
        ));
    }

    public static function save_hall($data)
    {
        global $wpdb;
        $table     = $wpdb->prefix . 'olama_exam_halls';
        $hall_id   = isset($data['id']) ? intval($data['id']) : 0;
        $hall_name = sanitize_text_field($data['hall_name'] ?? '');
        $capacity  = max(1, intval($data['capacity'] ?? 30));
        $year_id   = intval($data['academic_year_id'] ?? 0);

        if (!$year_id) {
            $year    = Olama_School_Academic::get_active_year();
            $year_id = $year ? $year->id : 0;
        }

        if ($hall_id) {
            return $wpdb->update($table, [
                'hall_name'        => $hall_name,
                'capacity'         => $capacity,
                'academic_year_id' => $year_id,
            ], ['id' => $hall_id], ['%s', '%d', '%d'], ['%d']);
        }

        return $wpdb->insert($table, [
            'hall_name'        => $hall_name,
            'capacity'         => $capacity,
            'academic_year_id' => $year_id,
            'is_active'        => 1,
        ], ['%s', '%d', '%d', '%d']);
    }

    public static function delete_hall($hall_id)
    {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'olama_exam_halls',
            ['is_active' => 0],
            ['id' => intval($hall_id)],
            ['%d'],
            ['%d']
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // STUDENT QUERIES
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Get students for a grade/section context.
     * These are ALL enrolled students, not filtered by assignment status.
     */
    public static function get_filtered_students($year_id, $semester_id = 0, $grade_id = 0, $section_id = 0)
    {
        global $wpdb;

        $where = '';
        $vals  = [$year_id];

        if ($section_id) {
            $where .= ' AND e.section_id = %d';
            $vals[] = intval($section_id);
        } elseif ($grade_id) {
            $where .= ' AND sec.grade_id = %d';
            $vals[] = intval($grade_id);
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.student_name, s.student_uid,
                    sec.grade_id, e.section_id,
                    g.grade_name, sec.section_name
             FROM {$wpdb->prefix}olama_students s
             JOIN {$wpdb->prefix}olama_student_enrollment e
                ON e.student_id = s.id AND e.academic_year_id = %d
             LEFT JOIN {$wpdb->prefix}olama_sections sec ON sec.id = e.section_id
             LEFT JOIN {$wpdb->prefix}olama_grades g ON g.id = sec.grade_id
             WHERE 1=1 $where
             ORDER BY g.grade_name ASC, sec.section_name ASC, s.student_name ASC",
            $vals
        ));
    }

    /**
     * Get students from a grade/section NOT already in any of the canvas halls
     * (for the semester/year scope).
     */
    public static function get_canvas_unassigned($year_id, $semester_id, $grade_id, $section_id, array $canvas_hall_ids)
    {
        global $wpdb;

        $where = '';
        $vals  = [$year_id];

        if ($section_id) {
            $where .= ' AND e.section_id = %d';
            $vals[] = intval($section_id);
        } elseif ($grade_id) {
            $where .= ' AND sec.grade_id = %d';
            $vals[] = intval($grade_id);
        }

        // Exclude students already assigned to any canvas hall (for this year).
        // Use safe integer interpolation for the subquery to avoid %d count mismatch.
        if (!empty($canvas_hall_ids)) {
            $safe_year  = intval($year_id);
            $safe_halls = implode(',', array_map('intval', $canvas_hall_ids));
            // Add semester filter only when the column actually exists
            $sem_clause = ($semester_id && self::has_semester_col())
                ? ' AND semester_id = ' . intval($semester_id)
                : '';
            $where .= " AND s.id NOT IN (
                SELECT student_id FROM {$wpdb->prefix}olama_exam_hall_assignments
                WHERE academic_year_id = $safe_year $sem_clause
                  AND hall_id IN ($safe_halls)
            )";
            // No extra values pushed to $vals – everything inlined as safe integers
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.student_name, s.student_uid,
                    sec.grade_id, e.section_id,
                    g.grade_name, sec.section_name
             FROM {$wpdb->prefix}olama_students s
             JOIN {$wpdb->prefix}olama_student_enrollment e
                ON e.student_id = s.id AND e.academic_year_id = %d
             LEFT JOIN {$wpdb->prefix}olama_sections sec ON sec.id = e.section_id
             LEFT JOIN {$wpdb->prefix}olama_grades g ON g.id = sec.grade_id
             WHERE 1=1 $where
             ORDER BY g.grade_name ASC, sec.section_name ASC, s.student_name ASC",
            $vals
        ));
    }

    /**
     * Get assignments for specific canvas halls, filtered by grade/section,
     * returned as [ hall_id => [student, ...] ].
     */
    public static function get_canvas_assignments($year_id, $semester_id, array $hall_ids, $grade_id = 0, $section_id = 0)
    {
        global $wpdb;

        if (empty($hall_ids)) {
            return [];
        }

        $safe_halls  = implode(',', array_map('intval', $hall_ids));
        $safe_year   = intval($year_id);

        // Semester filter only when column exists
        $sem_clause  = ($semester_id && self::has_semester_col())
            ? ' AND a.semester_id = ' . intval($semester_id)
            : '';

        $extra_where = '';
        $extra_vals  = [];
        if ($section_id) {
            $extra_where .= ' AND e.section_id = %d';
            $extra_vals[] = intval($section_id);
        } elseif ($grade_id) {
            $extra_where .= ' AND sec.grade_id = %d';
            $extra_vals[] = intval($grade_id);
        }

        $sql = "SELECT a.hall_id, a.student_id, a.seat_number, a.student_uid,
                        s.student_name,
                        sec.grade_id, e.section_id,
                        g.grade_name, sec.section_name
                 FROM {$wpdb->prefix}olama_exam_hall_assignments a
                 JOIN {$wpdb->prefix}olama_students s ON s.id = a.student_id
                 LEFT JOIN {$wpdb->prefix}olama_student_enrollment e
                    ON e.student_id = a.student_id AND e.academic_year_id = a.academic_year_id
                 LEFT JOIN {$wpdb->prefix}olama_sections sec ON sec.id = e.section_id
                 LEFT JOIN {$wpdb->prefix}olama_grades g ON g.id = sec.grade_id
                 WHERE a.academic_year_id = $safe_year $sem_clause
                   AND a.hall_id IN ($safe_halls)
                   $extra_where
                 GROUP BY a.id
                 ORDER BY g.grade_name ASC, sec.section_name ASC, s.student_name ASC";

        $rows = empty($extra_vals)
            ? $wpdb->get_results($sql)
            : $wpdb->get_results($wpdb->prepare($sql, $extra_vals));

        $map = [];
        foreach ($rows as $r) {
            $map[$r->hall_id][] = $r;
        }
        return $map;
    }

    /**
     * Get students assigned to a specific hall (for attendance/notes tabs).
     */
    public static function get_hall_students($hall_id, $academic_year_id, $semester_id = 0)
    {
        global $wpdb;
        $where = 'a.hall_id = %d AND a.academic_year_id = %d';
        $vals  = [intval($hall_id), intval($academic_year_id)];
        if ($semester_id) {
            $where .= ' AND a.semester_id = %d';
            $vals[] = intval($semester_id);
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.student_id, a.seat_number, a.student_uid,
                    s.student_name,
                    sec.grade_id, e.section_id,
                    g.grade_name, sec.section_name
             FROM {$wpdb->prefix}olama_exam_hall_assignments a
             JOIN {$wpdb->prefix}olama_students s ON s.id = a.student_id
             LEFT JOIN {$wpdb->prefix}olama_student_enrollment e
                ON e.student_id = a.student_id AND e.academic_year_id = a.academic_year_id
             LEFT JOIN {$wpdb->prefix}olama_sections sec ON sec.id = e.section_id
             LEFT JOIN {$wpdb->prefix}olama_grades g ON g.id = sec.grade_id
             WHERE $where
             ORDER BY g.grade_name ASC, sec.section_name ASC, s.student_name ASC",
            $vals
        ));
    }

    /**
     * Get ALL assignments for the year/semester (unfiltered by canvas).
     */
    public static function get_all_assignments($academic_year_id, $semester_id = 0)
    {
        global $wpdb;
        $year_id = intval($academic_year_id);
        $sem_id  = intval($semester_id);
        
        $sem_clause = '';
        if ($sem_id) {
            if (self::has_semester_col()) {
                $sem_clause = $wpdb->prepare(" AND a.semester_id = %d", $sem_id);
            }
        }

        $rows = $wpdb->get_results(
            "SELECT a.hall_id, a.student_id, a.seat_number, a.student_uid,
                    s.student_name,
                    sec.grade_id, e.section_id,
                    g.grade_name, sec.section_name,
                    h.hall_name, h.capacity
             FROM {$wpdb->prefix}olama_exam_hall_assignments a
             JOIN {$wpdb->prefix}olama_students s ON s.id = a.student_id
             JOIN {$wpdb->prefix}olama_exam_halls h ON h.id = a.hall_id
             LEFT JOIN {$wpdb->prefix}olama_student_enrollment e
                ON e.student_id = a.student_id AND e.academic_year_id = a.academic_year_id
             LEFT JOIN {$wpdb->prefix}olama_sections sec ON sec.id = e.section_id
             LEFT JOIN {$wpdb->prefix}olama_grades g ON g.id = sec.grade_id
             WHERE a.academic_year_id = $year_id $sem_clause
             ORDER BY h.hall_name ASC, g.grade_name ASC, sec.section_name ASC, s.student_name ASC"
        );

        $map = [];
        foreach ($rows as $r) {
            $map[$r->hall_id][] = $r;
        }
        return $map;
    }

    /**
     * Legacy: get all unassigned students for a year (used when no canvas filter).
     * Grade route: enrollment → sections → grades.
     */
    public static function get_unassigned_students($academic_year_id, $semester_id = 0)
    {
        global $wpdb;
        $sem_clause = $semester_id ? " AND semester_id = $semester_id" : '';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.student_name, s.student_uid,
                    sec.grade_id, e.section_id,
                    g.grade_name, sec.section_name
             FROM {$wpdb->prefix}olama_students s
             JOIN {$wpdb->prefix}olama_student_enrollment e
                ON e.student_id = s.id AND e.academic_year_id = %d
             LEFT JOIN {$wpdb->prefix}olama_sections sec ON sec.id = e.section_id
             LEFT JOIN {$wpdb->prefix}olama_grades g ON g.id = sec.grade_id
             WHERE s.id NOT IN (
                 SELECT student_id FROM {$wpdb->prefix}olama_exam_hall_assignments
                 WHERE academic_year_id = %d $sem_clause
             )
             ORDER BY g.grade_name ASC, sec.section_name ASC, s.student_name ASC",
            $academic_year_id,
            $academic_year_id
        ));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // ASSIGNMENTS
    // ──────────────────────────────────────────────────────────────────────────

    public static function assign_student($hall_id, $student_id, $academic_year_id, $semester_id = 0, $seat_number = null)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_exam_hall_assignments';

        $student = $wpdb->get_row($wpdb->prepare(
            "SELECT student_uid FROM {$wpdb->prefix}olama_students WHERE id = %d",
            $student_id
        ));
        $uid = $student ? $student->student_uid : '';

        $hall  = self::get_hall($hall_id);
        $count = self::get_hall_count($hall_id, $academic_year_id, $semester_id);
        if ($hall && $count >= $hall->capacity) {
            return new WP_Error('capacity_exceeded', sprintf(
                __('Hall "%s" has reached its capacity of %d.', 'olama-school'),
                $hall->hall_name,
                $hall->capacity
            ));
        }

        // Remove from any existing hall (same year+semester)
        $wpdb->delete($table, [
            'student_id'       => $student_id,
            'academic_year_id' => $academic_year_id,
            'semester_id'      => $semester_id,
        ], ['%d', '%d', '%d']);

        return $wpdb->insert($table, [
            'hall_id'          => $hall_id,
            'student_id'       => $student_id,
            'student_uid'      => $uid,
            'academic_year_id' => $academic_year_id,
            'semester_id'      => $semester_id,
            'seat_number'      => $seat_number,
            'assigned_by'      => get_current_user_id(),
        ], ['%d', '%d', '%s', '%d', '%d', '%d', '%d']);
    }

    public static function remove_student($student_id, $academic_year_id, $semester_id = 0)
    {
        global $wpdb;
        $where  = ['student_id' => $student_id, 'academic_year_id' => $academic_year_id];
        $format = ['%d', '%d'];
        if ($semester_id) {
            $where['semester_id'] = $semester_id;
            $format[] = '%d';
        }
        return $wpdb->delete($wpdb->prefix . 'olama_exam_hall_assignments', $where, $format);
    }

    public static function get_hall_count($hall_id, $academic_year_id, $semester_id = 0)
    {
        global $wpdb;
        $sem = ($semester_id && self::has_semester_col())
            ? ' AND semester_id = ' . intval($semester_id)
            : '';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}olama_exam_hall_assignments
             WHERE hall_id = %d AND academic_year_id = %d $sem",
            $hall_id,
            $academic_year_id
        ));
    }

    /**
     * Clear assignments – can be scoped to specific students + halls.
     */
    public static function clear_assignments($academic_year_id, $semester_id = 0, $student_ids = [], $hall_ids = [])
    {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_exam_hall_assignments';

        $where  = 'academic_year_id = ' . intval($academic_year_id);
        if ($semester_id) $where .= ' AND semester_id = ' . intval($semester_id);

        if (!empty($student_ids)) {
            $ph    = implode(',', array_map('intval', $student_ids));
            $where .= " AND student_id IN ($ph)";
        }
        if (!empty($hall_ids)) {
            $ph    = implode(',', array_map('intval', $hall_ids));
            $where .= " AND hall_id IN ($ph)";
        }

        return $wpdb->query("DELETE FROM $table WHERE $where");
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AUTO-DISTRIBUTION ALGORITHM (canvas-aware)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Auto-distribute students across specific canvas halls.
     *
     * @param int   $year_id
     * @param int   $semester_id
     * @param array $hall_ids      Canvas hall IDs to distribute to (required)
     * @param int   $grade_id      Filter students by grade (optional)
     * @param int   $section_id    Filter students by section (optional)
     * @param bool  $clear         Clear existing canvas assignments first
     * @return array|WP_Error
     */
    public static function auto_distribute($year_id, $semester_id, $hall_ids, $grade_id = 0, $section_id = 0, $clear = false)
    {
        global $wpdb;
        $all_halls = self::get_halls($year_id);
        $halls     = [];
        
        $target_ids = array_map('intval', $hall_ids);
        foreach ($all_halls as $h) {
            if (in_array(intval($h->id), $target_ids)) {
                $halls[] = $h;
            }
        }

        if (empty($halls)) {
            return new WP_Error('no_halls', __('No valid halls found for distribution.', 'olama-school'));
        }

        // 2. Get students (grade/section filter)
        $extra_where = '';
        $extra_vals  = [];
        if ($section_id) {
            $extra_where .= ' AND e.section_id = %d';
            $extra_vals[] = intval($section_id);
        } elseif ($grade_id) {
            $extra_where .= ' AND sec.grade_id = %d';
            $extra_vals[] = intval($grade_id);
        }

        $students = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.student_uid, sec.grade_id, e.section_id,
                    g.grade_name, sec.section_name, s.student_name
             FROM {$wpdb->prefix}olama_students s
             JOIN {$wpdb->prefix}olama_student_enrollment e
                ON e.student_id = s.id AND e.academic_year_id = %d
             LEFT JOIN {$wpdb->prefix}olama_sections sec ON sec.id = e.section_id
             LEFT JOIN {$wpdb->prefix}olama_grades g ON g.id = sec.grade_id
             WHERE 1=1 $extra_where
             ORDER BY g.grade_name ASC, sec.section_name ASC, s.student_name ASC",
            array_merge([intval($year_id)], $extra_vals)
        ));

        if (empty($students)) {
            return new WP_Error('no_students', __('No students found for the selected grade/section.', 'olama-school'));
        }

        // 3. Capacity check
        $total_capacity = array_sum(array_column($halls, 'capacity'));
        if ($total_capacity < count($students)) {
            return new WP_Error('insufficient_capacity', sprintf(
                __('Total hall capacity (%d) is less than student count (%d). Add more halls or increase capacity.', 'olama-school'),
                $total_capacity,
                count($students)
            ));
        }

        // 4. Clear existing for these students in these halls
        if ($clear) {
            $student_ids = array_column($students, 'id');
            self::clear_assignments($year_id, $semester_id, $student_ids, array_map('intval', $hall_ids));
        }

        // 5. Round-robin distribution
        $hall_pool   = [];
        $hall_counts = [];
        foreach ($halls as $h) {
            $hall_pool[$h->id]   = $h;
            $hall_counts[$h->id] = $clear ? 0 : self::get_hall_count($h->id, $year_id, $semester_id);
        }

        $hall_ids_ordered = array_keys($hall_pool);
        $n_halls          = count($hall_ids_ordered);
        $pointer          = 0;
        $assigned         = 0;
        $failed           = 0;
        $table            = $wpdb->prefix . 'olama_exam_hall_assignments';
        $user_id          = get_current_user_id();

        $use_semester = $semester_id && self::has_semester_col();

        foreach ($students as $student) {
            $tried = 0;
            while ($tried < $n_halls) {
                $hid = $hall_ids_ordered[$pointer % $n_halls];
                $cap = $hall_pool[$hid]->capacity;

                if ($hall_counts[$hid] < $cap) {
                    $seat = $hall_counts[$hid] + 1;

                    // Build INSERT data without semester_id if column is missing
                    $insert_data   = [
                        'hall_id'          => $hid,
                        'student_id'       => $student->id,
                        'student_uid'      => $student->student_uid,
                        'academic_year_id' => $year_id,
                        'seat_number'      => $seat,
                        'assigned_by'      => $user_id,
                    ];
                    $insert_format = ['%d', '%d', '%s', '%d', '%d', '%d'];
                    if ($use_semester) {
                        $insert_data['semester_id'] = $semester_id;
                        $insert_format[]            = '%d';
                    }

                    $ok = $wpdb->insert($table, $insert_data, $insert_format);

                    if ($ok !== false) {
                        $hall_counts[$hid]++;
                        $pointer++;
                        $assigned++;
                    } else {
                        // INSERT failed (e.g. duplicate) – try next hall
                        $pointer++;
                        $tried++;
                        continue;
                    }
                    break;
                }

                $pointer++;
                $tried++;
            }

            if ($tried >= $n_halls) {
                $failed++;
            }
        }

        // 6. Return fresh canvas assignments
        $assignments = self::get_canvas_assignments($year_id, $semester_id, array_map('intval', $hall_ids), $grade_id, $section_id);
        $unassigned  = self::get_canvas_unassigned($year_id, $semester_id, $grade_id, $section_id, array_map('intval', $hall_ids));

        return [
            'assigned'    => $assigned,
            'failed'      => $failed,
            'halls'       => count($halls),
            'assignments' => $assignments,
            'unassigned'  => $unassigned,
            'message'     => sprintf(
                __('Distributed %d students across %d halls. %d unassigned.', 'olama-school'),
                $assigned,
                count($halls),
                $failed
            ),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // ATTENDANCE
    // ──────────────────────────────────────────────────────────────────────────

    public static function get_attendance($hall_id, $exam_date, $session_label = '', $semester_id = 0)
    {
        global $wpdb;
        $sem   = $semester_id ? $wpdb->prepare(' AND semester_id = %d', $semester_id) : '';
        $sql   = $wpdb->prepare(
            "SELECT student_id, status FROM {$wpdb->prefix}olama_exam_hall_attendance
             WHERE hall_id = %d AND exam_date = %s AND session_label = %s $sem",
            $hall_id, $exam_date, $session_label
        );
        $rows  = $wpdb->get_results($sql);
        
        error_log("[EH Load] Hall: $hall_id, Date: $exam_date, Session: $session_label, Sem: $semester_id");
        error_log("[EH Load] Query: $sql");
        error_log("[EH Load] Found: " . count($rows) . " records");

        $map = [];
        foreach ($rows as $r) {
            $map[$r->student_id] = $r->status;
        }
        return $map;
    }

    public static function save_attendance($hall_id, $exam_date, $session_label, $status_map, $semester_id = 0, $year_id = 0)
    {
        // NOTE: Do NOT call maybe_migrate() here — it runs DDL (ALTER TABLE) which
        // causes an implicit COMMIT in MySQL and breaks any outer transaction.
        // maybe_migrate() is called once by the AJAX handler BEFORE the transaction starts.
        global $wpdb;
        $table   = $wpdb->prefix . 'olama_exam_hall_attendance';
        $user_id = get_current_user_id();

        // Ensure we have a valid year_id (default to 0 if not provided)
        $year_id     = intval($year_id);
        $semester_id = intval($semester_id);

        error_log("[EH Save] Hall: $hall_id, Date: $exam_date, Session: $session_label, Year: $year_id, Sem: $semester_id, Count: " . count($status_map));

        // Check if academic_year_id column exists (might not on some production DBs)
        $has_year_col = !empty($wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'academic_year_id'"));
        $has_sem_col  = !empty($wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'semester_id'"));

        foreach ($status_map as $student_id => $status) {
            $student_id = intval($student_id);
            $status     = in_array($status, ['present', 'absent']) ? $status : 'present';

            $uid = $wpdb->get_var($wpdb->prepare(
                "SELECT student_uid FROM {$wpdb->prefix}olama_students WHERE id = %d",
                $student_id
            ));

            if ($has_year_col && $has_sem_col) {
                $result = $wpdb->query($wpdb->prepare(
                    "INSERT INTO $table
                        (hall_id, student_id, student_uid, academic_year_id, semester_id, exam_date, session_label, status, recorded_by)
                     VALUES (%d, %d, %s, %d, %d, %s, %s, %s, %d)
                     ON DUPLICATE KEY UPDATE status = VALUES(status), recorded_by = VALUES(recorded_by)",
                    $hall_id, $student_id, $uid ?? '', $year_id, $semester_id, $exam_date, $session_label, $status, $user_id
                ));
            } else {
                // Fallback: insert without year/semester columns (older schema)
                $result = $wpdb->query($wpdb->prepare(
                    "INSERT INTO $table
                        (hall_id, student_id, student_uid, exam_date, session_label, status, recorded_by)
                     VALUES (%d, %d, %s, %s, %s, %s, %d)
                     ON DUPLICATE KEY UPDATE status = VALUES(status), recorded_by = VALUES(recorded_by)",
                    $hall_id, $student_id, $uid ?? '', $exam_date, $session_label, $status, $user_id
                ));
            }

            if ($wpdb->last_error) {
                error_log("[EH Save Error] SID $student_id: " . $wpdb->last_error);
                // Don't throw — log and continue so partial saves still work
            } else {
                error_log("[EH Save OK] SID $student_id => $status (affected: $result)");
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // BEHAVIOR NOTES
    // ──────────────────────────────────────────────────────────────────────────

    public static function get_notes($hall_id, $exam_date = null, $semester_id = 0)
    {
        global $wpdb;
        $sql  = "SELECT n.*, s.student_name
                 FROM {$wpdb->prefix}olama_exam_hall_notes n
                 JOIN {$wpdb->prefix}olama_students s ON s.id = n.student_id
                 WHERE n.hall_id = %d";
        $vals = [$hall_id];

        if ($exam_date) {
            $sql   .= ' AND n.exam_date = %s';
            $vals[] = $exam_date;
        }
        if ($semester_id) {
            $sql   .= ' AND n.semester_id = %d';
            $vals[] = intval($semester_id);
        }
        $sql .= ' ORDER BY n.created_at DESC';
        return $wpdb->get_results($wpdb->prepare($sql, $vals));
    }

    public static function save_note($data)
    {
        self::maybe_migrate();
        global $wpdb;
        return $wpdb->insert(
            $wpdb->prefix . 'olama_exam_hall_notes',
            [
                'hall_id'     => intval($data['hall_id']),
                'student_id'  => intval($data['student_id']),
                'student_uid' => sanitize_text_field($data['student_uid'] ?? ''),
                'exam_date'   => sanitize_text_field($data['exam_date']),
                'semester_id' => intval($data['semester_id'] ?? 0),
                'note_type'   => sanitize_text_field($data['note_type'] ?? 'ملتزم'),
                'note_text'   => sanitize_textarea_field($data['note_text'] ?? ''),
                'recorded_by' => get_current_user_id(),
            ],
            ['%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d']
        );
    }

    public static function delete_note($note_id)
    {
        global $wpdb;
        return $wpdb->delete(
            $wpdb->prefix . 'olama_exam_hall_notes',
            ['id' => intval($note_id)],
            ['%d']
        );
    }

    public static function get_note_types()
    {
        return [
            'ملتزم' => 'ملتزم (Compliant)',
            'مخالف' => 'مخالف (Violation)',
            'متميز' => 'متميز (Excellent)',
            'غائب'  => 'غائب (Absent)',
            'أخرى'  => 'أخرى (Other)',
        ];
    }
    // ──────────────────────────────────────────────────────────────────────────
    // INVIGILATORS (المراقبين)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Get invigilators assigned to a specific hall.
     */
    public static function get_hall_invigilators($hall_id, $year_id, $semester_id = 0)
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT i.*, u.display_name 
             FROM {$wpdb->prefix}olama_exam_hall_invigilators i
             JOIN {$wpdb->users} u ON u.ID = i.invigilator_id
             WHERE i.hall_id = %d AND i.academic_year_id = %d AND i.semester_id = %d",
            $hall_id,
            $year_id,
            $semester_id
        ));
    }

    /**
     * Assign an invigilator to a hall.
     */
    public static function assign_invigilator($hall_id, $invigilator_id, $year_id, $semester_id = 0)
    {
        self::maybe_migrate();
        global $wpdb;
        $table = $wpdb->prefix . 'olama_exam_hall_invigilators';

        // 1. Check if already in another hall
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT hall_id FROM $table WHERE invigilator_id = %d AND academic_year_id = %d AND semester_id = %d",
            $invigilator_id,
            $year_id,
            $semester_id
        ));

        if ($existing) {
            if ($existing == $hall_id) return true; // Already there
            $hall = self::get_hall($existing);
            return new WP_Error('already_assigned', sprintf(
                __('Invigilator is already assigned to hall "%s".', 'olama-school'),
                $hall ? $hall->hall_name : '#' . $existing
            ));
        }

        // 2. Check hall limit (up to 3)
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE hall_id = %d AND academic_year_id = %d AND semester_id = %d",
            $hall_id,
            $year_id,
            $semester_id
        ));

        if ($count >= 3) {
            return new WP_Error('limit_reached', __('Maximum 3 invigilators allowed per hall.', 'olama-school'));
        }

        // 3. Insert
        return $wpdb->insert($table, [
            'hall_id'          => $hall_id,
            'invigilator_id'   => $invigilator_id,
            'academic_year_id' => $year_id,
            'semester_id'      => $semester_id,
            'assigned_by'      => get_current_user_id()
        ], ['%d', '%d', '%d', '%d', '%d']);
    }

    /**
     * Remove an invigilator from a hall.
     */
    public static function remove_invigilator($hall_id, $invigilator_id, $year_id, $semester_id = 0)
    {
        global $wpdb;
        return $wpdb->delete($wpdb->prefix . 'olama_exam_hall_invigilators', [
            'hall_id'          => $hall_id,
            'invigilator_id'   => $invigilator_id,
            'academic_year_id' => $year_id,
            'semester_id'      => $semester_id,
        ], ['%d', '%d', '%d', '%d']);
    }

    /**
     * Get all assigned invigilators for the context (to highlight or filter).
     */
    public static function get_all_assigned_invigilators($year_id, $semester_id = 0)
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT i.invigilator_id, i.hall_id, u.display_name 
             FROM {$wpdb->prefix}olama_exam_hall_invigilators i
             JOIN {$wpdb->users} u ON u.ID = i.invigilator_id
             WHERE i.academic_year_id = %d AND i.semester_id = %d",
            $year_id,
            $semester_id
        ));
    }

    /**
     * Update seat numbers for a set of students in a hall.
     */
    public static function update_seat_numbers($hall_id, $student_ids, $academic_year_id, $semester_id = 0)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_exam_hall_assignments';
        
        foreach ($student_ids as $index => $student_id) {
            $seat_number = $index + 1;
            $wpdb->update($table, 
                ['seat_number' => $seat_number],
                [
                    'hall_id' => $hall_id,
                    'student_id' => $student_id,
                    'academic_year_id' => $academic_year_id,
                    'semester_id' => $semester_id
                ],
                ['%d'],
                ['%d', '%d', '%d', '%d']
            );
        }
        return true;
    }
}

