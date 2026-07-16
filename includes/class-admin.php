<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Exam_Management_Admin
{
    public function __construct()
    {
        add_action('admin_menu', array($this, 'register_menus'), 30);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_init', array($this, 'handle_exam_save'));
        add_action('admin_init', array($this, 'redirect_legacy_pages'), 1);
    }

    public function register_menus()
    {
        $can_access_exams = Olama_School_Permissions::can('olama_access_exams_mgmt');
        $parent_capability = $can_access_exams ? 'olama_access_exams_mgmt' : 'olama_access_exam_halls';

        add_menu_page(
            __('Olama Exams', 'olama-exam-management'),
            __('Olama Exams', 'olama-exam-management'),
            $parent_capability,
            'olama-exam-management',
            array($this, 'render_module_landing'),
            'dashicons-clipboard',
            28
        );

        add_submenu_page(
            'olama-exam-management',
            __('Exam Management', 'olama-exam-management'),
            __('Exam Management', 'olama-exam-management'),
            'olama_access_exams_mgmt',
            'olama-exam-management',
            array($this, 'render_exam_management_page')
        );

        add_submenu_page(
            'olama-exam-management',
            __('Exam Hall Distribution', 'olama-exam-management'),
            __('Exam Hall Distribution', 'olama-exam-management'),
            'olama_access_exam_halls',
            'olama-exam-halls',
            array($this, 'render_exam_hall_distribution_page')
        );
    }

    public function render_module_landing()
    {
        if (Olama_School_Permissions::can('olama_access_exams_mgmt')) {
            $this->render_exam_management_page();
            return;
        }

        if (Olama_School_Permissions::can('olama_access_exam_halls')) {
            wp_safe_redirect(admin_url('admin.php?page=olama-exam-halls'));
            exit;
        }

        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'olama-exam-management'));
    }

    public function redirect_legacy_pages()
    {
        if (empty($_GET['page'])) {
            return;
        }

        $map = array(
            'olama-school-exams'      => 'olama-exam-management',
            'olama-school-exam-halls' => 'olama-exam-halls',
        );
        $legacy_page = sanitize_key(wp_unslash($_GET['page']));
        if (!isset($map[$legacy_page])) {
            return;
        }

        $args = wp_unslash($_GET);
        $args['page'] = $map[$legacy_page];
        unset($args['_wpnonce']);
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public function handle_exam_save()
    {
        if (wp_doing_ajax()) {
            return;
        }

        if (isset($_POST['olama_save_exam']) && check_admin_referer('olama_save_exam', 'olama_exam_nonce_field')) {
            if (!Olama_School_Permissions::can('olama_manage_exams_schedule') && !Olama_School_Permissions::can('olama_fill_exam_details')) {
                wp_die(esc_html__('You are not allowed to save exams.', 'olama-exam-management'));
            }

            $result = Olama_School_Exam::save_exam($_POST);
            $redirect_url = add_query_arg(array(
                'page'             => 'olama-exam-management',
                'tab'              => 'exam_schedule',
                'academic_year_id' => intval($_POST['academic_year_id'] ?? 0),
                'semester_id'      => intval($_POST['semester_id'] ?? 0),
                'grade_id'         => intval($_POST['grade_id'] ?? 0),
                'subject_id'       => intval($_POST['subject_id'] ?? 0),
                'message'          => is_wp_error($result) ? 'error' : 'exam_saved',
            ), admin_url('admin.php'));

            wp_safe_redirect($redirect_url);
            exit;
        }

        if (($_GET['action'] ?? '') === 'delete_exam' && isset($_GET['exam_id'])) {
            $exam_id = intval($_GET['exam_id']);
            if (!Olama_School_Permissions::can('olama_manage_exams_schedule')) {
                wp_die(esc_html__('You are not allowed to delete exams.', 'olama-exam-management'));
            }
            if (check_admin_referer('olama_delete_exam_' . $exam_id)) {
                Olama_School_Exam::delete_exam($exam_id);
                $redirect_url = remove_query_arg(array('action', 'exam_id', '_wpnonce'), wp_get_referer());
                wp_safe_redirect(add_query_arg('message', 'exam_deleted', $redirect_url));
                exit;
            }
        }
    }

    public function render_exam_management_page()
    {
        $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'exam_schedule';
        $tabs = array(
            'exam_schedule' => array('label' => Olama_School_Helpers::translate('Exam Schedule'), 'cap' => 'olama_manage_exams_schedule'),
            'teacher_exams' => array('label' => Olama_School_Helpers::translate('Teacher Exams'), 'cap' => 'olama_fill_exam_details'),
        );
        $allowed_tabs = array_filter($tabs, function ($tab) {
            return Olama_School_Permissions::can($tab['cap']);
        });

        if (!$allowed_tabs) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'olama-exam-management'));
        }
        if (!isset($allowed_tabs[$active_tab])) {
            $active_tab = array_key_first($allowed_tabs);
        }

        echo '<div class="wrap olama-school-wrap"><h1>' . esc_html(Olama_School_Helpers::translate('Exam Management')) . '</h1>';
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($allowed_tabs as $id => $tab) {
            $url = add_query_arg(array('page' => 'olama-exam-management', 'tab' => $id), admin_url('admin.php'));
            echo '<a href="' . esc_url($url) . '" class="nav-tab ' . ($active_tab === $id ? 'nav-tab-active' : '') . '">'
                . esc_html($tab['label']) . '</a>';
        }
        echo '</h2><div class="olama-tab-content" style="margin-top:20px;">';

        if ('teacher_exams' === $active_tab) {
            $this->render_teacher_exams_content();
        } else {
            $this->render_exam_schedule_content();
        }
        echo '</div></div>';
    }

    private function render_exam_schedule_content()
    {
        $years = Olama_School_Academic::get_years();
        $active_year = Olama_School_Academic::get_active_year();
        $selected_year_id = isset($_GET['academic_year_id']) ? intval($_GET['academic_year_id']) : ($active_year ? $active_year->id : 0);
        $semesters = $selected_year_id ? Olama_School_Academic::get_semesters($selected_year_id) : array();
        $active_semester = Olama_School_Academic::get_active_semester($selected_year_id);
        $selected_semester_id = isset($_GET['semester_id']) ? intval($_GET['semester_id']) : ($active_semester ? $active_semester->id : (!empty($semesters) ? $semesters[0]->id : 0));
        $grades = Olama_School_Grade::get_grades();
        $selected_grade_id = isset($_GET['grade_id']) ? intval($_GET['grade_id']) : (!empty($grades) ? $grades[0]->id : 0);
        $subjects = $selected_grade_id ? Olama_School_Subject::get_subjects_by_grade($selected_grade_id) : array();
        $selected_subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
        $active_exam = Olama_School_Academic::get_active_exam($selected_semester_id);
        $selected_semester_exam_id = $active_exam ? $active_exam->id : 0;

        include OLAMA_EXAM_MANAGEMENT_PATH . 'admin-views/exam-schedule.php';
    }

    private function render_teacher_exams_content()
    {
        $years = Olama_School_Academic::get_years();
        $active_year = Olama_School_Academic::get_active_year();
        $selected_year_id = isset($_GET['academic_year_id']) ? intval($_GET['academic_year_id']) : ($active_year ? $active_year->id : 0);
        $semesters = $selected_year_id ? Olama_School_Academic::get_semesters($selected_year_id) : array();
        $active_semester = Olama_School_Academic::get_active_semester($selected_year_id);
        $selected_semester_id = isset($_GET['semester_id']) ? intval($_GET['semester_id']) : ($active_semester ? $active_semester->id : (!empty($semesters) ? $semesters[0]->id : 0));
        $active_exam = Olama_School_Academic::get_active_exam($selected_semester_id);
        $selected_exam_id = $active_exam ? $active_exam->id : 0;

        include OLAMA_EXAM_MANAGEMENT_PATH . 'admin-views/teacher-exams.php';
    }

    public function render_exam_hall_distribution_page()
    {
        include OLAMA_EXAM_MANAGEMENT_PATH . 'admin-views/exam-hall-distribution.php';
    }

    public function enqueue_assets($hook)
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (!in_array($page, array('olama-exam-management', 'olama-exam-halls'), true)) {
            return;
        }
        if ('olama-exam-management' === $page && !Olama_School_Permissions::can('olama_access_exams_mgmt')) {
            return;
        }
        if ('olama-exam-halls' === $page && !Olama_School_Permissions::can('olama_access_exam_halls')) {
            return;
        }

        $this->enqueue_style('olama-exam-management-admin', 'assets/css/admin.css');
        wp_enqueue_style('jquery-ui-datepicker-css', 'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css', array(), '1.13.2');
        if (Olama_School_Helpers::is_arabic()) {
            $this->enqueue_style('olama-exam-management-rtl', 'assets/css/admin-rtl.css', array('olama-exam-management-admin'));
        }
        wp_enqueue_script('jquery-ui-datepicker');

        if ('olama-exam-halls' !== $page) {
            return;
        }

        $active_year = Olama_School_Academic::get_active_year();
        $year_id = $active_year ? $active_year->id : 0;
        $halls = $year_id ? Olama_Exam_Hall::get_halls($year_id) : array();
        $grades = Olama_School_Grade::get_grades();
        $active_semester = $year_id ? Olama_School_Academic::get_active_semester($year_id) : null;
        $active_semester_id = $active_semester ? $active_semester->id : 0;
        $sections_by_grade = array();
        foreach ($grades as $grade) {
            $sections = Olama_School_Section::get_by_grade($grade->id, $year_id);
            if ($sections) {
                $sections_by_grade[$grade->id] = array_map(function ($section) {
                    return array('id' => $section->id, 'section_name' => $section->section_name);
                }, $sections);
            }
        }

        $this->enqueue_style('olama-exam-hall-style', 'assets/css/exam-hall.css', array('olama-exam-management-admin'));
        $script_path = OLAMA_EXAM_MANAGEMENT_PATH . 'assets/js/exam-hall.js';
        wp_enqueue_script('olama-exam-hall-script', OLAMA_EXAM_MANAGEMENT_URL . 'assets/js/exam-hall.js', array('jquery', 'jquery-ui-sortable'), $this->asset_version($script_path), true);
        wp_localize_script('olama-exam-hall-script', 'olamaExamHall', array(
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('olama_exam_hall_nonce'),
            'yearId'       => (string) $year_id,
            'yearName'     => $active_year ? $active_year->year_name : '',
            'semesterId'   => (string) $active_semester_id,
            'semesterName' => $active_semester ? ($active_semester->semester_name ?? '') : '',
            'isAdmin'      => Olama_School_Permissions::can('olama_manage_exam_halls') ? '1' : '0',
            'isArabic'     => Olama_School_Helpers::is_arabic() ? '1' : '0',
            'halls'        => $halls,
            'sections'     => $sections_by_grade,
        ));
    }

    private function enqueue_style($handle, $relative_path, $dependencies = array())
    {
        $path = OLAMA_EXAM_MANAGEMENT_PATH . $relative_path;
        wp_enqueue_style($handle, OLAMA_EXAM_MANAGEMENT_URL . $relative_path, $dependencies, $this->asset_version($path));
    }

    private function asset_version($path)
    {
        return OLAMA_EXAM_MANAGEMENT_VERSION . '-' . (file_exists($path) ? filemtime($path) : '0');
    }
}
