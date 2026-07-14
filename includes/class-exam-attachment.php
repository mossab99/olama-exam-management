<?php
/**
 * Exam Attachment Handler Class
 * Provides secure upload and download functionality for exam files
 */

if (!defined('ABSPATH')) {
    exit;
}

class Olama_School_Exam_Attachment
{
    /**
     * Get the base directory for exam uploads based on OS
     */
    public static function get_upload_base_dir()
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: Outside the public/ folder in Local Sites structure
            // Resolve the path programmatically to avoid '..' issues on some Windows setups
            $public_dir = wp_normalize_path(untrailingslashit(ABSPATH));
            $app_dir = dirname($public_dir);
            $site_dir = dirname($app_dir);
            $base = $site_dir . '/olama_secure_exams/';
        } else {
            // Linux (Contabo VPS)
            $base = '/srv/exams/uploads/';
        }

        return wp_normalize_path($base);
    }

    /**
     * Ensure the upload directory exists
     */
    public static function ensure_dir_exists()
    {
        $dir = self::get_upload_base_dir();
        if (!file_exists($dir)) {
            if (!wp_mkdir_p($dir)) {
                return new WP_Error('dir_creation_failed', sprintf(__('Could not create storage directory: %s', 'olama-school'), $dir));
            }
            // Add an index.php and .htaccess for extra security
            @file_put_contents($dir . 'index.php', '<?php // Silence is golden');
            @file_put_contents($dir . '.htaccess', 'Deny from all');
        }

        if (!is_writable($dir)) {
            return new WP_Error('dir_not_writable', sprintf(__('Storage directory is not writable: %s', 'olama-school'), $dir));
        }

        return $dir;
    }

    /**
     * Validate the uploaded file
     */
    public static function validate_file($file)
    {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, array('docx', 'pdf'))) {
            return new WP_Error('invalid_extension', __('Only .docx and .pdf files are allowed.', 'olama-school'));
        }

        // 2. Check MIME type
        $allowed_mimes = array(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/pdf',
            'application/zip', // Some systems might report docx as zip
            'application/octet-stream' // Fallback
        );

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime_type, $allowed_mimes)) {
            return new WP_Error('invalid_mime', __('Invalid file type. Please upload a genuine Word document.', 'olama-school'));
        }

        // 3. Deep validation for .docx
        if ($ext === 'docx') {
            $zip = new ZipArchive();
            if ($zip->open($file['tmp_name']) === TRUE) {
                if ($zip->locateName('word/document.xml') === false) {
                    $zip->close();
                    return new WP_Error('invalid_structure', __('The file is not a valid Word (.docx) document.', 'olama-school'));
                }
                $zip->close();
            } else {
                return new WP_Error('zip_error', __('Could not open the file for validation.', 'olama-school'));
            }
        }

        return true;
    }

    /**
     * Handle the file upload
     */
    public static function handle_upload($exam_id, $file)
    {
        global $wpdb;

        // Security check
        if (!Olama_School_Permissions::can('olama_upload_exam_files')) {
            return new WP_Error('unauthorized', __('Unauthorized', 'olama-school'));
        }

        $validation = self::validate_file($file);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $upload_dir = self::ensure_dir_exists();
        if (is_wp_error($upload_dir)) {
            return $upload_dir;
        }

        $user_id = get_current_user_id();
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        // Get descriptive filename
        $details = Olama_School_Exam::get_full_exam_details($exam_id);
        if ($details) {
            // Custom descriptive naming: Year-Semester-Exam-Grade-Subject-Date
            $descriptive_name = sprintf(
                '%s - %s - %s - %s - %s - %s',
                $details->year_name,
                $details->semester_name,
                $details->exam_name,
                $details->grade_name,
                $details->subject_name,
                $details->exam_date
            );

            // Sanitize: Preserve Arabic but remove dangerous chars
            $clean_name = preg_replace('/[\\\\\/:\*\?"<>|]/', '', $descriptive_name);
            $clean_name = str_replace(' ', '-', $clean_name);
            $clean_name = preg_replace('/-+/', '-', $clean_name);

            // If it ends up empty or too short, fallback to UUID
            if (mb_strlen(trim($clean_name, '-')) < 3) {
                $stored_filename = wp_generate_uuid4() . '.' . $ext;
            } else {
                $stored_filename = trim($clean_name, '-') . '.' . $ext;
            }
        } else {
            // Fallback to UUID if details can't be fetched
            $stored_filename = wp_generate_uuid4() . '.' . $ext;
        }

        $destination = $upload_dir . $stored_filename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            // Save metadata to DB
            $data = array(
                'exam_id' => $exam_id,
                'user_id' => $user_id,
                'original_filename' => sanitize_file_name($file['name']),
                'stored_filename' => $stored_filename,
                'file_size' => filesize($destination),
                'file_hash' => hash_file('sha256', $destination),
                'file_status' => 'uploaded',
                'uploaded_at' => current_time('mysql')
            );

            // Check if there's already an attachment for this exam
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_exam_attachments WHERE exam_id = %d",
                $exam_id
            ));

            if ($existing) {
                // Delete old physical file if it has a different name or if we just want to be clean
                $old_filename = $wpdb->get_var($wpdb->prepare(
                    "SELECT stored_filename FROM {$wpdb->prefix}olama_exam_attachments WHERE id = %d",
                    $existing
                ));

                if ($old_filename && $old_filename !== $stored_filename) {
                    $old_filepath = self::get_upload_base_dir() . $old_filename;
                    if (file_exists($old_filepath)) {
                        @unlink($old_filepath);
                    }
                }

                $wpdb->update("{$wpdb->prefix}olama_exam_attachments", $data, array('id' => $existing));
            } else {
                $wpdb->insert("{$wpdb->prefix}olama_exam_attachments", $data);
            }

            return true;
        }

        return new WP_Error('upload_failed', __('Failed to move uploaded file.', 'olama-school'));
    }

    /**
     * Get attachment info for an exam
     */
    public static function get_attachment_info($exam_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}olama_exam_attachments WHERE exam_id = %d",
            $exam_id
        ));
    }

    /**
     * Secure file download
     */
    public static function stream_file($exam_id)
    {
        global $wpdb;

        $attachment = self::get_attachment_info($exam_id);
        if (!$attachment) {
            wp_die(__('File not found.', 'olama-school'));
        }

        // Permission check
        $allow = false;
        if (Olama_School_Permissions::can('manage_options') || current_user_can('editor')) {
            $allow = true; // Admin/Supervisors
        } elseif ($attachment->user_id == get_current_user_id()) {
            $allow = true; // Owner
        }

        if (!$allow) {
            wp_die(__('You do not have permission to download this file.', 'olama-school'));
        }

        $filepath = self::get_upload_base_dir() . $attachment->stored_filename;

        if (!file_exists($filepath)) {
            wp_die(__('Physical file not found on server.', 'olama-school'));
        }

        // Clean output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Set headers for download
        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        $mime_type = ($ext === 'pdf') ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: attachment; filename="' . $attachment->stored_filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filepath));

        readfile($filepath);
        exit;
    }

    /**
     * Get attachments based on exam filters
     */
    public static function get_attachments_by_filters($filters)
    {
        global $wpdb;
        $query = "SELECT a.* FROM {$wpdb->prefix}olama_exam_attachments a
                  JOIN {$wpdb->prefix}olama_exams e ON a.exam_id = e.id
                  WHERE 1=1";
        $params = array();

        if (!empty($filters['academic_year_id'])) {
            $query .= " AND e.academic_year_id = %d";
            $params[] = $filters['academic_year_id'];
        }
        if (!empty($filters['semester_id'])) {
            $query .= " AND e.semester_id = %d";
            $params[] = $filters['semester_id'];
        }
        if (!empty($filters['semester_exam_id'])) {
            $query .= " AND e.semester_exam_id = %d";
            $params[] = $filters['semester_exam_id'];
        }
        if (!empty($filters['grade_id'])) {
            $query .= " AND e.grade_id = %d";
            $params[] = $filters['grade_id'];
        }
        if (!empty($filters['status'])) {
            $query .= " AND a.file_status = %s";
            $params[] = $filters['status'];
        }
        if (!empty($filters['exam_status'])) {
            $query .= " AND e.status = %s";
            $params[] = $filters['exam_status'];
        }

        return $wpdb->get_results($wpdb->prepare($query, $params));
    }

    /**
     * Download all approved exams as a ZIP file
     */
    public static function download_all_approved_zip($filters)
    {
        if (!Olama_School_Permissions::can('manage_options')) {
            wp_die(__('Unauthorized', 'olama-school'));
        }

        $filters['exam_status'] = 'approved';
        $attachments = self::get_attachments_by_filters($filters);

        if (empty($attachments)) {
            wp_die(__('No approved exam files found for the current selection.', 'olama-school'));
        }

        $zip = new ZipArchive();
        $temp_zip = tempnam(sys_get_temp_dir(), 'exams_zip_');

        if ($zip->open($temp_zip, ZipArchive::CREATE) !== TRUE) {
            wp_die(__('Could not create ZIP file.', 'olama-school'));
        }

        $base_dir = self::get_upload_base_dir();
        $added_count = 0;

        foreach ($attachments as $at) {
            $file_path = $base_dir . $at->stored_filename;
            if (file_exists($file_path)) {
                $entry_name = !empty($at->stored_filename) ? $at->stored_filename : $at->original_filename;
                $zip->addFile($file_path, $entry_name);
                $added_count++;
            }
        }

        $zip->close();

        if ($added_count === 0) {
            @unlink($temp_zip);
            wp_die(__('No physical files found to ZIP.', 'olama-school'));
        }

        // Stream the ZIP
        while (ob_get_level()) {
            ob_end_clean();
        }

        $zip_name = 'exams_export_' . date('Y-m-d_H-i') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_name . '"');
        header('Content-Length: ' . filesize($temp_zip));
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($temp_zip);
        @unlink($temp_zip);
        exit;
    }
    /**
     * Delete an attachment (DB record and physical file)
     */
    public static function delete_attachment($exam_id)
    {
        global $wpdb;

        $attachment = self::get_attachment_info($exam_id);
        if (!$attachment) {
            return new WP_Error('not_found', __('Attachment not found.', 'olama-school'));
        }

        // 1. Delete physical file
        $filepath = self::get_upload_base_dir() . $attachment->stored_filename;
        if (file_exists($filepath)) {
            @unlink($filepath);
        }

        // 2. Delete DB record
        $deleted = $wpdb->delete("{$wpdb->prefix}olama_exam_attachments", array('id' => $attachment->id));
        if ($deleted === false) {
            return new WP_Error('db_delete_failed', __('Failed to delete record from database.', 'olama-school'));
        }

        return true;
    }
}

