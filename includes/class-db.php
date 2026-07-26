<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Olama_Exam_Management_DB
{
    public static function install()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $tables = self::schemas($wpdb->prefix, $charset_collate);
        foreach ($tables as $sql) {
            // dbDelta must also run for existing tables so type, column, and
            // index changes are applied during plugin upgrades.
            dbDelta($sql);
        }
    }

    private static function schemas($prefix, $charset_collate)
    {
        return array(
            'olama_exams' => "CREATE TABLE {$prefix}olama_exams (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                academic_year_id mediumint(9) NOT NULL,
                semester_id mediumint(9) NOT NULL,
                semester_exam_id mediumint(9) DEFAULT NULL,
                grade_id mediumint(9) NOT NULL,
                subject_id mediumint(9) NOT NULL,
                evaluation_type varchar(50) NOT NULL,
                exam_date date NOT NULL,
                room_number varchar(50) DEFAULT NULL,
                description text DEFAULT NULL,
                student_book_material text DEFAULT NULL,
                workbook_material text DEFAULT NULL,
                exercise_book_material text DEFAULT NULL,
                notebook_material text DEFAULT NULL,
                teacher_notes text DEFAULT NULL,
                exam_material_json longtext DEFAULT NULL,
                status varchar(20) DEFAULT 'draft' NOT NULL,
                supervisor_comments text DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                KEY year_semester (academic_year_id,semester_id),
                KEY grade_subject (grade_id,subject_id),
                KEY semester_exam_id (semester_exam_id),
                KEY exam_tracking (grade_id,subject_id,status)
            ) {$charset_collate};",

            'olama_exam_attachments' => "CREATE TABLE {$prefix}olama_exam_attachments (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                exam_id mediumint(9) NOT NULL,
                user_id bigint(20) UNSIGNED NOT NULL,
                original_filename varchar(255) NOT NULL,
                stored_filename varchar(255) NOT NULL,
                file_size bigint(20) NOT NULL,
                file_hash varchar(64) NOT NULL,
                file_status varchar(20) DEFAULT 'uploaded' NOT NULL,
                supervisor_comments text DEFAULT NULL,
                uploaded_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                KEY exam_id (exam_id),
                KEY user_id (user_id)
            ) {$charset_collate};",

            'olama_exam_halls' => "CREATE TABLE {$prefix}olama_exam_halls (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                hall_name varchar(100) NOT NULL,
                capacity smallint(6) NOT NULL DEFAULT 30,
                academic_year_id mediumint(9) NOT NULL,
                is_active tinyint(1) DEFAULT 1 NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                KEY academic_year_id (academic_year_id)
            ) {$charset_collate};",

            'olama_exam_hall_assignments' => "CREATE TABLE {$prefix}olama_exam_hall_assignments (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                hall_id mediumint(9) NOT NULL,
                student_id bigint(20) UNSIGNED NOT NULL,
                student_uid varchar(100) DEFAULT NULL,
                academic_year_id mediumint(9) NOT NULL,
                semester_id mediumint(9) NOT NULL DEFAULT 0,
                seat_number smallint(6) DEFAULT NULL,
                assigned_by bigint(20) UNSIGNED DEFAULT NULL,
                assigned_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY student_context (student_id,academic_year_id,semester_id),
                KEY hall_id (hall_id),
                KEY academic_year_id (academic_year_id),
                KEY student_uid (student_uid)
            ) {$charset_collate};",

            'olama_exam_hall_attendance' => "CREATE TABLE {$prefix}olama_exam_hall_attendance (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                hall_id mediumint(9) NOT NULL,
                academic_year_id mediumint(9) NOT NULL,
                semester_id mediumint(9) NOT NULL DEFAULT 0,
                student_id bigint(20) UNSIGNED NOT NULL,
                student_uid varchar(100) DEFAULT NULL,
                exam_date date NOT NULL,
                session_label varchar(100) DEFAULT '' NOT NULL,
                status varchar(20) DEFAULT 'present' NOT NULL,
                recorded_by bigint(20) UNSIGNED DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY session_student (hall_id,student_id,exam_date,session_label),
                KEY hall_id (hall_id),
                KEY exam_date (exam_date)
            ) {$charset_collate};",

            'olama_exam_hall_notes' => "CREATE TABLE {$prefix}olama_exam_hall_notes (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                hall_id mediumint(9) NOT NULL,
                student_id bigint(20) UNSIGNED NOT NULL,
                student_uid varchar(100) DEFAULT NULL,
                exam_date date NOT NULL,
                semester_id mediumint(9) NOT NULL DEFAULT 0,
                note_type varchar(50) DEFAULT 'ملتزم' NOT NULL,
                note_text text DEFAULT NULL,
                recorded_by bigint(20) UNSIGNED DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                KEY hall_student_date (hall_id,student_id,exam_date)
            ) {$charset_collate};",

            'olama_exam_hall_invigilators' => "CREATE TABLE {$prefix}olama_exam_hall_invigilators (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                hall_id mediumint(9) NOT NULL,
                invigilator_id bigint(20) UNSIGNED NOT NULL,
                academic_year_id mediumint(9) NOT NULL,
                semester_id mediumint(9) NOT NULL DEFAULT 0,
                assigned_by bigint(20) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY inv_context (invigilator_id,academic_year_id,semester_id),
                KEY hall_context (hall_id,academic_year_id,semester_id)
            ) {$charset_collate};",
        );
    }
}
