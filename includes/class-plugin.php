<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Olama_Exam_Management_Plugin
{
    private static $instance = null;
    private $available = false;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate()
    {
        if (!class_exists('Olama_School_DB') || !class_exists('Olama_School_Permissions')) {
            deactivate_plugins(plugin_basename(OLAMA_EXAM_MANAGEMENT_FILE));
            wp_die(
                esc_html__('Olama Exam Management requires the Olama School plugin to be installed and active.', 'olama-exam-management'),
                esc_html__('Plugin dependency missing', 'olama-exam-management'),
                array('back_link' => true)
            );
        }

        Olama_Exam_Management_DB::install();
        Olama_School_Permissions::add_capabilities();
        update_option('olama_exam_management_db_version', OLAMA_EXAM_MANAGEMENT_VERSION);
    }

    private function __construct()
    {
        load_plugin_textdomain(
            'olama-exam-management',
            false,
            dirname(plugin_basename(OLAMA_EXAM_MANAGEMENT_FILE)) . '/languages'
        );

        add_action('admin_notices', array($this, 'dependency_notice'));
        add_filter('olama_dashboard_cards', array($this, 'register_hub_card'), 20);
        add_action('olama_users_register_modules', array($this, 'register_access_module'));

        $this->available = $this->dependencies_available();
        if (!$this->available) {
            return;
        }

        require_once OLAMA_EXAM_MANAGEMENT_PATH . 'includes/class-exam.php';
        require_once OLAMA_EXAM_MANAGEMENT_PATH . 'includes/class-exam-attachment.php';
        require_once OLAMA_EXAM_MANAGEMENT_PATH . 'includes/class-exam-hall.php';
        require_once OLAMA_EXAM_MANAGEMENT_PATH . 'includes/class-exam-hall-ajax.php';
        require_once OLAMA_EXAM_MANAGEMENT_PATH . 'includes/class-ajax.php';
        require_once OLAMA_EXAM_MANAGEMENT_PATH . 'includes/class-admin.php';

        if (is_admin()) {
            new Olama_Exam_Management_Admin();
            new Olama_Exam_Management_Ajax();
            new Olama_Exam_Hall_Ajax();
            add_action('admin_init', array($this, 'maybe_update_schema'), 5);
        }
    }

    private function dependencies_available()
    {
        return defined('OLAMA_SCHOOL_FILE')
            && class_exists('Olama_School_DB')
            && class_exists('Olama_School_Permissions')
            && class_exists('Olama_School_Academic')
            && class_exists('Olama_School_Grade')
            && class_exists('Olama_School_Subject');
    }

    public function maybe_update_schema()
    {
        if (get_option('olama_exam_management_db_version') === OLAMA_EXAM_MANAGEMENT_VERSION) {
            return;
        }

        Olama_Exam_Management_DB::install();
        Olama_Exam_Hall::maybe_migrate();
        Olama_School_Permissions::add_capabilities();
        update_option('olama_exam_management_db_version', OLAMA_EXAM_MANAGEMENT_VERSION);
    }

    public function dependency_notice()
    {
        if ($this->available || !current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>'
            . esc_html__('Olama Exam Management is inactive because Olama School is not active.', 'olama-exam-management')
            . '</p></div>';
    }

    public function register_hub_card($cards)
    {
        if (!$this->available) {
            return $cards;
        }

        foreach ($cards as &$card) {
            if (($card['id'] ?? '') !== 'olama-school' || empty($card['submenus'])) {
                continue;
            }

            $card['submenus'] = array_values(array_filter($card['submenus'], function ($submenu) {
                return !in_array($submenu['id'] ?? '', array('school.exams', 'school.exam_halls'), true);
            }));
        }
        unset($card);

        $can_access_exams = Olama_School_Permissions::can('olama_access_exams_mgmt');
        $card_capability = $can_access_exams ? 'olama_access_exams_mgmt' : 'olama_access_exam_halls';
        $primary_url = $can_access_exams
            ? admin_url('admin.php?page=olama-exam-management')
            : admin_url('admin.php?page=olama-exam-halls');

        $cards[] = array(
            'id'          => 'olama-exam-management',
            'label'       => __('Exam Management', 'olama-exam-management'),
            'description' => __('Exam schedules, teacher exam files, halls, distribution, attendance and notes.', 'olama-exam-management'),
            'icon'        => 'dashicons-clipboard',
            'accent'      => '#ea580c',
            'accent_rgb'  => '234,88,12',
            'active'      => true,
            'capability'  => $card_capability,
            'primary_url' => $primary_url,
            'submenus'    => array(
                array(
                    'id'         => 'exam_management.exams',
                    'label'      => __('Exam Management', 'olama-exam-management'),
                    'icon'       => 'dashicons-calendar-alt',
                    'url'        => admin_url('admin.php?page=olama-exam-management'),
                    'capability' => 'olama_access_exams_mgmt',
                    'color'      => '#ea580c',
                ),
                array(
                    'id'         => 'exam_management.halls',
                    'label'      => __('Exam Hall Distribution', 'olama-exam-management'),
                    'icon'       => 'dashicons-building',
                    'url'        => admin_url('admin.php?page=olama-exam-halls'),
                    'capability' => 'olama_access_exam_halls',
                    'color'      => '#ea580c',
                ),
            ),
        );

        return $cards;
    }

    /**
     * Declare every user-facing Exam Management surface to OLAMA Users.
     */
    public function register_access_module()
    {
        if (!function_exists('olama_users_register_module')) {
            return;
        }

        olama_users_register_module(array(
            'id' => 'exam_management',
            'plugin' => 'olama-exam-management',
            'label' => __('Exam Management', 'olama-exam-management'),
            'capability' => 'olama_access_exams_mgmt',
            'items' => array(
                array(
                    'id' => 'exam_management.exams',
                    'type' => 'submenu',
                    'label' => __('Exam Management', 'olama-exam-management'),
                    'capability' => 'olama_access_exams_mgmt',
                    'url' => admin_url('admin.php?page=olama-exam-management'),
                    'tabs' => array(
                        array(
                            'id' => 'exam_management.exams.schedule',
                            'type' => 'tab',
                            'label' => __('Exam Schedule', 'olama-exam-management'),
                            'capability' => 'olama_manage_exams_schedule',
                            'actions' => array(
                                array('id' => 'exam_management.exams.schedule.save', 'type' => 'action', 'label' => __('Save and delete exams', 'olama-exam-management'), 'capability' => 'olama_manage_exams_schedule'),
                                array('id' => 'exam_management.exams.schedule.bulk', 'type' => 'action', 'label' => __('Add exam subjects in bulk', 'olama-exam-management'), 'capability' => 'olama_manage_exams_schedule'),
                                array('id' => 'exam_management.exams.schedule.review', 'type' => 'action', 'label' => __('Review files and download archive', 'olama-exam-management'), 'capability' => 'olama_manage_exams_schedule'),
                            ),
                        ),
                        array(
                            'id' => 'exam_management.exams.teacher',
                            'type' => 'tab',
                            'label' => __('Teacher Exams', 'olama-exam-management'),
                            'capability' => 'olama_fill_exam_details',
                            'actions' => array(
                                array('id' => 'exam_management.exams.teacher.upload', 'type' => 'action', 'label' => __('Upload exam files', 'olama-exam-management'), 'capability' => 'olama_upload_exam_files'),
                                array('id' => 'exam_management.exams.teacher.view_files', 'type' => 'action', 'label' => __('View and download exam files', 'olama-exam-management'), 'capability' => 'olama_fill_exam_details'),
                            ),
                        ),
                    ),
                ),
                array(
                    'id' => 'exam_management.halls',
                    'type' => 'submenu',
                    'label' => __('Exam Hall Distribution', 'olama-exam-management'),
                    'capability' => 'olama_access_exam_halls',
                    'url' => admin_url('admin.php?page=olama-exam-halls'),
                    'tabs' => array(
                        array(
                            'id' => 'exam_management.halls.distribution',
                            'type' => 'tab',
                            'label' => __('Distribution', 'olama-exam-management'),
                            'capability' => 'olama_manage_exam_halls',
                            'actions' => array(
                                array('id' => 'exam_management.halls.distribute', 'type' => 'action', 'label' => __('Distribute and move students', 'olama-exam-management'), 'capability' => 'olama_manage_exam_halls'),
                                array('id' => 'exam_management.halls.invigilators', 'type' => 'action', 'label' => __('Assign invigilators and seats', 'olama-exam-management'), 'capability' => 'olama_manage_exam_halls'),
                            ),
                        ),
                        array(
                            'id' => 'exam_management.halls.attendance',
                            'type' => 'tab',
                            'label' => __('Attendance', 'olama-exam-management'),
                            'capability' => 'olama_manage_hall_attendance',
                            'actions' => array(
                                array('id' => 'exam_management.halls.attendance.save', 'type' => 'action', 'label' => __('Save hall attendance', 'olama-exam-management'), 'capability' => 'olama_manage_hall_attendance'),
                            ),
                        ),
                        array(
                            'id' => 'exam_management.halls.notes',
                            'type' => 'tab',
                            'label' => __('Notes', 'olama-exam-management'),
                            'capability' => 'olama_manage_hall_attendance',
                            'actions' => array(
                                array('id' => 'exam_management.halls.notes.save', 'type' => 'action', 'label' => __('Save behavior notes', 'olama-exam-management'), 'capability' => 'olama_manage_hall_attendance'),
                                array('id' => 'exam_management.halls.notes.delete', 'type' => 'action', 'label' => __('Delete behavior notes', 'olama-exam-management'), 'capability' => 'olama_manage_exam_halls'),
                            ),
                        ),
                        array(
                            'id' => 'exam_management.halls.manage',
                            'type' => 'tab',
                            'label' => __('Halls', 'olama-exam-management'),
                            'capability' => 'olama_manage_exam_halls',
                            'actions' => array(
                                array('id' => 'exam_management.halls.manage.save', 'type' => 'action', 'label' => __('Create, edit, and delete halls', 'olama-exam-management'), 'capability' => 'olama_manage_exam_halls'),
                            ),
                        ),
                    ),
                ),
            ),
        ));
    }
}
