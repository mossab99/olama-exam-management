<?php
/**
 * Exam Management Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_School_Exam
{
    /**
     * Determine whether an exam is assigned to a synchronized teacher.
     *
     * The WordPress user ID is the primary relationship. The employee ID is
     * also checked so assignments survive account reprovisioning.
     */
    public static function is_assigned_to_teacher($exam_id, $user_id = 0)
    {
        global $wpdb;

        $exam_id = absint($exam_id);
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        if (!$exam_id || !$user_id) {
            return false;
        }

        $employee_id = '';
        $profile_table = $wpdb->prefix . 'olama_core_staff_profiles';
        $profile_pattern = $wpdb->esc_like($profile_table);
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $profile_pattern)) === $profile_table) {
            $employee_id = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT employee_id FROM {$profile_table} WHERE user_id = %d LIMIT 1",
                $user_id
            ));
        }

        $query = "SELECT 1
                  FROM {$wpdb->prefix}olama_exams e
                  INNER JOIN {$wpdb->prefix}olama_teacher_assignments ta
                    ON ta.academic_year_id = e.academic_year_id
                   AND ta.subject_id = e.subject_id
                  WHERE e.id = %d
                    AND (ta.teacher_id = %d";
        $params = array($exam_id, $user_id);

        if ('' !== $employee_id) {
            $query .= ' OR ta.teacher_employee_id = %s';
            $params[] = $employee_id;
        }

        $query .= ') LIMIT 1';
        return (bool) $wpdb->get_var($wpdb->prepare($query, $params));
    }

    /**
     * Central authorization rule for teacher-facing exam operations.
     */
    public static function current_user_can_access_exam($exam_id, $capability = 'olama_fill_exam_details')
    {
        if (Olama_School_Permissions::can('olama_manage_exams_schedule')) {
            return true;
        }

        return Olama_School_Permissions::can($capability)
            && self::is_assigned_to_teacher($exam_id, get_current_user_id());
    }

    /**
     * Get student specific exams (Direct Resolver)
     */
    public static function get_student_specific_exams($student_uid)
    {
        global $wpdb;

        // 1. Get active year
        $active_year = Olama_School_Academic::get_active_year();
        if (!$active_year) return null;
        $year_id = $active_year->id;

        // 2. Get student enrollment context
        $enrollment = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, s.student_name, sec.grade_id 
             FROM {$wpdb->prefix}olama_student_enrollment e 
             JOIN {$wpdb->prefix}olama_students s ON e.student_uid = s.student_uid
             JOIN {$wpdb->prefix}olama_sections sec ON e.section_id = sec.id
             WHERE s.student_uid = %s AND e.academic_year_id = %d",
            $student_uid, $year_id
        ));

        if (!$enrollment) return null;

        // 3. Get active semester
        $active_sem = Olama_School_Academic::get_active_semester($year_id);
        if (!$active_sem) return null;
        $semester_id = $active_sem->id;

        // 4. Get active Master Exam (if any)
        $active_master = Olama_School_Academic::get_active_exam($semester_id);
        $semester_exam_id = $active_master ? $active_master->id : 0;

        // 5. Fetch exams for this grade
        $all_grade_exams = self::get_exams($year_id, $semester_id, $enrollment->grade_id, 0, $semester_exam_id);

        // 6. Filter by section subjects (ensure student actually takes these subjects)
        $section_subjects = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT subject_id FROM {$wpdb->prefix}olama_teacher_assignments 
             WHERE section_id = %d AND academic_year_id = %d",
            $enrollment->section_id, $year_id
        ));

        if (!empty($section_subjects)) {
            $filtered_exams = array_filter($all_grade_exams, function($e) use ($section_subjects) {
                return in_array($e->subject_id, $section_subjects);
            });
            $exams = array_values($filtered_exams);
        } else {
            $exams = $all_grade_exams;
        }

        return array(
            'student_name' => $enrollment->student_name,
            'grade_id'     => $enrollment->grade_id,
            'section_id'   => $enrollment->section_id,
            'year_id'      => $year_id,
            'semester_id'  => $semester_id,
            'semester_exam_id' => $semester_exam_id,
            'exams'        => $exams
        );
    }

    /**
     * Get exams based on filters
     */
    public static function get_exams($year_id, $semester_id, $grade_id, $subject_id = 0, $semester_exam_id = 0)
    {
        global $wpdb;
        $query = "SELECT e.*, s.subject_name, se.room_number as master_room, a.id as attachment_id, a.file_status as attachment_status 
                  FROM {$wpdb->prefix}olama_exams e
                  LEFT JOIN {$wpdb->prefix}olama_subjects s ON e.subject_id = s.id
                  LEFT JOIN {$wpdb->prefix}olama_semester_exams se ON e.semester_exam_id = se.id
                  LEFT JOIN {$wpdb->prefix}olama_exam_attachments a ON e.id = a.exam_id
                  WHERE e.academic_year_id = %d AND e.semester_id = %d AND e.grade_id = %d";
        $params = array($year_id, $semester_id, $grade_id);

        if ($subject_id > 0) {
            $query .= " AND e.subject_id = %d";
            $params[] = $subject_id;
        }

        if ($semester_exam_id > 0) {
            $query .= " AND e.semester_exam_id = %d";
            $params[] = $semester_exam_id;
        }

        $query .= " ORDER BY e.exam_date ASC";

        return $wpdb->get_results($wpdb->prepare($query, $params));
    }

    /**
     * Save an exam
     */
    public static function save_exam($data)
    {
        global $wpdb;

        $exam_id = !empty($data['id']) ? intval($data['id']) : 0;

        if (!Olama_School_Permissions::can('olama_manage_exams_schedule')) {
            if (!self::current_user_can_access_exam($exam_id, 'olama_fill_exam_details')) {
                return new WP_Error('unauthorized_exam', __('You are not assigned to this exam.', 'olama-exam-management'));
            }

            // Teachers may fill teaching material only. Schedule context,
            // approval state, and supervisor fields remain manager-owned.
            $teacher_fields = array(
                'id',
                'description',
                'student_book_material',
                'workbook_material',
                'exercise_book_material',
                'notebook_material',
                'teacher_notes',
                'exam_material_json',
            );
            $data = array_intersect_key($data, array_fill_keys($teacher_fields, true));
            $data['id'] = $exam_id;
        }

        $existing = null;
        if ($exam_id) {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}olama_exams WHERE id = %d", $exam_id), ARRAY_A);
        }

        $fields = array();
        $keys = array(
            'academic_year_id' => 'intval',
            'semester_id' => 'intval',
            'semester_exam_id' => 'intval',
            'grade_id' => 'intval',
            'subject_id' => 'intval',
            'evaluation_type' => 'sanitize_text_field',
            'exam_date' => 'sanitize_text_field',
            'room_number' => 'sanitize_text_field',
            'description' => 'sanitize_textarea_field',
            'student_book_material' => 'sanitize_textarea_field',
            'workbook_material' => 'sanitize_textarea_field',
            'exercise_book_material' => 'sanitize_textarea_field',
            'notebook_material' => 'sanitize_textarea_field',
            'teacher_notes' => 'sanitize_textarea_field',
            'supervisor_comments' => 'sanitize_textarea_field',
            'exam_material_json' => 'wp_unslash', // JSON data, needs special handling
            'status' => 'sanitize_text_field'
        );

        // Fetch existing data if needed for validation
        $val_date = isset($data['exam_date']) ? $data['exam_date'] : ($existing ? $existing['exam_date'] : '');
        $val_grade = isset($data['grade_id']) ? $data['grade_id'] : ($existing ? $existing['grade_id'] : 0);
        $val_status = isset($data['status']) ? $data['status'] : ($existing ? $existing['status'] : 'draft');

        // Date validation: Relaxed conflict check
        if ($val_date && $val_grade) {
            $sanitized_val_date = Olama_School_Helpers::sanitize_date($val_date);

            // Only block if BOTH the current save is for 'approved' status 
            // AND there is another 'approved' exam on the same day.
            if ($val_status === 'approved') {
                $query = "SELECT id FROM {$wpdb->prefix}olama_exams 
                         WHERE grade_id = %d AND exam_date = %s AND id != %d AND status = 'approved'";
                $conflict = $wpdb->get_var($wpdb->prepare($query, $val_grade, $sanitized_val_date, $exam_id));

                if ($conflict) {
                    return new WP_Error('duplicate_date', Olama_School_Helpers::translate('This grade already has an approved exam on this date. Please change the date before approving.'));
                }
            }
        }

        foreach ($keys as $key => $sanitizer) {
            if (isset($data[$key])) {
                $value = $data[$key];
                if ($key === 'exam_material_json') {
                    // Handle JSON data with unslashing
                    if (is_string($value)) {
                        $value = wp_unslash($value);
                    }
                    $fields[$key] = is_string($value) ? $value : json_encode($value);
                } elseif ($sanitizer === 'intval') {
                    $fields[$key] = intval($value);
                } elseif (is_callable($sanitizer)) {
                    $fields[$key] = $sanitizer($value);
                } else {
                    $fields[$key] = $value;
                }
            } elseif ($existing && array_key_exists($key, $existing)) {
                $fields[$key] = $existing[$key];
            } elseif ($key === 'status') {
                $fields[$key] = 'draft';
            }
        }

        // Mandatory material validation: Only if transitioning to 'approved'
        $is_newly_approved = ($val_status === 'approved' && (!$existing || $existing['status'] !== 'approved'));
        $is_updating_full_material = isset($data['exam_material_json']) || isset($data['description']);

        if ($is_newly_approved || ($val_status === 'approved' && $is_updating_full_material)) {
            $desc = isset($fields['description']) ? trim($fields['description']) : '';
            $json_str = isset($fields['exam_material_json']) ? $fields['exam_material_json'] : '';
            $material = json_decode($json_str, true);

            // Check curriculum items in JSON
            $has_curriculum = !empty($material['curriculum_items']);

            // Check booklets and teacher notes
            $booklets = !empty($material['booklets_notebooks']) ? trim($material['booklets_notebooks']) : (isset($fields['notebook_material']) ? trim($fields['notebook_material']) : '');
            $notes = !empty($material['teacher_notes']) ? trim($material['teacher_notes']) : (isset($fields['teacher_notes']) ? trim($fields['teacher_notes']) : '');

            $missing = array();
            if (empty($desc)) {
                $missing[] = Olama_School_Helpers::translate('Exam Description');
            }
            if (!$has_curriculum) {
                $missing[] = Olama_School_Helpers::translate('Curriculum Material');
            }
            if (empty($booklets)) {
                $missing[] = Olama_School_Helpers::translate('Booklets & Notebooks');
            }
            if (empty($notes)) {
                $missing[] = Olama_School_Helpers::translate('Teacher Notes');
            }

            if (!empty($missing)) {
                return new WP_Error(
                    'missing_material',
                    Olama_School_Helpers::translate('Approval denied! Please fill the required fields first:') . ' ' . implode(', ', $missing)
                );
            }
        }

        if ($exam_id) {
            $result = $wpdb->update(
                "{$wpdb->prefix}olama_exams",
                $fields,
                array('id' => $exam_id)
            );

            // Sync attachment status if exam is approved/completed
            if ($result !== false && isset($fields['status'])) {
                $wpdb->update(
                    "{$wpdb->prefix}olama_exam_attachments",
                    array('file_status' => $fields['status']),
                    array('exam_id' => $exam_id)
                );
            }
            // Return true for 0 rows (no changes), check for actual errors
            if ($result === false) {
                error_log('Olama save_exam error: ' . $wpdb->last_error);
                return new WP_Error('db_error', $wpdb->last_error ?: 'Unknown database error');
            }
            return $result !== false ? true : $result;
        } else {
            $result = $wpdb->insert(
                "{$wpdb->prefix}olama_exams",
                $fields
            );
            if ($result === false) {
                error_log('Olama save_exam insert error: ' . $wpdb->last_error);
                return new WP_Error('db_error', $wpdb->last_error ?: 'Unknown database error');
            }
            return $result;
        }
    }

    /**
     * Get exams assigned to a teacher
     */
    public static function get_teacher_exams($teacher_id, $academic_year_id, $semester_exam_id)
    {
        global $wpdb;
        $teacher_id = absint($teacher_id);
        $employee_id = '';
        $profile_table = $wpdb->prefix . 'olama_core_staff_profiles';
        $profile_pattern = $wpdb->esc_like($profile_table);
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $profile_pattern)) === $profile_table) {
            $employee_id = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT employee_id FROM {$profile_table} WHERE user_id = %d LIMIT 1",
                $teacher_id
            ));
        }

        $query = "SELECT e.*, s.subject_name, g.grade_name, a.id as attachment_id, a.file_status as attachment_status 
                  FROM {$wpdb->prefix}olama_exams e
                  JOIN {$wpdb->prefix}olama_subjects s ON e.subject_id = s.id
                  JOIN {$wpdb->prefix}olama_grades g ON e.grade_id = g.id
                  LEFT JOIN {$wpdb->prefix}olama_exam_attachments a ON e.id = a.exam_id
                  WHERE e.academic_year_id = %d 
                  AND e.semester_exam_id = %d
                  AND e.subject_id IN (
                      SELECT DISTINCT subject_id 
                      FROM {$wpdb->prefix}olama_teacher_assignments 
                      WHERE (teacher_id = %d";
        $params = array($academic_year_id, $semester_exam_id, $teacher_id);
        if ('' !== $employee_id) {
            $query .= ' OR teacher_employee_id = %s';
            $params[] = $employee_id;
        }
        $query .= ") AND academic_year_id = %d
                  )
                  ORDER BY e.exam_date ASC";
        $params[] = $academic_year_id;

        return $wpdb->get_results($wpdb->prepare($query, $params));
    }

    /**
     * Delete an exam
     */
    public static function delete_exam($exam_id)
    {
        global $wpdb;
        return $wpdb->delete("{$wpdb->prefix}olama_exams", array('id' => intval($exam_id)));
    }

    /**
     * Get a single exam
     */
    public static function get_exam($exam_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}olama_exams WHERE id = %d",
            $exam_id
        ));
    }
    /**
     * Get an exam with all related names for descriptive filenames
     */
    public static function get_full_exam_details($exam_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, 
                    ay.year_name, 
                    s.semester_name, 
                    se.exam_name, 
                    g.grade_name, 
                    sb.subject_name
             FROM {$wpdb->prefix}olama_exams e
             LEFT JOIN {$wpdb->prefix}olama_academic_years ay ON e.academic_year_id = ay.id
             LEFT JOIN {$wpdb->prefix}olama_semesters s ON e.semester_id = s.id
             LEFT JOIN {$wpdb->prefix}olama_semester_exams se ON e.semester_exam_id = se.id
             LEFT JOIN {$wpdb->prefix}olama_grades g ON e.grade_id = g.id
             LEFT JOIN {$wpdb->prefix}olama_subjects sb ON e.subject_id = sb.id
             WHERE e.id = %d",
            $exam_id
        ));
    }
}
