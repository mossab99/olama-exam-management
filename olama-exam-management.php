<?php
/**
 * Plugin Name: Olama Exam Management
 * Plugin URI: https://olama.online
 * Description: Exam schedules, teacher exam files, and exam hall distribution for Olama School.
 * Version: 1.0.0
 * Author: Olama
 * Text Domain: olama-exam-management
 * Domain Path: /languages
 * Requires Plugins: olama-school
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OLAMA_EXAM_MANAGEMENT_VERSION', '1.0.0');
define('OLAMA_EXAM_MANAGEMENT_FILE', __FILE__);
define('OLAMA_EXAM_MANAGEMENT_PATH', plugin_dir_path(__FILE__));
define('OLAMA_EXAM_MANAGEMENT_URL', plugin_dir_url(__FILE__));

require_once OLAMA_EXAM_MANAGEMENT_PATH . 'includes/class-db.php';
require_once OLAMA_EXAM_MANAGEMENT_PATH . 'includes/class-plugin.php';

register_activation_hook(__FILE__, array('Olama_Exam_Management_Plugin', 'activate'));
add_action('plugins_loaded', array('Olama_Exam_Management_Plugin', 'instance'), 20);
