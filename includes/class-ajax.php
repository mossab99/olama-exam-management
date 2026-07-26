<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Exam_Management_Ajax
{
    public function __construct()
    {
        add_action('wp_ajax_olama_save_exam', array($this, 'save_exam'));
        add_action('wp_ajax_olama_bulk_add_exam_subjects', array($this, 'bulk_add_exam_subjects'));
        add_action('wp_ajax_olama_upload_exam_file', array($this, 'upload_exam_file'));
        add_action('wp_ajax_olama_download_exam_file', array($this, 'download_exam_file'));
        add_action('wp_ajax_olama_get_exam_attachment', array($this, 'get_exam_attachment'));
        add_action('wp_ajax_olama_delete_exam_attachment', array($this, 'delete_exam_attachment'));
        add_action('wp_ajax_olama_save_exam_attachment_comment', array($this, 'save_exam_attachment_comment'));
        add_action('wp_ajax_olama_download_all_exams_zip', array($this, 'download_all_exams_zip'));
        add_action('wp_ajax_olama_get_semester_exams', array($this, 'get_semester_exams'));
    }

    public function save_exam()
    {
        $nonce = $_POST['nonce'] ?? ($_POST['olama_exam_nonce_field'] ?? ($_POST['olama_material_nonce_field'] ?? ''));
        if (!$nonce || !wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), 'olama_save_exam')) {
            wp_send_json_error(__('Session expired or security check failed. Please refresh the page and try again.', 'olama-exam-management'));
        }
        if (!Olama_School_Permissions::can('olama_manage_exams_schedule') && !Olama_School_Permissions::can('olama_fill_exam_details')) {
            wp_send_json_error(__('Permission denied.', 'olama-exam-management'), 403);
        }
        $is_manager = Olama_School_Permissions::can('olama_manage_exams_schedule');
        if ($is_manager && (empty($_POST['academic_year_id']) || empty($_POST['semester_id']) || empty($_POST['grade_id']) || empty($_POST['subject_id']))) {
            wp_send_json_error(__('Required fields are missing.', 'olama-exam-management'));
        }
        if (isset($_POST['exam_date'])) {
            $_POST['exam_date'] = Olama_School_Helpers::sanitize_date(wp_unslash($_POST['exam_date']));
        }

        $result = Olama_School_Exam::save_exam($_POST);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        if (false === $result) {
            wp_send_json_error(__('Database error: Could not save exam.', 'olama-exam-management'));
        }
        wp_send_json_success(array('message' => Olama_School_Helpers::translate('Exam saved successfully.')));
    }

    public function bulk_add_exam_subjects()
    {
        global $wpdb;
        check_ajax_referer('olama_save_exam', 'nonce');
        if (!Olama_School_Permissions::can('olama_manage_exams_schedule')) {
            wp_send_json_error(__('Unauthorized', 'olama-exam-management'), 403);
        }

        $year_id = intval($_POST['academic_year_id'] ?? 0);
        $semester_id = intval($_POST['semester_id'] ?? 0);
        $exam_id = intval($_POST['semester_exam_id'] ?? 0);
        $grade_id = intval($_POST['grade_id'] ?? 0);
        if (!$year_id || !$semester_id || !$exam_id || !$grade_id) {
            wp_send_json_error(__('Missing parameters', 'olama-exam-management'));
        }

        $exam_meta = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}olama_semester_exams WHERE id = %d", $exam_id));
        $subjects = Olama_School_Subject::get_subjects_by_grade($grade_id);
        $added = 0;
        foreach ($subjects as $subject) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_exams WHERE semester_exam_id = %d AND subject_id = %d",
                $exam_id,
                $subject->id
            ));
            if ($exists) {
                continue;
            }

            Olama_School_Exam::save_exam(array(
                'academic_year_id' => $year_id,
                'semester_id'      => $semester_id,
                'semester_exam_id' => $exam_id,
                'grade_id'         => $grade_id,
                'subject_id'       => $subject->id,
                'evaluation_type'  => $exam_meta ? $exam_meta->exam_name : '',
                'exam_date'        => $exam_meta ? $exam_meta->start_date : current_time('Y-m-d'),
                'status'           => 'draft',
            ));
            $added++;
        }

        wp_send_json_success(array('message' => sprintf(__('%d subjects added to exam.', 'olama-exam-management'), $added)));
    }

    public function upload_exam_file()
    {
        if (!check_ajax_referer('olama_save_exam', 'nonce', false)) {
            wp_send_json_error(__('Session expired or security check failed. Please refresh.', 'olama-exam-management'));
        }
        if (empty($_POST['exam_id']) || empty($_FILES['exam_file'])) {
            wp_send_json_error(__('Missing parameters', 'olama-exam-management'));
        }

        $result = Olama_School_Exam_Attachment::handle_upload(intval($_POST['exam_id']), $_FILES['exam_file']);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success(__('File uploaded successfully', 'olama-exam-management'));
    }

    public function delete_exam_attachment()
    {
        if (!check_ajax_referer('olama_save_exam', 'nonce', false)) {
            wp_send_json_error(__('Session expired or security check failed. Please refresh.', 'olama-exam-management'));
        }
        if (empty($_POST['exam_id'])) {
            wp_send_json_error(__('Missing exam ID', 'olama-exam-management'));
        }
        if (!Olama_School_Permissions::can('olama_upload_exam_files') && !Olama_School_Permissions::can('olama_manage_exams_schedule')) {
            wp_send_json_error(__('Permission denied.', 'olama-exam-management'), 403);
        }

        $result = Olama_School_Exam_Attachment::delete_attachment(intval($_POST['exam_id']));
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success(__('File deleted successfully', 'olama-exam-management'));
    }

    public function download_exam_file()
    {
        $exam_id = intval($_GET['exam_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? ''));
        if (!$exam_id || !wp_verify_nonce($nonce, 'olama_download_file_' . $exam_id)) {
            wp_die(esc_html__('Security check failed', 'olama-exam-management'));
        }
        if (!Olama_School_Permissions::can('olama_fill_exam_details') && !Olama_School_Permissions::can('olama_manage_exams_schedule')) {
            wp_die(esc_html__('Unauthorized', 'olama-exam-management'), '', array('response' => 403));
        }
        Olama_School_Exam_Attachment::stream_file($exam_id);
    }

    public function get_exam_attachment()
    {
        if (!check_ajax_referer('olama_save_exam', 'nonce', false)) {
            wp_send_json_error(__('Session expired or security check failed. Please refresh.', 'olama-exam-management'));
        }
        if (!Olama_School_Permissions::can('olama_fill_exam_details') && !Olama_School_Permissions::can('olama_manage_exams_schedule')) {
            wp_send_json_error(__('Permission denied.', 'olama-exam-management'), 403);
        }
        $exam_id = intval($_POST['exam_id'] ?? 0);
        if (!Olama_School_Exam::current_user_can_access_exam($exam_id, 'olama_fill_exam_details')) {
            wp_send_json_error(__('You are not assigned to this exam.', 'olama-exam-management'), 403);
        }
        $info = Olama_School_Exam_Attachment::get_attachment_info($exam_id);
        if ($info) {
            $info->download_url = add_query_arg(array(
                'action'   => 'olama_download_exam_file',
                'exam_id'  => $exam_id,
                '_wpnonce' => wp_create_nonce('olama_download_file_' . $exam_id),
            ), admin_url('admin-ajax.php'));
        }
        wp_send_json_success($info);
    }

    public function save_exam_attachment_comment()
    {
        global $wpdb;
        check_ajax_referer('olama_save_exam', 'nonce');
        if (!Olama_School_Permissions::can('olama_manage_exams_schedule')) {
            wp_send_json_error(__('Unauthorized', 'olama-exam-management'), 403);
        }

        $wpdb->update(
            $wpdb->prefix . 'olama_exam_attachments',
            array(
                'file_status'        => sanitize_text_field(wp_unslash($_POST['file_status'] ?? '')),
                'supervisor_comments' => sanitize_textarea_field(wp_unslash($_POST['supervisor_comments'] ?? '')),
            ),
            array('exam_id' => intval($_POST['exam_id'] ?? 0))
        );
        wp_send_json_success(__('Comments saved successfully', 'olama-exam-management'));
    }

    public function download_all_exams_zip()
    {
        check_admin_referer('olama_download_all_exams_zip');
        if (!Olama_School_Permissions::can('olama_manage_exams_schedule')) {
            wp_die(esc_html__('Unauthorized', 'olama-exam-management'));
        }
        Olama_School_Exam_Attachment::download_all_approved_zip(array(
            'academic_year_id' => intval($_GET['academic_year_id'] ?? 0),
            'semester_id'      => intval($_GET['semester_id'] ?? 0),
            'semester_exam_id' => intval($_GET['semester_exam_id'] ?? 0),
            'grade_id'         => intval($_GET['grade_id'] ?? 0),
        ));
    }

    public function get_semester_exams()
    {
        check_ajax_referer('olama_curriculum_nonce', 'nonce');
        if (!Olama_School_Permissions::can('olama_access_exams_mgmt')) {
            wp_send_json_error(__('Unauthorized', 'olama-exam-management'), 403);
        }
        wp_send_json_success(Olama_School_Academic::get_semester_exams(intval($_POST['semester_id'] ?? 0)));
    }
}
