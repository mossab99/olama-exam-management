<?php
/** Standalone regression checks: php tests/exam-dates.php */
define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');

function __($text, $domain = '') { return $text; }
function sanitize_text_field($text) { return trim((string) $text); }
function sanitize_textarea_field($text) { return trim((string) $text); }
function wp_unslash($value) { return stripslashes($value); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function expect_date($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
class WP_Error {
    public $code;
    private $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
class Olama_School_Permissions {
    public static function can($capability) { return true; }
}
class Olama_School_Helpers {
    public static function translate($text) { return $text; }
    public static function format_date($date) { return (new DateTimeImmutable($date))->format('d-m-Y'); }
}
class Olama_School_Academic {
    public static function get_semester_exam($id) {
        return (object) array('start_date' => '2026-10-18', 'end_date' => '2026-11-05');
    }
}

// Exercise the actual save/query code against an isolated database, never the site.
class Exam_Date_Test_DB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $db;
    public $writes = 0;
    public function __construct() {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE wp_olama_exams (
            id INTEGER PRIMARY KEY, academic_year_id INTEGER, semester_id INTEGER,
            semester_exam_id INTEGER, grade_id INTEGER, subject_id INTEGER,
            exam_date TEXT, status TEXT, description TEXT
        )');
        $this->db->exec('CREATE TABLE wp_olama_exam_attachments (exam_id INTEGER, file_status TEXT)');
    }
    public function prepare($sql, ...$values) {
        if (isset($values[0]) && is_array($values[0])) { $values = $values[0]; }
        return preg_replace_callback('/%[ds]/', function ($match) use (&$values) {
            $value = array_shift($values);
            return $match[0] === '%d' ? (string) intval($value) : $this->db->quote((string) $value);
        }, $sql);
    }
    public function get_row($sql, $format = null) {
        return $this->db->query($sql)->fetch($format === ARRAY_A ? PDO::FETCH_ASSOC : PDO::FETCH_OBJ) ?: null;
    }
    public function get_var($sql) { return $this->db->query($sql)->fetchColumn(); }
    public function insert($table, $fields) {
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($fields)) . ') VALUES (' .
            implode(',', array_fill(0, count($fields), '?')) . ')';
        $this->writes++;
        $this->db->prepare($sql)->execute(array_values($fields));
        return 1;
    }
    public function update($table, $fields, $where) {
        $assignments = array_map(function ($key) { return $key . ' = ?'; }, array_keys($fields));
        $conditions = array_map(function ($key) { return $key . ' = ?'; }, array_keys($where));
        $this->writes++;
        $this->db->prepare('UPDATE ' . $table . ' SET ' . implode(',', $assignments) .
            ' WHERE ' . implode(' AND ', $conditions))->execute(array_merge(array_values($fields), array_values($where)));
        return 1;
    }
}
require dirname(__DIR__) . '/includes/class-exam.php';
$wpdb = new Exam_Date_Test_DB();
$context = array('academic_year_id' => 1, 'semester_id' => 2, 'semester_exam_id' => 3, 'grade_id' => 11, 'subject_id' => 4);

$screenshot_dates = array(
    '25-10-2026' => array('2026-10-25', 'Sunday'),
    '27-10-2026' => array('2026-10-27', 'Tuesday'),
    '28-10-2026' => array('2026-10-28', 'Wednesday'),
    '01-11-2026' => array('2026-11-01', 'Sunday'),
    '03-11-2026' => array('2026-11-03', 'Tuesday'),
);
foreach (array('UTC', 'Asia/Amman', 'America/Los_Angeles') as $timezone) {
    date_default_timezone_set($timezone);
    foreach ($screenshot_dates as $display => list($iso, $weekday)) {
        expect_date(Olama_School_Exam::normalize_exam_date($display) === $iso, "$timezone: display date changed");
        expect_date(Olama_School_Exam::normalize_exam_date($iso) === $iso, "$timezone: ISO date changed");
        expect_date((new DateTimeImmutable($iso))->format('l') === $weekday, "$timezone: weekday mismatch");
    }
}

foreach (array('', '31-02-2026', '2026-02-29', '2026-13-01', '32-10-2026', '2026-00-10',
    '10/11/2026', '2026-10-25junk', "2026-10-25\0", array('2026-10-25'), null) as $invalid) {
    $result = Olama_School_Exam::save_exam($context + array('exam_date' => $invalid));
    expect_date(is_wp_error($result) && $result->code === 'invalid_exam_date', 'Invalid input was accepted');
}
expect_date($wpdb->writes === 0, 'Invalid input wrote to the database');
expect_date(Olama_School_Exam::normalize_exam_date('29-02-2028') === '2028-02-29', 'Valid leap day rejected');

// A regular form submission must save the same value as AJAX's ISO submission.
expect_date(Olama_School_Exam::save_exam($context + array('exam_date' => '01-11-2026')) === 1, 'Display date save failed');
expect_date(Olama_School_Exam::get_exam(1)->exam_date === '2026-11-01', 'Display date was not normalized on save');
expect_date(Olama_School_Exam::save_exam(array('id' => 1, 'exam_date' => '2026-11-03')) === true, 'ISO update failed');
expect_date(Olama_School_Exam::get_exam(1)->exam_date === '2026-11-03', 'ISO update changed the date');
expect_date(Olama_School_Exam::save_exam(array('id' => 1, 'description' => 'Updated material')) === true, 'Material update failed');
expect_date(Olama_School_Exam::get_exam(1)->exam_date === '2026-11-03', 'Material-only save overwrote the date');

$writes_before = $wpdb->writes;
foreach (array('17-10-2026', '06-11-2026') as $outside) {
    $result = Olama_School_Exam::save_exam(array('id' => 1, 'exam_date' => $outside));
    expect_date(is_wp_error($result) && $result->code === 'exam_date_out_of_range', 'Out-of-period date accepted');
}
expect_date($wpdb->writes === $writes_before, 'Out-of-period date wrote to the database');
foreach (array('18-10-2026', '05-11-2026') as $boundary) {
    expect_date(Olama_School_Exam::save_exam(array('id' => 1, 'exam_date' => $boundary)) === true, 'Inclusive period boundary rejected');
}

// The approval conflict query compares the normalized calendar date.
$result = Olama_School_Exam::save_exam(array('id' => 1, 'exam_date' => '31-10-2026', 'status' => 'draft'));
expect_date($result === true, 'Valid date rejected');
$wpdb->db->exec("UPDATE wp_olama_exams SET status = 'approved' WHERE id = 1");
$result = Olama_School_Exam::save_exam($context + array('exam_date' => '31-10-2026', 'status' => 'approved'));
expect_date(is_wp_error($result) && $result->code === 'duplicate_date', 'Normalized dates bypassed conflict detection');

// Exercise the real AJAX entry point so invalid input cannot be rolled over before validation.
function add_action($hook, $callback) {}
function wp_verify_nonce($nonce, $action) { return $nonce === 'test-nonce'; }
class Exam_Date_JSON extends RuntimeException {
    public $success;
    public $data;
    public function __construct($success, $data) { $this->success = $success; $this->data = $data; }
}
function wp_send_json_success($data) { throw new Exam_Date_JSON(true, $data); }
function wp_send_json_error($data, $status = null) { throw new Exam_Date_JSON(false, $data); }
require dirname(__DIR__) . '/includes/class-ajax.php';
$ajax = new Olama_Exam_Management_Ajax();
foreach (array('31-02-2026' => false, '01-11-2026' => true, '2026-11-03' => true) as $input => $valid) {
    $_POST = $context + array('nonce' => 'test-nonce', 'id' => 1, 'exam_date' => $input);
    $writes_before = $wpdb->writes;
    try {
        $ajax->save_exam();
        throw new RuntimeException('AJAX did not send a response');
    } catch (Exam_Date_JSON $response) {
        expect_date($response->success === $valid, 'AJAX accepted or rejected the wrong date');
        if ($valid) {
            $saved = Olama_School_Exam::get_exam(1)->exam_date;
            expect_date($response->data['exam_date'] === $saved, 'AJAX response differs from the stored date');
            expect_date($response->data['formatted_date'] === Olama_School_Helpers::format_date($saved), 'AJAX display differs from the stored date');
        } else {
            expect_date($wpdb->writes === $writes_before, 'Invalid AJAX input wrote to the database');
        }
    }
}

echo "Exam date regression checks passed (calendar parsing, direct and AJAX saves, period boundaries, partial updates, conflicts, three timezones).\n";
