<?php
/**
 * Exam Hall Distribution System – AJAX Handlers
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Exam_Hall_Ajax
{
    public function __construct()
    {
        $actions = [
            'olama_eh_get_students',
            'olama_eh_auto_distribute',
            'olama_eh_move_student',
            'olama_eh_remove_student',
            'olama_eh_save_hall',
            'olama_eh_delete_hall',
            'olama_eh_save_attendance',
            'olama_eh_save_note',
            'olama_eh_delete_note',
            'olama_eh_clear_context',
            'olama_eh_get_global_report',
            'olama_eh_get_invigilators',
            'olama_eh_assign_invigilator',
            'olama_eh_remove_invigilator',
            'olama_eh_sort_students',
        ];

        foreach ($actions as $action) {
            add_action('wp_ajax_' . $action, [$this, str_replace('olama_eh_', '', $action)]);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function check()
    {
        check_ajax_referer('olama_exam_hall_nonce', 'nonce');
        if (!Olama_School_Permissions::can('olama_access_exam_halls')) {
            wp_send_json_error(['message' => __('Unauthorized', 'olama-school')], 403);
        }
    }

    private function check_admin()
    {
        check_ajax_referer('olama_exam_hall_nonce', 'nonce');
        if (!Olama_School_Permissions::can('olama_manage_exam_halls')) {
            wp_send_json_error(['message' => __('Unauthorized', 'olama-school')], 403);
        }
    }

    private function year_id()
    {
        $id = intval($_POST['academic_year_id'] ?? 0);
        if (!$id) {
            $year = Olama_School_Academic::get_active_year();
            $id   = $year ? $year->id : 0;
        }
        return $id;
    }

    private function semester_id()
    {
        $id = intval($_POST['semester_id'] ?? 0);
        if (!$id) {
            global $wpdb;
            $year_id = $this->year_id();
            $id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_semesters
                 WHERE academic_year_id = %d AND is_active = 1 LIMIT 1",
                $year_id
            ));
        }
        return $id;
    }

    private function canvas_hall_ids()
    {
        $raw = $_POST['canvas_hall_ids'] ?? [];
        if (is_string($raw)) {
            $raw = array_filter(explode(',', $raw));
        }
        return array_map('intval', (array) $raw);
    }

    // ── get_students ─────────────────────────────────────────────────────────
    /**
     * Two modes:
     * 1. grade_id/section_id → context students + canvas assignments + unassigned list
     * 2. hall_id             → students assigned to that hall (for attendance/notes)
     */
    public function get_students()
    {
        $this->check();

        $year_id    = $this->year_id();
        $semester_id = $this->semester_id();
        $grade_id   = intval($_POST['grade_id']   ?? 0);
        $section_id = intval($_POST['section_id'] ?? 0);
        $hall_id    = intval($_POST['hall_id']    ?? 0);

        if ($hall_id) {
            // Mode 2: attendance/notes – return hall students
            $students = Olama_Exam_Hall::get_hall_students($hall_id, $year_id, $semester_id);
            
            $exam_date = sanitize_text_field($_POST['exam_date'] ?? '');
            $session_label = sanitize_text_field($_POST['session_label'] ?? '');
            if ($exam_date) {
                $attendance_map = Olama_Exam_Hall::get_attendance($hall_id, $exam_date, $session_label, $semester_id);
                foreach ($students as $student) {
                    $sid = $student->student_id;
                    $student->attendance_status = isset($attendance_map[$sid]) ? $attendance_map[$sid] : 'present';
                }
            }

            wp_send_json_success(['students' => $students]);
            return;
        }

        // Mode 1: distribution context
        $canvas_hall_ids = $this->canvas_hall_ids();

        $all_students = Olama_Exam_Hall::get_filtered_students($year_id, $semester_id, $grade_id, $section_id);
        $unassigned   = Olama_Exam_Hall::get_canvas_unassigned($year_id, $semester_id, $grade_id, $section_id, $canvas_hall_ids);
        $assignments  = Olama_Exam_Hall::get_canvas_assignments($year_id, $semester_id, $canvas_hall_ids, $grade_id, $section_id);

        // Get total occupancy for each canvas hall (across all grades)
        $hall_occupancy = [];
        if (!empty($canvas_hall_ids)) {
            foreach ($canvas_hall_ids as $hid) {
                $hall_occupancy[$hid] = Olama_Exam_Hall::get_hall_count($hid, $year_id, $semester_id);
            }
        }

        wp_send_json_success([
            'students'    => $all_students,
            'unassigned'  => $unassigned,
            'assignments' => $assignments,
            'occupancy'   => $hall_occupancy,
        ]);
    }

    // ── auto_distribute ───────────────────────────────────────────────────────
    public function auto_distribute()
    {
        $this->check_admin();

        $year_id     = $this->year_id();
        $semester_id = $this->semester_id();
        $grade_id    = intval($_POST['grade_id']    ?? 0);
        $section_id  = intval($_POST['section_id']  ?? 0);
        $clear       = !empty($_POST['clear_existing']);
        $hall_ids    = $this->canvas_hall_ids();

        if (empty($hall_ids)) {
            wp_send_json_error(['message' => __('No canvas halls selected.', 'olama-school')]);
        }

        $result = Olama_Exam_Hall::auto_distribute($year_id, $semester_id, $hall_ids, $grade_id, $section_id, $clear);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        // Return new occupancy counts
        $occupancy = [];
        foreach ($hall_ids as $hid) {
            $occupancy[$hid] = Olama_Exam_Hall::get_hall_count($hid, $year_id, $semester_id);
        }

        wp_send_json_success([
            'message'     => $result['message'] ?? __('Students distributed successfully.', 'olama-school'),
            'occupancy'   => $occupancy,
            'assignments' => $result['assignments'] ?? [],
            'unassigned'  => $result['unassigned'] ?? [],
        ]);
    }

    // ── move_student ──────────────────────────────────────────────────────────
    public function move_student()
    {
        $this->check_admin();

        $year_id     = $this->year_id();
        $semester_id = $this->semester_id();
        $hall_id     = intval($_POST['hall_id']    ?? 0);
        $student_id  = intval($_POST['student_id'] ?? 0);

        if (!$hall_id || !$student_id) {
            wp_send_json_error(['message' => __('Missing hall or student.', 'olama-school')]);
        }

        $result = Olama_Exam_Hall::assign_student($hall_id, $student_id, $year_id, $semester_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        // Return new occupancy for the target hall
        $count = Olama_Exam_Hall::get_hall_count($hall_id, $year_id, $semester_id);

        wp_send_json_success([
            'message' => __('Student moved successfully.', 'olama-school'),
            'occupancy' => [$hall_id => $count]
        ]);
    }

    // ── remove_student ────────────────────────────────────────────────────────
    public function remove_student()
    {
        $this->check_admin();

        $year_id     = $this->year_id();
        $semester_id = $this->semester_id();
        $student_id  = intval($_POST['student_id'] ?? 0);

        if (!$student_id) {
            wp_send_json_error(['message' => __('Missing student.', 'olama-school')]);
        }

        Olama_Exam_Hall::remove_student($student_id, $year_id, $semester_id);
        wp_send_json_success(['message' => __('Student removed.', 'olama-school')]);
    }

    // ── clear_context ─────────────────────────────────────────────────────────
    public function clear_context()
    {
        $this->check_admin();

        $year_id     = $this->year_id();
        $semester_id = $this->semester_id();
        $grade_id    = intval($_POST['grade_id']   ?? 0);
        $section_id  = intval($_POST['section_id'] ?? 0);
        $hall_ids    = $this->canvas_hall_ids();

        // Clear ALL students from these halls (regardless of grade/section) to make them empty for re-distribution
        Olama_Exam_Hall::clear_assignments($year_id, $semester_id, [], $hall_ids);

        // Get fresh state for the canvas
        $assignments = Olama_Exam_Hall::get_canvas_assignments($year_id, $semester_id, $hall_ids, $grade_id, $section_id);
        $unassigned  = Olama_Exam_Hall::get_canvas_unassigned($year_id, $semester_id, $grade_id, $section_id, $hall_ids);

        // Return new occupancy counts
        $occupancy = [];
        foreach ($hall_ids as $hid) {
            $occupancy[$hid] = Olama_Exam_Hall::get_hall_count($hid, $year_id, $semester_id);
        }

        wp_send_json_success([
            'message'     => __('All assignments cleared.', 'olama-school'),
            'occupancy'   => $occupancy,
            'assignments' => $assignments,
            'unassigned'  => $unassigned,
        ]);
    }

    // ── save_hall ─────────────────────────────────────────────────────────────
    public function save_hall()
    {
        $this->check_admin();

        $year_id = $this->year_id();
        $result  = Olama_Exam_Hall::save_hall(array_merge($_POST, ['academic_year_id' => $year_id]));

        if ($result === false) {
            wp_send_json_error(['message' => __('Failed to save hall.', 'olama-school')]);
        }

        $halls = Olama_Exam_Hall::get_halls($year_id);
        wp_send_json_success([
            'message' => __('Hall saved successfully.', 'olama-school'),
            'halls'   => $halls,
        ]);
    }

    // ── delete_hall ───────────────────────────────────────────────────────────
    public function delete_hall()
    {
        $this->check_admin();

        $hall_id = intval($_POST['hall_id'] ?? 0);
        if (!$hall_id) {
            wp_send_json_error(['message' => __('Invalid hall.', 'olama-school')]);
        }

        Olama_Exam_Hall::delete_hall($hall_id);

        $year_id = $this->year_id();
        $halls   = Olama_Exam_Hall::get_halls($year_id);

        wp_send_json_success([
            'message' => __('Hall deleted.', 'olama-school'),
            'halls'   => $halls,
        ]);
    }

    // ── save_attendance ───────────────────────────────────────────────────────
    public function save_attendance()
    {
        $this->check();
        if (!Olama_School_Permissions::can('olama_manage_hall_attendance')) {
            wp_send_json_error(['message' => __('Unauthorized', 'olama-school')], 403);
        }

        // Run schema migration BEFORE starting any transaction.
        // DDL statements (ALTER TABLE) cause implicit COMMIT in MySQL,
        // so they must never run inside an open transaction.
        Olama_Exam_Hall::maybe_migrate();

        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $year_id     = $this->year_id();
            $semester_id = $this->semester_id();
            $hall_id     = intval($_POST['hall_id']       ?? 0);
            $exam_date   = sanitize_text_field($_POST['exam_date']     ?? date('Y-m-d'));
            $session     = sanitize_text_field($_POST['session_label'] ?? '');
            $statuses    = $_POST['statuses'] ?? [];

            error_log("[EH Ajax] Save attempt - Hall: $hall_id, Date: $exam_date, Session: $session, Year: $year_id, Sem: $semester_id, Statuses count: " . count($statuses));

            if (!$hall_id || empty($statuses)) {
                throw new Exception(__('Missing hall ID or attendance data.', 'olama-school'));
            }

            Olama_Exam_Hall::save_attendance($hall_id, $exam_date, $session, $statuses, $semester_id, $year_id);
            $wpdb->query('COMMIT');
            error_log("[EH Ajax Success] Attendance save committed for Hall $hall_id");
            wp_send_json_success(['message' => __('Attendance saved successfully.', 'olama-school')]);
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log("[EH Ajax Error] " . $e->getMessage());
            wp_send_json_error(['message' => __('Failed to save attendance: ', 'olama-school') . $e->getMessage()]);
        }
    }

    // ── save_note ─────────────────────────────────────────────────────────────
    public function save_note()
    {
        $this->check();
        if (!Olama_School_Permissions::can('olama_manage_hall_attendance')) {
            wp_send_json_error(['message' => __('Unauthorized', 'olama-school')], 403);
        }

        $semester_id = $this->semester_id();
        $result = Olama_Exam_Hall::save_note(array_merge($_POST, ['semester_id' => $semester_id]));

        if ($result === false) {
            wp_send_json_error(['message' => __('Failed to save note.', 'olama-school')]);
        }

        $hall_id = intval($_POST['hall_id'] ?? 0);
        $notes   = Olama_Exam_Hall::get_notes($hall_id, null, $semester_id);

        wp_send_json_success([
            'message' => __('Note saved.', 'olama-school'),
            'notes'   => $notes,
        ]);
    }

    // ── delete_note ───────────────────────────────────────────────────────────
    public function delete_note()
    {
        $this->check_admin();

        $note_id = intval($_POST['note_id'] ?? 0);
        Olama_Exam_Hall::delete_note($note_id);

        wp_send_json_success(['message' => __('Note deleted.', 'olama-school')]);
    }

    // ── get_global_report ───────────────────────────────────────────────────
    public function get_global_report()
    {
        $this->check();
        $year_id     = $this->year_id();
        $semester_id = $this->semester_id();

        $assignments = Olama_Exam_Hall::get_all_assignments($year_id, $semester_id);
        wp_send_json_success(['assignments' => $assignments]);
    }

    // ── INVIGILATORS ─────────────────────────────────────────────────────────

    public function get_invigilators()
    {
        $this->check();
        $year_id     = $this->year_id();
        $semester_id = $this->semester_id();
        $hall_id     = intval($_POST['hall_id'] ?? 0);

        $available    = Olama_School_Teacher::get_teachers();
        $assigned     = Olama_Exam_Hall::get_hall_invigilators($hall_id, $year_id, $semester_id);
        $all_assigned = Olama_Exam_Hall::get_all_assigned_invigilators($year_id, $semester_id);

        wp_send_json_success([
            'available'    => $available,
            'assigned'     => $assigned,
            'all_assigned' => $all_assigned,
        ]);
    }

    public function assign_invigilator()
    {
        $this->check_admin();
        $year_id        = $this->year_id();
        $semester_id    = $this->semester_id();
        $hall_id        = intval($_POST['hall_id'] ?? 0);
        $invigilator_id = intval($_POST['invigilator_id'] ?? 0);

        if (!$hall_id || !$invigilator_id) {
            wp_send_json_error(['message' => __('Missing data.', 'olama-school')]);
        }

        $result = Olama_Exam_Hall::assign_invigilator($hall_id, $invigilator_id, $year_id, $semester_id);

        if (is_wp_error($result) || $result === false) {
            $msg = is_wp_error($result) ? $result->get_error_message() : __('Database error: could not assign invigilator.', 'olama-school');
            wp_send_json_error(['message' => $msg]);
        }

        $assigned = Olama_Exam_Hall::get_hall_invigilators($hall_id, $year_id, $semester_id);
        wp_send_json_success([
            'message'  => __('Invigilator assigned.', 'olama-school'),
            'assigned' => $assigned
        ]);
    }

    public function remove_invigilator()
    {
        $this->check_admin();
        $year_id        = $this->year_id();
        $semester_id    = $this->semester_id();
        $hall_id        = intval($_POST['hall_id'] ?? 0);
        $invigilator_id = intval($_POST['invigilator_id'] ?? 0);

        if (!$hall_id || !$invigilator_id) {
            wp_send_json_error(['message' => __('Missing data.', 'olama-school')]);
        }

        Olama_Exam_Hall::remove_invigilator($hall_id, $invigilator_id, $year_id, $semester_id);

        $assigned = Olama_Exam_Hall::get_hall_invigilators($hall_id, $year_id, $semester_id);
        wp_send_json_success([
            'message'  => __('Invigilator removed.', 'olama-school'),
            'assigned' => $assigned
        ]);
    }

    public function sort_students()
    {
        $this->check_admin();
        $year_id     = $this->year_id();
        $semester_id = $this->semester_id();
        $hall_id     = intval($_POST['hall_id'] ?? 0);
        $student_ids = $_POST['student_ids'] ?? [];

        if (!$hall_id || empty($student_ids)) {
            wp_send_json_error(['message' => __('Missing data.', 'olama-school')]);
        }

        Olama_Exam_Hall::update_seat_numbers($hall_id, $student_ids, $year_id, $semester_id);

        wp_send_json_success(['message' => __('Order saved.', 'olama-school')]);
    }
}

