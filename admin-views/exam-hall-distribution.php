<?php
/**
 * Exam Hall Distribution System – Admin View (Redesigned)
 * Canvas-based: grade/section first → add halls → distribute
 */

if (!defined('ABSPATH')) exit;

$can_manage = Olama_School_Permissions::can('olama_manage_exam_halls');
$can_attend = Olama_School_Permissions::can('olama_manage_hall_attendance');
$can_access = Olama_School_Permissions::can('olama_access_exam_halls');

if (!$can_access) wp_die(__('Unauthorized', 'olama-school'));

$active_tab = sanitize_text_field($_GET['tab'] ?? 'distribution');
if ($active_tab === 'distribution' && !$can_manage) $active_tab = 'attendance';
if ($active_tab === 'halls'        && !$can_manage) $active_tab = 'attendance';

$active_year = Olama_School_Academic::get_active_year();
$year_id     = $active_year ? $active_year->id : 0;
$grades      = Olama_School_Grade::get_grades();
$halls       = Olama_Exam_Hall::get_halls($year_id);

// Active semester
global $wpdb;
$active_semester    = null;
$active_semester_id = 0;
if ($year_id) {
    $active_semester    = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}olama_semesters
         WHERE academic_year_id = %d AND is_active = 1 LIMIT 1",
        $year_id
    ));
    $active_semester_id = $active_semester ? $active_semester->id : 0;
}

// Quick counts for stat bar
$total_capacity = array_sum(array_column($halls, 'capacity'));
$total_assigned = $year_id ? (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}olama_exam_hall_assignments
     WHERE academic_year_id = %d AND semester_id = %d",
    $year_id, $active_semester_id
)) : 0;

// Fetch all assigned invigilators for this context
$all_invigilators = Olama_Exam_Hall::get_all_assigned_invigilators($year_id, $active_semester_id);
$inv_by_hall = [];
foreach ($all_invigilators as $inv) {
    $inv_by_hall[$inv->hall_id][] = $inv->display_name;
}
?>

<div class="wrap olama-school-wrap eh-module-wrap">

    <!-- ─── TOP BAR ─────────────────────────────────────────────────────────── -->
    <div class="eh-top-bar">
        <h1 style="display:flex;align-items:center;gap:10px;margin:0;">
            <span class="dashicons dashicons-id-alt" style="font-size:26px;width:26px;height:26px;color:#1a73e8;"></span>
            نظام توزيع قاعات الاختبار
        </h1>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <?php if ($active_year): ?>
            <span class="eh-context-pill year">
                <span class="dashicons dashicons-calendar-alt"></span>
                <?php echo esc_html($active_year->year_name); ?>
            </span>
            <?php endif; ?>
            <?php if ($active_semester): ?>
            <span class="eh-context-pill semester">
                <span class="dashicons dashicons-book-alt"></span>
                <?php echo esc_html($active_semester->semester_name ?? 'الفصل الدراسي'); ?>
            </span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$year_id): ?>
        <div class="notice notice-warning"><p>⚠️ لا توجد سنة دراسية نشطة.</p></div>
    <?php endif; ?>

    <!-- ─── STATS BAR ─────────────────────────────────────────────────────── -->
    <div class="eh-stats-bar no-print">
        <div class="eh-stat-card halls">
            <div class="stat-icon"><span class="dashicons dashicons-building"></span></div>
            <div class="stat-info">
                <span class="stat-value" id="eh-stat-halls"><?php echo count($halls); ?></span>
                <span class="stat-label">القاعات</span>
            </div>
        </div>
        <div class="eh-stat-card students">
            <div class="stat-icon"><span class="dashicons dashicons-groups"></span></div>
            <div class="stat-info">
                <span class="stat-value" id="eh-stat-students"><?php echo $total_assigned; ?></span>
                <span class="stat-label">موزعون</span>
            </div>
        </div>
        <div class="eh-stat-card unassigned">
            <div class="stat-icon"><span class="dashicons dashicons-warning"></span></div>
            <div class="stat-info">
                <span class="stat-value" id="eh-stat-unassigned">0</span>
                <span class="stat-label">غير موزعين</span>
            </div>
        </div>
        <div class="eh-stat-card capacity">
            <div class="stat-icon"><span class="dashicons dashicons-chart-bar"></span></div>
            <div class="stat-info">
                <span class="stat-value"><?php echo $total_capacity; ?></span>
                <span class="stat-label">إجمالي السعة</span>
            </div>
        </div>
    </div>

    <!-- ─── TABS ─────────────────────────────────────────────────────────────── -->
    <h2 class="nav-tab-wrapper no-print">
        <?php if ($can_manage): ?>
        <a href="?page=olama-exam-halls&tab=distribution"
           class="nav-tab <?php echo $active_tab === 'distribution' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-layout"></span> توزيع الطلاب
        </a>
        <?php endif; ?>
        <?php if ($can_attend): ?>
        <a href="?page=olama-exam-halls&tab=attendance"
           class="nav-tab <?php echo $active_tab === 'attendance' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-list-view"></span> الحضور
        </a>
        <a href="?page=olama-exam-halls&tab=notes"
           class="nav-tab <?php echo $active_tab === 'notes' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-edit-page"></span> الملاحظات
        </a>
        <?php endif; ?>
        <?php if ($can_manage): ?>
        <a href="?page=olama-exam-halls&tab=halls"
           class="nav-tab <?php echo $active_tab === 'halls' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-admin-home"></span> القاعات
        </a>
        <?php endif; ?>
    </h2>

    <!-- ════════════════════════════════════════════════════════════════════════
         TAB 1 – DISTRIBUTION (Canvas-based)
         ════════════════════════════════════════════════════════════════════════ -->
    <?php if ($active_tab === 'distribution' && $can_manage): ?>

    <?php if (empty($halls)): ?>
        <div class="notice notice-warning" style="margin-top:16px;">
            <p>⚠️ لم يتم إضافة قاعات بعد. اذهب إلى تبويب <a href="?page=olama-exam-halls&tab=halls">القاعات</a> لإضافة قاعات أولاً.</p>
        </div>
    <?php else: ?>

    <!-- STEP 1: Context Selection ─────────────────────────────────────────── -->
    <div class="eh-planning-steps no-print">

        <div class="eh-step">
            <div class="eh-step-num">١</div>
            <div class="eh-step-body">
                <label class="eh-step-label">اختر الصف والشعبة / Context</label>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <select id="eh-filter-grade" class="eh-step-select">
                        <option value="">-- الصف / Grade --</option>
                        <?php foreach ($grades as $g): ?>
                        <option value="<?php echo $g->id; ?>"><?php echo esc_html($g->grade_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="eh-filter-section" class="eh-step-select">
                        <option value="">-- الشعبة / Section --</option>
                    </select>
                    <button id="btn-eh-load-students" class="eh-btn-premium secondary">
                        <span class="dashicons dashicons-search"></span>
                        <span class="btn-text">تحميل الطلاب (Load)</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="eh-step">
            <div class="eh-step-num">٢</div>
            <div class="eh-step-body">
                <label class="eh-step-label">إضافة القاعات / Halls setup</label>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <button id="btn-eh-add-hall" class="eh-btn-premium primary" <?php echo empty($halls) ? 'disabled' : ''; ?>>
                        <span class="dashicons dashicons-plus-alt2"></span>
                        <span class="btn-text">إضافة قاعة (Add)</span>
                    </button>
                    <div id="eh-canvas-badges" style="display:flex;gap:6px;flex-wrap:wrap;"></div>
                </div>
            </div>
        </div>

        <div class="eh-step">
            <div class="eh-step-num">٣</div>
            <div class="eh-step-body">
                <label class="eh-step-label">الإجراءات والتقارير / Actions & Reports</label>
                
                <!-- ROW 1: Execution Controls -->
                <div style="display:flex;gap:8px;margin-bottom:12px;">
                    <button id="btn-eh-auto-distribute" class="eh-btn-premium auto">
                        <span class="dashicons dashicons-randomize"></span>
                        <span class="btn-text">توزيع تلقائي (Auto)</span>
                        <span class="eh-spinner"></span>
                    </button>
                    <button id="btn-eh-clear-all" class="eh-btn-premium clear" title="مسح التوزيع">
                        <span class="dashicons dashicons-no-alt"></span>
                        <span class="btn-text">مسح الطلاب الموزعين (Clear)</span>
                    </button>
                </div>

                <!-- ROW 2: Report Grid -->
                <div class="eh-print-grid">
                    <button id="btn-eh-print-section" class="eh-report-btn">
                        <span class="dashicons dashicons-printer"></span>
                        <div class="lbl">طباعة الشعبة</div>
                        <div class="sub">Current Section</div>
                    </button>
                    <button id="btn-eh-print-hall-select" class="eh-report-btn">
                        <span class="dashicons dashicons-building"></span>
                        <div class="lbl">طباعة قاعة</div>
                        <div class="sub">Specific Hall</div>
                    </button>
                    <button id="btn-eh-print-all-halls" class="eh-report-btn">
                        <span class="dashicons dashicons-grid-view"></span>
                        <div class="lbl">كافة القاعات</div>
                        <div class="sub">All Canvas Halls</div>
                    </button>
                    <button id="btn-eh-print-all-sections" class="eh-report-btn">
                        <span class="dashicons dashicons-groups"></span>
                        <div class="lbl">كل الشعب</div>
                        <div class="sub">Global Report</div>
                    </button>
                </div>
            </div>
        </div>

    </div>

    <!-- Modal: Hall Selection for Printing -->
    <div id="eh-hall-print-modal" class="eh-modal-overlay">
        <div class="eh-modal">
            <div class="eh-modal-header">
                <h3>🏛 اختر القاعة للطباعة</h3>
                <button type="button" class="eh-modal-close">&times;</button>
            </div>
            <div class="eh-modal-body" id="eh-hall-print-list">
                <!-- Hall list injected via JS -->
            </div>
        </div>
    </div>

    <!-- Print header -->
    <div class="print-header" style="display:none;">
        <h2><?php bloginfo('name'); ?> — توزيع قاعات الاختبار</h2>
        <p>
            السنة: <?php echo esc_html($active_year->year_name ?? ''); ?> |
            الفصل: <?php echo esc_html($active_semester->semester_name ?? ''); ?> |
            <?php echo date('Y-m-d'); ?>
        </p>
    </div>

    <!-- WORK AREA ─────────────────────────────────────────────────────────── -->
    <div class="eh-work-area">

        <!-- Unassigned Panel -->
        <div id="eh-student-panel" class="eh-unassigned-panel">
            <div class="eh-unassigned-header">
                <h3><span class="dashicons dashicons-groups"></span> الطلاب</h3>
                <span class="eh-hall-capacity-badge" id="eh-student-count">0</span>
            </div>
            <div id="eh-student-panel-body" class="eh-unassigned-body">
                <p style="text-align:center;color:#9ca3af;padding:24px;font-size:13px;">
                    اختر الصف والشعبة<br>لعرض الطلاب
                </p>
            </div>
        </div>

        <!-- Canvas Grid -->
        <div id="eh-canvas-grid" class="eh-distribution-grid">
            <div class="eh-canvas-empty">
                <span class="dashicons dashicons-building" style="font-size:40px;width:40px;height:40px;color:#d1d5db;"></span>
                <p>أضف قاعات للبدء بالتوزيع<br><em>Add halls to start planning</em></p>
            </div>
        </div>

    </div>

    <?php endif; // halls check ?>

    <!-- ════════════════════════════════════════════════════════════════════════
         TAB 2 – ATTENDANCE
         ════════════════════════════════════════════════════════════════════════ -->
    <?php elseif ($active_tab === 'attendance' && $can_attend): ?>

    <div style="margin-top:16px;">
        <div class="eh-filter-bar no-print">
            <div>
                <label><strong>القاعة</strong></label><br>
                <select id="eh-att-hall" style="height:34px;border-radius:6px;border:1px solid #d1d5db;min-width:200px;">
                    <option value="">-- اختر القاعة --</option>
                    <?php foreach ($halls as $h): ?>
                    <option value="<?php echo $h->id; ?>"><?php echo esc_html($h->hall_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label><strong>التاريخ</strong></label><br>
                <input type="date" id="eh-att-date" value="<?php echo date('Y-m-d'); ?>"
                       style="height:34px;border-radius:6px;border:1px solid #d1d5db;padding:0 8px;">
            </div>
            <div>
                <label><strong>الجلسة</strong></label><br>
                <input type="text" id="eh-att-session" placeholder="مثال: الأول صباح"
                       style="height:34px;border-radius:6px;border:1px solid #d1d5db;padding:0 8px;min-width:200px;">
            </div>
            <div style="margin-top:auto;">
                <button class="button" onclick="loadAttendance();">
                    <span class="dashicons dashicons-search"></span> تحميل
                </button>
            </div>
        </div>

        <div id="eh-att-table-wrap" style="display:none;margin-top:16px;">
            <div class="print-header" style="display:none;text-align:center;margin-bottom:20px;">
                <h2><?php bloginfo('name'); ?></h2>
                <h3>كشف الحضور — قاعة الاختبار</h3>
                <p>الفصل: <?php echo esc_html($active_semester->semester_name ?? ''); ?> | التاريخ: <span id="print-att-date"></span> | الجلسة: <span id="print-att-session"></span></p>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;" class="no-print">
                <div style="display:flex;gap:8px;">
                    <button class="button" id="btn-mark-all-present">✓ تحديد الكل حاضر</button>
                    <button class="button" id="btn-mark-all-absent">✕ تحديد الكل غائب</button>
                </div>
                <div style="display:flex;gap:8px;">
                    <button id="btn-eh-save-attendance" class="button button-primary">
                        <span class="dashicons dashicons-saved btn-text"></span>
                        <span class="btn-text">حفظ الحضور</span>
                        <span class="eh-spinner"></span>
                    </button>
                    <button onclick="window.print()" class="btn-eh-print">
                        <span class="dashicons dashicons-printer"></span> طباعة
                    </button>
                </div>
            </div>

            <table class="eh-attendance-table widefat">
                <thead>
                    <tr>
                        <th width="60">المقعد</th>
                        <th>اسم الطالب</th>
                        <th>الصف / الشعبة</th>
                        <th width="220">الحضور</th>
                    </tr>
                </thead>
                <tbody id="eh-att-tbody">
                    <tr><td colspan="4" style="text-align:center;padding:20px;color:#6b7280;">اختر قاعة وتاريخاً</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════════
         TAB 3 – BEHAVIOR NOTES
         ════════════════════════════════════════════════════════════════════════ -->
    <?php elseif ($active_tab === 'notes' && $can_attend): ?>

    <div style="margin-top:16px;display:grid;grid-template-columns:360px 1fr;gap:20px;">
        <div class="card" style="padding:20px;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,.06);border:none;">
            <h3 style="margin:0 0 16px;"><span class="dashicons dashicons-plus-alt2" style="color:#1a73e8;"></span> إضافة ملاحظة</h3>
            <form id="eh-note-form">
                <div class="eh-form-row">
                    <label>القاعة</label>
                    <select id="eh-notes-hall" required>
                        <option value="">-- اختر القاعة --</option>
                        <?php foreach ($halls as $h): ?>
                        <option value="<?php echo $h->id; ?>"><?php echo esc_html($h->hall_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="eh-form-row">
                    <label>التاريخ</label>
                    <input type="date" id="eh-notes-date" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="eh-form-row">
                    <label>الطالب</label>
                    <select id="eh-notes-student" required>
                        <option value="">-- اختر القاعة أولاً --</option>
                    </select>
                </div>
                <div class="eh-form-row">
                    <label>نوع الملاحظة</label>
                    <select id="eh-note-type">
                        <?php foreach (Olama_Exam_Hall::get_note_types() as $val => $label): ?>
                        <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="eh-form-row">
                    <label>تفاصيل (اختياري)</label>
                    <textarea id="eh-note-text" rows="3" placeholder="تفاصيل إضافية..."></textarea>
                </div>
                <button type="submit" class="button button-primary widefat" style="height:40px;">
                    <span class="btn-text">حفظ الملاحظة</span>
                    <span class="eh-spinner"></span>
                </button>
            </form>
        </div>

        <div>
            <h3 style="margin:0 0 12px;">الملاحظات المسجلة</h3>
            <div id="eh-notes-list" class="eh-notes-list">
                <?php
                $all_notes = [];
                foreach ($halls as $h) {
                    $notes = Olama_Exam_Hall::get_notes($h->id, null, $active_semester_id);
                    foreach ($notes as $n) { $n->hall_name = $h->hall_name; $all_notes[] = $n; }
                }
                if (empty($all_notes)):
                ?>
                <p style="text-align:center;color:#6b7280;padding:30px;background:#f8fafc;border-radius:10px;">
                    لا توجد ملاحظات بعد
                </p>
                <?php else: foreach ($all_notes as $note): ?>
                <div class="eh-note-card <?php echo esc_attr($note->note_type); ?>">
                    <div>
                        <span class="eh-note-type-badge <?php echo esc_attr($note->note_type); ?>"><?php echo esc_html($note->note_type); ?></span>
                        <strong style="display:block;margin-top:4px;"><?php echo esc_html($note->student_name); ?></strong>
                        <span style="font-size:11px;color:#6b7280;"><?php echo esc_html($note->hall_name); ?> — <?php echo esc_html($note->exam_date); ?></span>
                        <?php if ($note->note_text): ?>
                        <p style="margin:4px 0 0;font-size:13px;"><?php echo esc_html($note->note_text); ?></p>
                        <?php endif; ?>
                    </div>
                    <button class="button btn-eh-delete-note" data-note-id="<?php echo $note->id; ?>" style="color:#dc2626;border:none;background:none;">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════════
         TAB 4 – HALL MANAGEMENT
         ════════════════════════════════════════════════════════════════════════ -->
    <?php elseif ($active_tab === 'halls' && $can_manage): ?>

    <div style="margin-top:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h2 style="margin:0;">إدارة القاعات</h2>
            <button id="btn-eh-add-hall-form" class="button button-primary" style="height:38px;display:flex;align-items:center;gap:6px;">
                <span class="dashicons dashicons-plus-alt"></span> إضافة قاعة
            </button>
        </div>

        <?php if (empty($halls)): ?>
        <div style="text-align:center;padding:60px;background:#f8fafc;border-radius:14px;border:2px dashed #e5e7eb;">
            <span class="dashicons dashicons-building" style="font-size:48px;width:48px;height:48px;color:#d1d5db;"></span>
            <p style="color:#6b7280;margin-top:16px;">لا توجد قاعات مضافة بعد</p>
            <button class="button button-primary" style="margin-top:12px;" onclick="document.getElementById('btn-eh-add-hall-form').click();">
                إضافة أول قاعة
            </button>
        </div>
        <?php else: ?>
        <div id="eh-halls-manage-grid" class="eh-halls-grid">
            <?php foreach ($halls as $h):
                $cnt = Olama_Exam_Hall::get_hall_count($h->id, $year_id, $active_semester_id);
                $pct = $h->capacity > 0 ? min(100, round($cnt / $h->capacity * 100)) : 0;
            ?>
            <div class="eh-hall-manage-card">
                <div class="hall-name"><span class="dashicons dashicons-building" style="color:#1a73e8;"></span> <?php echo esc_html($h->hall_name); ?></div>
                <div class="hall-cap">السعة: <strong><?php echo $h->capacity; ?></strong> طالب</div>
                <div style="font-size:12px;color:#6b7280;">الموزعون: <strong style="color:#1a73e8;"><?php echo $cnt; ?></strong></div>
                
                <!-- Invigilators List -->
                <div style="margin-top:10px;">
                    <span class="eh-hall-inv-label">المراقبون:</span>
                    <div class="eh-hall-inv-list" data-hall-id="<?php echo $h->id; ?>">
                        <?php 
                        $hall_invs = $inv_by_hall[$h->id] ?? [];
                        if (!empty($hall_invs)): 
                            foreach ($hall_invs as $name): ?>
                                <span class="eh-hall-inv-badge"><?php echo esc_html($name); ?></span>
                            <?php endforeach;
                        else: ?>
                            <small style="color:#94a3b8;">لا يوجد مراقبون</small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="eh-hall-progress">
                    <div class="eh-hall-progress-fill <?php echo $pct >= 100 ? 'full' : ''; ?>" style="width:<?php echo $pct; ?>%;"></div>
                </div>
                <div class="hall-actions">
                    <button class="button btn-eh-edit-hall" data-hall-id="<?php echo $h->id; ?>"
                            data-hall-name="<?php echo esc_attr($h->hall_name); ?>"
                            data-hall-cap="<?php echo $h->capacity; ?>">
                        <span class="dashicons dashicons-edit"></span> تعديل
                    </button>
                    <button class="button btn-eh-manage-invigilators" data-hall-id="<?php echo $h->id; ?>" data-hall-name="<?php echo esc_attr($h->hall_name); ?>">
                        <span class="dashicons dashicons-id-alt"></span> المراقبين
                    </button>
                    <button class="button btn-eh-delete-hall" style="color:#dc2626;"
                            data-hall-id="<?php echo $h->id; ?>"
                            data-hall-name="<?php echo esc_attr($h->hall_name); ?>">
                        <span class="dashicons dashicons-trash"></span> حذف
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php endif; // tab content ?>

</div>

<!-- ════ MODAL: Hall Picker (add to canvas) ════════════════════════════════ -->
<div class="eh-modal-overlay" id="eh-hall-picker-modal">
    <div class="eh-modal" style="max-width:460px;">
        <button class="eh-modal-close" type="button">✕</button>
        <h3><span class="dashicons dashicons-building" style="color:#1a73e8;"></span> اختر قاعة لاضافة الطلاب</h3>
        <div id="eh-hall-picker-list" style="max-height:360px;overflow-y:auto;display:flex;flex-direction:column;gap:8px;margin-top:12px;">
            <!-- Populated by JS -->
        </div>
    </div>
</div>

<!-- ════ MODAL: Add / Edit Hall ═══════════════════════════════════════════ -->
<div class="eh-modal-overlay" id="eh-hall-modal">
    <div class="eh-modal">
        <button class="eh-modal-close" type="button">✕</button>
        <h3><span class="dashicons dashicons-building" style="color:#1a73e8;"></span> إضافة / تعديل قاعة</h3>
        <form id="eh-hall-form">
            <input type="hidden" id="eh-hall-id">
            <div class="eh-form-row">
                <label>اسم القاعة <span style="color:#ef4444">*</span></label>
                <input type="text" id="eh-hall-name" placeholder="مثال: قاعة أ" required>
            </div>
            <div class="eh-form-row">
                <label>السعة <span style="color:#ef4444">*</span></label>
                <input type="number" id="eh-hall-capacity" min="1" max="500" value="30" required>
            </div>
            <button type="submit" class="button button-primary widefat" style="height:40px;margin-top:8px;">
                <span class="btn-text">حفظ القاعة</span>
                <span class="eh-spinner"></span>
            </button>
        </form>
    </div>
</div>

<!-- ════ MODAL: Manage Invigilators ════════════════════════════════════════ -->
<div class="eh-modal-overlay" id="eh-invigilator-modal">
    <div class="eh-modal" style="max-width:500px;">
        <button class="eh-modal-close" type="button">✕</button>
        <h3><span class="dashicons dashicons-id-alt" style="color:#1a73e8;"></span> إدارة المراقبين: <span id="eh-inv-hall-name"></span></h3>
        
        <div class="eh-inv-current-section">
            <h4 style="margin:0 0 10px;font-size:14px;border-bottom:1px solid #eee;padding-bottom:5px;">المراقبون الحاليون (بحد أقصى ٣)</h4>
            <div id="eh-inv-current-list" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:15px;min-height:40px;">
                <!-- Current invigilators tags -->
            </div>
        </div>

        <div class="eh-inv-select-section">
            <h4 style="margin:0 0 10px;font-size:14px;">إضافة مراقب جديد</h4>
            <div style="display:flex;gap:8px;margin-bottom:12px;">
                <select id="eh-inv-picker" style="flex:1;height:38px;">
                    <option value="">-- اختر المعلم / الإداري --</option>
                </select>
                <button id="btn-eh-add-invigilator" class="button button-primary" style="height:38px;">
                    إضافة
                </button>
            </div>
            <p style="font-size:11px;color:#6b7280;">* لا يمكن للمراقب أن يكون في أكثر من قاعة في نفس الوقت.</p>
        </div>
    </div>
</div>

