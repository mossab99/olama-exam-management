<?php
/**
 * Exam Schedule View - Modal Layout
 */
if (!defined('ABSPATH')) {
    exit;
}

// Force active year and semester
$active_year = Olama_School_Academic::get_active_year();
$selected_year_id = $active_year ? intval($active_year->id) : 0;

$active_semester = Olama_School_Academic::get_active_semester($selected_year_id);
$selected_semester_id = $active_semester ? intval($active_semester->id) : 0;

// Re-fetch semesters for the active year to ensure consistency
$semesters = Olama_School_Academic::get_semesters($selected_year_id);

// Get semester exams for the dropdown - filtered by grade if selected
$semester_exams = Olama_School_Academic::get_semester_exams($selected_semester_id, $selected_grade_id);
$active_exam = Olama_School_Academic::get_active_exam($selected_semester_id);
$exams = Olama_School_Exam::get_exams($selected_year_id, $selected_semester_id, $selected_grade_id, $selected_subject_id, $selected_semester_exam_id);

// Get current master exam details if selected
$current_master_exam = null;
if ($selected_semester_exam_id) {
    foreach ($semester_exams as $se) {
        if ($se->id == $selected_semester_exam_id) {
            $current_master_exam = $se;
            break;
        }
    }
}
?>

<div class="wrap olama-exam-wrap">
    <div class="olama-header-section"
        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1 style="margin: 0;"><?php echo Olama_School_Helpers::translate('Exam Schedule'); ?></h1>
        <div style="display: flex; gap: 10px;">
            <button type="button" id="header-bulk-add-subjects" class="button button-secondary" <?php echo (!$selected_semester_exam_id || !$selected_grade_id) ? 'disabled' : ''; ?>>
                <span class="dashicons dashicons-database-add" style="margin-top: 4px;"></span>
                <?php echo Olama_School_Helpers::translate('Init All Subjects'); ?>
            </button>
            <?php if (Olama_School_Permissions::can('olama_manage_exams_schedule') && !empty($exams)): ?>
                <?php
                $archive_url = add_query_arg(array(
                    'action' => 'olama_download_all_exams_zip',
                    'academic_year_id' => $selected_year_id,
                    'semester_id' => $selected_semester_id,
                    'semester_exam_id' => $selected_semester_exam_id,
                    'grade_id' => $selected_grade_id,
                ), admin_url('admin-ajax.php'));
                $archive_url = wp_nonce_url($archive_url, 'olama_download_all_exams_zip');
                ?>
                <a href="<?php echo esc_url($archive_url); ?>"
                    class="button button-primary"
                    style="display: flex; align-items: center; gap: 5px; background: #6366f1; border-color: #4f46e5;">
                    <span class="dashicons dashicons-archive" style="margin-top: 4px;"></span>
                    <?php echo Olama_School_Helpers::translate('Download All Approved'); ?>
                </a>
            <?php endif; ?>
            <button type="button" id="open-add-exam-modal" class="button button-primary"
                style="display: flex; align-items: center; gap: 5px;">
                <span class="dashicons dashicons-plus-alt" style="margin-top: 4px;"></span>
                <?php echo Olama_School_Helpers::translate('Add Exam Subject'); ?>
            </button>
        </div>
    </div>

    <?php if (isset($_GET['message']) && $_GET['message'] === 'exam_saved'): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo Olama_School_Helpers::translate('Exam saved successfully.'); ?></p>
        </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="olama-filter-bar"
        style="background: #fff; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 25px;">
        <form method="get" action="" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
            <input type="hidden" name="page" value="olama-exam-management">
            <input type="hidden" name="tab" value="exam_schedule">

            <?php
            // Academic Year locked filter
            $year_name = '—';
            foreach ($years as $y) {
                if (intval($y->id) === $selected_year_id) {
                    $year_name = $y->year_name;
                    break;
                }
            }
            echo Olama_School_Helpers::locked_filter_render(Olama_School_Helpers::translate('Active Year'), $year_name, 'academic_year_id', $selected_year_id);

            // Semester locked filter
            $semester_name = '—';
            foreach ($semesters as $s) {
                if (intval($s->id) === $selected_semester_id) {
                    $semester_name = $s->semester_name;
                    break;
                }
            }
            echo Olama_School_Helpers::locked_filter_render(Olama_School_Helpers::translate('Active Semester'), $semester_name, 'semester_id', $selected_semester_id);
            ?>

            <?php
            // Active Exam locked filter
            $exam_name = Olama_School_Helpers::translate('Choose Exam');
            if ($active_exam) {
                $exam_name = $active_exam->exam_name;
            }
            echo Olama_School_Helpers::locked_filter_render(Olama_School_Helpers::translate('Active Exam'), $exam_name, 'semester_exam_id', $selected_semester_exam_id);
            ?>

            <div class="olama-filter-item" style="flex: 1; min-width: 150px;">
                <label class="olama-label"><?php echo Olama_School_Helpers::translate('Grade'); ?></label>
                <select name="grade_id" class="olama-select" onchange="this.form.submit();">
                    <?php foreach ($grades as $g): ?>
                        <option value="<?php echo $g->id; ?>" <?php selected($selected_grade_id, $g->id); ?>>
                            <?php echo esc_html($g->grade_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="button button-secondary" style="height: 42px; padding: 0 20px;">
                <?php echo Olama_School_Helpers::translate('Search'); ?>
            </button>
        </form>
    </div>

    <?php if ($current_master_exam): ?>
        <div class="olama-exam-context"
            style="background: #fdf2f2; border: 1px solid #fecaca; padding: 12px 20px; border-radius: 6px; margin-bottom: 20px; display: flex; gap: 40px; align-items: center;">
            <div>
                <span style="color: #ef4444; font-weight: 700; margin-left: 8px;"><span
                        class="dashicons dashicons-location-alt" style="font-size: 18px; margin-top:2px;"></span>
                    <?php echo Olama_School_Helpers::translate('Room'); ?>:</span>
                <span
                    style="font-weight: 800; color: #1e293b; font-size: 15px;"><?php echo esc_html($current_master_exam->room_number ?: __('Not Assigned', 'olama-school')); ?></span>
            </div>
            <div>
                <span style="color: #ef4444; font-weight: 700; margin-left: 8px;"><span
                        class="dashicons dashicons-calendar-alt" style="font-size: 18px; margin-top:2px;"></span>
                    <?php echo Olama_School_Helpers::translate('Allowed Period'); ?>:</span>
                <span style="font-weight: 800; color: #1e293b; font-size: 15px;">
                    <?php echo Olama_School_Helpers::format_date($current_master_exam->start_date); ?>
                    <span style="margin: 0 5px;">-</span>
                    <?php echo Olama_School_Helpers::format_date($current_master_exam->end_date); ?>
                </span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Full Width Table -->
    <div class="olama-table-container">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="font-weight: 600; width: 25%;"><?php echo Olama_School_Helpers::translate('Subject'); ?>
                    </th>
                    <th style="font-weight: 600; width: 15%;"><?php echo Olama_School_Helpers::translate('Date'); ?>
                    </th>
                    <th style="font-weight: 600; width: 35%;">
                        <?php echo Olama_School_Helpers::translate('Supervisor Comments'); ?>
                    </th>
                    <th style="font-weight: 600; width: 10%;"><?php echo Olama_School_Helpers::translate('Status'); ?>
                    </th>
                    <th style="width: 200px; text-align: center; font-weight: 600;">
                        <?php echo Olama_School_Helpers::translate('Actions'); ?>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($exams)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: #666;">
                            <?php echo Olama_School_Helpers::translate('No exams found for the selected criteria.'); ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($exams as $exam):
                        $exam->formatted_date = Olama_School_Helpers::format_date($exam->exam_date);

                        $status = $exam->status;
                        if ($status === 'approved') {
                            $status_label = Olama_School_Helpers::translate('Approved');
                            $status_color = '#10b981';
                        } elseif ($status === 'completed') {
                            $status_label = Olama_School_Helpers::translate('Completed');
                            $status_color = '#3b82f6';
                        } else {
                            $status_label = Olama_School_Helpers::translate('Not Completed');
                            $status_color = '#f59e0b';
                        }
                        ?>
                        <tr>
                            <td>
                                <strong><?php
                                $sb_info = Olama_School_Subject::get_subject($exam->subject_id);
                                echo $sb_info ? esc_html($sb_info->subject_name) : __('Unknown', 'olama-school');
                                ?></strong>
                                <br>
                                <small style="color: #64748b;"><?php
                                $se_info = array_filter($semester_exams, function ($e) use ($exam) {
                                    return $e->id == $exam->semester_exam_id;
                                });
                                $se_info = reset($se_info);
                                echo $se_info ? esc_html($se_info->exam_name) : esc_html($exam->evaluation_type);
                                ?></small>
                            </td>
                            <td class="date-column">
                                <div class="date-display"><?php echo $exam->formatted_date; ?></div>
                                <div class="date-edit" style="display: none;">
                                    <input type="text" class="olama-datepicker inline-date-input"
                                        data-exam-id="<?php echo $exam->id; ?>"
                                        data-year-id="<?php echo $exam->academic_year_id; ?>"
                                        data-semester-id="<?php echo $exam->semester_id; ?>"
                                        data-grade-id="<?php echo $exam->grade_id; ?>"
                                        data-subject-id="<?php echo $exam->subject_id; ?>"
                                        data-semester-exam-id="<?php echo $exam->semester_exam_id; ?>"
                                        value="<?php echo $exam->formatted_date; ?>"
                                        style="width: 100px; padding: 4px; font-size: 12px;">
                                    <div class="inline-actions" style="margin-top: 5px;">
                                        <button type="button"
                                            class="button button-primary button-small save-inline-date"><?php _e('Save', 'olama-school'); ?></button>
                                        <button type="button"
                                            class="button button-small cancel-inline-date"><?php _e('Cancel', 'olama-school'); ?></button>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($exam->supervisor_comments)): ?>
                                    <div style="font-size: 12px; color: #475569; font-style: italic; white-space: pre-wrap;">
                                        <?php echo esc_html($exam->supervisor_comments); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span
                                    style="display:inline-block; padding: 2px 8px; border-radius: 12px; background: <?php echo $status_color; ?>22; color: <?php echo $status_color; ?>; font-size: 11px; font-weight: 600;">
                                    <?php echo $status_label; ?>
                                </span>
                            </td>
                            <td style="text-align: center; white-space: nowrap;">
                                <?php if ($exam->status === 'completed'): ?>
                                    <button type="button" class="button button-small approve-exam"
                                        data-exam-id="<?php echo $exam->id; ?>"
                                        data-year-id="<?php echo $exam->academic_year_id; ?>"
                                        data-semester-id="<?php echo $exam->semester_id; ?>"
                                        data-grade-id="<?php echo $exam->grade_id; ?>"
                                        data-subject-id="<?php echo $exam->subject_id; ?>"
                                        data-exam-date="<?php echo $exam->exam_date; ?>"
                                        title="<?php echo Olama_School_Helpers::translate('Approve'); ?>"
                                        style="background: #10b981; color: #fff; border-color: #059669;">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="button button-small edit-exam-date-inline"
                                    title="<?php echo Olama_School_Helpers::translate('Edit Date'); ?>">
                                    <span class="dashicons dashicons-calendar-alt"></span>
                                </button>
                                <button type="button" class="button button-small edit-exam-material"
                                    data-exam='<?php echo esc_attr(json_encode($exam)); ?>'
                                    title="<?php echo Olama_School_Helpers::translate('Exam Material'); ?>">
                                    <span class="dashicons dashicons-media-text"></span>
                                </button>
                                <button type="button" class="button button-small open-upload-modal"
                                    data-exam-id="<?php echo $exam->id; ?>"
                                    title="<?php echo Olama_School_Helpers::translate('Upload Exam File'); ?>">
                                    <span class="dashicons dashicons-upload"></span>
                                </button>
                                <?php if ($exam->attachment_id): ?>
                                    <a href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=olama_download_exam_file&exam_id=' . $exam->id), 'olama_download_file_' . $exam->id); ?>"
                                        class="button button-small"
                                        title="<?php echo Olama_School_Helpers::translate('Download Exam'); ?>"
                                        style="background: #e1effe; color: #1e429f;">
                                        <span class="dashicons dashicons-download"></span>
                                    </a>
                                <?php endif; ?>
                                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=olama-exam-management&tab=exam_schedule&action=delete_exam&exam_id=' . $exam->id), 'olama_delete_exam_' . $exam->id); ?>"
                                    class="button button-small"
                                    onclick="return confirm('<?php echo Olama_School_Helpers::translate('Are you sure?'); ?>')"
                                    title="<?php echo Olama_School_Helpers::translate('Delete'); ?>">
                                    <span class="dashicons dashicons-trash"></span>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Upload Modal -->
    <div id="upload-modal" class="olama-modal" style="display: none;">
        <div class="olama-modal-content" style="max-width: 500px;">
            <div class="olama-modal-header">
                <h2><?php echo Olama_School_Helpers::translate('Upload Exam File'); ?></h2>
                <span class="olama-modal-close" onclick="closeModal('upload-modal')">&times;</span>
            </div>
            <div style="padding: 20px;">
                <input type="hidden" id="upload_exam_id">
                <div id="attachment-container">
                    <!-- Status Info -->
                    <div id="attachment-status"
                        style="margin-bottom: 20px; display: none; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span class="dashicons dashicons-media-document" style="color: #64748b;"></span>
                            <span id="attachment-filename"
                                style="font-weight: 500; color: #1e293b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 200px;"></span>
                            <span id="attachment-badge" class="olama-badge" style="font-size: 11px;"></span>
                        </div>
                        <div style="margin-top: 15px; display: flex; gap: 10px;">
                            <a href="#" id="download-attachment-btn" class="button button-secondary">
                                <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                                <?php echo Olama_School_Helpers::translate('Download File'); ?>
                            </a>
                            <button type="button" id="delete-attachment-btn" class="button button-link-delete"
                                style="color: #ef4444;">
                                <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                                <?php echo Olama_School_Helpers::translate('Delete File'); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Upload Input -->
                    <div id="attachment-upload-section"
                        style="display: none; background: #f0f9ff; padding: 20px; border-radius: 8px; border: 1px solid #bae6fd;">
                        <h4 style="margin-top: 0; color: #0369a1;">
                            <?php echo Olama_School_Helpers::translate('Upload New File'); ?></h4>
                        <input type="file" id="exam_file_input" accept=".docx,.pdf"
                            style="margin-bottom: 15px; width: 100%;">
                        <button type="button" id="upload-exam-btn" class="button button-primary" style="width: 100%;">
                            <span class="dashicons dashicons-upload" style="vertical-align: middle;"></span>
                            <?php echo Olama_School_Helpers::translate('Upload File'); ?>
                        </button>
                        <p class="description" style="margin-top: 10px;">
                            <?php echo Olama_School_Helpers::translate('Only .docx and .pdf files are accepted.'); ?>
                        </p>
                    </div>

                    <div id="attachment-loading" style="text-align: center; padding: 20px; display: none;">
                        <span class="dashicons dashicons-update spin"></span>
                        <?php echo Olama_School_Helpers::translate('Loading...'); ?>
                    </div>
                </div>
            </div>
            <div class="olama-modal-footer">
                <button type="button" class="button"
                    onclick="closeModal('upload-modal')"><?php echo Olama_School_Helpers::translate('Close'); ?></button>
            </div>
        </div>
    </div>

    <!-- Material Edit Modal -->
    <div id="material-modal" class="olama-modal" style="display: none;">
        <div class="olama-modal-content" style="max-width: 800px;">
            <div class="olama-modal-header">
                <h2><?php echo Olama_School_Helpers::translate('Exam Material & Details'); ?></h2>
                <span class="olama-modal-close" onclick="closeModal('material-modal')">&times;</span>
            </div>
            <form id="material-form" method="post">
                <?php wp_nonce_field('olama_save_exam', 'olama_material_nonce_field'); ?>
                <input type="hidden" name="id" id="material_exam_id">
                <input type="hidden" name="academic_year_id" id="material_academic_year_id">
                <input type="hidden" name="semester_id" id="material_semester_id">
                <input type="hidden" name="grade_id" id="material_grade_id">
                <input type="hidden" name="subject_id" id="material_subject_id">
                <input type="hidden" name="semester_exam_id" id="material_semester_exam_id">
                <input type="hidden" name="exam_material_json" id="exam_material_json">
                <input type="hidden" name="action" value="olama_save_exam">

                <div style="padding: 20px; max-height: 70vh; overflow-y: auto;">
                    <!-- Description Section -->
                    <div class="material-section">
                        <h3
                            style="margin: 0 0 10px; font-size: 14px; color: #475569; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px;">
                            <?php echo Olama_School_Helpers::translate('Exam Description'); ?>
                        </h3>
                        <textarea name="description" id="material_description" rows="2"
                            style="width: 100%; margin-bottom: 10px;"
                            placeholder="<?php echo Olama_School_Helpers::translate('Enter general description or topic...'); ?>"></textarea>
                    </div>

                    <!-- Curriculum Material Section -->
                    <div class="material-section">
                        <h3
                            style="margin: 0 0 15px; font-size: 14px; color: #475569; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px;">
                            <?php echo Olama_School_Helpers::translate('Curriculum Material'); ?>
                        </h3>

                        <div id="curriculum-blocks-container">
                            <!-- Unit blocks will be added here dynamically -->
                        </div>

                        <button type="button" id="add-unit-btn" class="button button-secondary"
                            style="margin-top: 15px;">
                            <span class="dashicons dashicons-plus-alt2" style="vertical-align: middle;"></span>
                            <?php echo Olama_School_Helpers::translate('Add Unit'); ?>
                        </button>
                    </div>

                    <!-- Booklets & Notebooks Section -->
                    <div class="material-section" style="margin-top: 20px;">
                        <h3
                            style="margin: 0 0 10px; font-size: 14px; color: #475569; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px;">
                            <?php echo Olama_School_Helpers::translate('Booklets & Notebooks'); ?>
                        </h3>
                        <textarea name="notebook_material" id="material_notebook_material" rows="3" style="width: 100%;"
                            placeholder="<?php echo Olama_School_Helpers::translate('e.g., Worksheet #3, Notebook entries from week 2'); ?>"></textarea>
                    </div>

                    <!-- Teacher Notes Section -->
                    <div class="material-section" style="margin-top: 20px;">
                        <h3
                            style="margin: 0 0 10px; font-size: 14px; color: #475569; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px;">
                            <?php echo Olama_School_Helpers::translate('Teacher Notes'); ?>
                        </h3>
                        <textarea name="teacher_notes" id="material_teacher_notes" rows="2"
                            style="width: 100%;"></textarea>
                    </div>


                    <!-- Supervisor Comments Section -->
                    <div class="material-section" style="margin-top: 20px;">
                        <h3
                            style="margin: 0 0 10px; font-size: 14px; color: #475569; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px;">
                            <?php echo Olama_School_Helpers::translate('Supervisor Comments'); ?>
                        </h3>
                        <textarea name="supervisor_comments" id="material_supervisor_comments" rows="2"
                            style="width: 100%;"
                            placeholder="<?php echo Olama_School_Helpers::translate('Add notes for the teacher...'); ?>"></textarea>
                    </div>

                    <!-- Status -->
                    <div class="form-field" style="margin-top: 20px;">
                        <label
                            style="font-weight: 600; font-size: 13px; color: #475569;"><?php echo Olama_School_Helpers::translate('Status'); ?></label>
                        <select name="status" id="material_status" style="margin-top: 5px;">
                            <option value="draft">
                                <?php echo Olama_School_Helpers::translate('Draft / Not Completed'); ?>
                            </option>
                            <option value="completed"><?php echo Olama_School_Helpers::translate('Completed'); ?>
                            </option>
                            <option value="approved"><?php echo Olama_School_Helpers::translate('Approved'); ?>
                            </option>
                        </select>
                    </div>
                </div>

                <div class="olama-modal-footer">
                    <button type="submit"
                        class="button button-primary"><?php echo Olama_School_Helpers::translate('Save Material'); ?></button>
                    <button type="button" class="button"
                        onclick="closeModal('material-modal')"><?php echo Olama_School_Helpers::translate('Cancel'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Subject Modal (Legacy compatibility or new subject) -->
    <div id="exam-modal" class="olama-modal" style="display: none;">
        <div class="olama-modal-content">
            <div class="olama-modal-header">
                <h2 id="form-title"><?php echo Olama_School_Helpers::translate('Add Exam Subject'); ?></h2>
                <span class="olama-modal-close" onclick="closeModal('exam-modal')">&times;</span>
            </div>

            <form id="exam-form" method="post">
                <?php wp_nonce_field('olama_save_exam', 'olama_exam_nonce_field'); ?>
                <input type="hidden" name="olama_save_exam" value="1">
                <input type="hidden" name="id" id="exam_id" value="">
                <input type="hidden" name="academic_year_id" value="<?php echo $selected_year_id; ?>">
                <input type="hidden" name="semester_id" value="<?php echo $selected_semester_id; ?>">
                <input type="hidden" name="grade_id" value="<?php echo $selected_grade_id; ?>">

                <div class="olama-form-grid">
                    <div class="form-field">
                        <label><?php echo Olama_School_Helpers::translate('Active Exam'); ?> *</label>
                        <select name="semester_exam_id" id="add_semester_exam_id" required>
                            <option value=""><?php echo Olama_School_Helpers::translate('Choose'); ?></option>
                            <?php foreach ($semester_exams as $se): ?>
                                <option value="<?php echo $se->id; ?>"><?php echo esc_html($se->exam_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-field">
                        <label><?php echo Olama_School_Helpers::translate('Subject'); ?> *</label>
                        <select name="subject_id" required>
                            <option value=""><?php echo Olama_School_Helpers::translate('Choose'); ?></option>
                            <?php foreach ($subjects as $sb): ?>
                                <option value="<?php echo $sb->id; ?>"><?php echo esc_html($sb->subject_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-field">
                        <label><?php echo Olama_School_Helpers::translate('Exam Date'); ?> *</label>
                        <input type="text" name="exam_date" id="add_exam_date" required class="olama-datepicker"
                            autocomplete="off">
                    </div>

                    <input type="hidden" name="status" value="draft">
                </div>

                <div class="olama-modal-footer">
                    <button type="submit" id="submit-exam-btn"
                        class="button button-primary button-large"><?php echo Olama_School_Helpers::translate('Add Exam'); ?></button>
                    <button type="button" class="button"
                        onclick="closeModal('exam-modal')"><?php echo Olama_School_Helpers::translate('Cancel'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .olama-exam-wrap {
        max-width: 1200px;
        margin: 20px auto;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .olama-modal {
        position: fixed;
        z-index: 99999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .olama-modal-content {
        background-color: #fff;
        padding: 0;
        border-radius: 8px;
        width: 100%;
        max-width: 700px;
        position: relative;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    }

    .olama-modal-header {
        padding: 20px 25px;
        border-bottom: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .olama-modal-header h2 {
        margin: 0;
        font-size: 18px;
        color: #1e293b;
    }

    .olama-modal-close {
        font-size: 28px;
        font-weight: bold;
        color: #94a3b8;
        cursor: pointer;
        line-height: 1;
    }

    .olama-form-grid {
        padding: 25px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        max-height: 70vh;
        overflow-y: auto;
    }

    .form-field {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .form-field.full-width {
        grid-column: span 2;
    }

    .form-field label {
        font-weight: 600;
        font-size: 13px;
        color: #475569;
    }

    .form-field select,
    .form-field input,
    .form-field textarea {
        padding: 8px 12px;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        width: 100%;
    }

    .olama-modal-footer {
        padding: 20px 25px;
        border-top: 1px solid #eee;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }

    <?php if (Olama_School_Helpers::is_arabic()): ?>
        .olama-exam-wrap {
            direction: rtl;
        }

        .olama-modal-footer {
            justify-content: flex-start;
        }

        .unit-block {
            border-right: 4px solid #6366f1;
            border-left: 1px solid #e2e8f0;
        }

        .lesson-row {
            border-right: 2px solid #e2e8f0;
            border-left: none;
            padding-right: 15px;
            padding-left: 0;
        }

        .remove-unit,
        .remove-lesson {
            left: 10px;
            right: auto;
        }

    <?php else: ?>
        .unit-block {
            border-left: 4px solid #6366f1;
            border-right: 1px solid #e2e8f0;
        }

        .lesson-row {
            border-left: 2px solid #e2e8f0;
            border-right: none;
            padding-left: 15px;
            padding-right: 0;
        }

        .remove-unit,
        .remove-lesson {
            right: 10px;
            left: auto;
        }

    <?php endif; ?>

    .unit-block {
        background: #f8fafc;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
        position: relative;
    }

    .unit-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 1px dashed #cbd5e1;
    }

    .lessons-container {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .lesson-row {
        display: flex;
        align-items: center;
        gap: 15px;
        background: #fff;
        padding: 10px;
        border-radius: 6px;
        position: relative;
    }

    .remove-unit,
    .remove-lesson {
        position: absolute;
        top: 10px;
        cursor: pointer;
        color: #ef4444;
        background: none;
        border: none;
        padding: 0;
    }

    .remove-unit {
        top: 15px;
    }

    .add-lesson-row-btn {
        margin-top: 10px !important;
        font-size: 11px !important;
    }
</style>

<script>
    window.closeModal = function (id) {
        jQuery('#' + id).fadeOut(200);
    }

    jQuery(document).ready(function ($) {
        var master_start = <?php echo $current_master_exam ? '"' . $current_master_exam->start_date . '"' : 'null'; ?>;
        var master_end = <?php echo $current_master_exam ? '"' . $current_master_exam->end_date . '"' : 'null'; ?>;

        function validateDate(dateStr) {
            if (!master_start || !master_end || !dateStr) return true;

            var date;
            if (dateStr.indexOf('-') !== -1) {
                var parts = dateStr.split('-');
                if (parts[0].length === 4) {
                    // Y-m-d
                    date = new Date(dateStr);
                } else {
                    // d-m-Y
                    date = new Date(parts[2], parts[1] - 1, parts[0]);
                }
            } else {
                date = new Date(dateStr);
            }

            if (isNaN(date.getTime())) return true;

            var start = new Date(master_start);
            var end = new Date(master_end);

            date.setHours(0, 0, 0, 0);
            start.setHours(0, 0, 0, 0);
            end.setHours(0, 0, 0, 0);

            if (date < start || date > end) {
                alert('<?php echo esc_js(__('Exam date must be within the allowed period: ', 'olama-school')); ?>' + '<?php echo $current_master_exam ? Olama_School_Helpers::format_date($current_master_exam->start_date) : ''; ?>' + ' - ' + '<?php echo $current_master_exam ? Olama_School_Helpers::format_date($current_master_exam->end_date) : ''; ?>');
                return false;
            }
            return true;
        }

        $('#open-add-exam-modal').on('click', function () {
            $('#exam-form')[0].reset();
            $('#exam_id').val('');
            $('#exam-modal').fadeIn(200);
        });

        // Inline Date Edit Toggling
        $(document).on('click', '.edit-exam-date-inline', function () {
            var row = $(this).closest('tr');
            row.find('.date-display').hide();
            row.find('.date-edit').show();

            // Initialize datepicker if not already initialized
            var input = row.find('.inline-date-input');
            if (!input.hasClass('hasDatepicker')) {
                input.datepicker({
                    dateFormat: 'dd-mm-yy',
                    changeMonth: true,
                    changeYear: true
                });
            }
        });

        $(document).on('click', '.cancel-inline-date', function () {
            var row = $(this).closest('tr');
            row.find('.date-edit').hide();
            row.find('.date-display').show();
        });

        $(document).on('click', '.save-inline-date', function () {
            var btn = $(this);
            var row = btn.closest('tr');
            var input = row.find('.inline-date-input');
            var newDate = input.val();
            var examId = input.data('exam-id');
            var yearId = input.data('year-id');
            var semesterId = input.data('semester-id');
            var gradeId = input.data('grade-id');
            var subjectId = input.data('subject-id');
            var semesterExamId = input.data('semester-exam-id');

            console.log('Saving exam date:', examId, newDate, yearId, semesterId, gradeId, subjectId);

            if (!validateDate(newDate)) return;

            btn.prop('disabled', true).text('...');

            $.post(ajaxurl, {
                action: 'olama_save_exam',
                nonce: $('#olama_exam_nonce_field').val() || $('#olama_material_nonce_field').val(),
                id: examId,
                academic_year_id: yearId,
                semester_id: semesterId,
                grade_id: gradeId,
                subject_id: subjectId,
                semester_exam_id: semesterExamId,
                exam_date: newDate
            }, function (response) {
                console.log('Response:', response);
                if (response.success) {
                    row.find('.date-display').text(newDate);
                    row.find('.date-edit').hide();
                    row.find('.date-display').show();
                    btn.prop('disabled', false).text('<?php _e('Save', 'olama-school'); ?>');
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                    btn.prop('disabled', false).text('<?php _e('Save', 'olama-school'); ?>');
                }
            }).fail(function (xhr, status, error) {
                console.error('AJAX Error:', error);
                alert('Connection error');
                btn.prop('disabled', false).text('<?php _e('Save', 'olama-school'); ?>');
            });
        });

        // Material Modal - Hierarchical Handling
        var currentUnits = [];
        var unitLessonsCache = {};

        function addUnitBlock(unitId) {
            var gradeId = $('#material_grade_id').val();
            var subjectId = $('#material_subject_id').val();
            var semesterId = $('#material_semester_id').val();

            var unitBlock = $('<div class="unit-block">' +
                '<button type="button" class="remove-unit" title="<?php echo Olama_School_Helpers::translate('Remove Unit'); ?>"><span class="dashicons dashicons-no-alt"></span></button>' +
                '<div class="unit-header">' +
                '<label style="font-weight: 700; color: #1e293b; min-width: 100px;"><?php echo Olama_School_Helpers::translate('Unit'); ?>:</label>' +
                '<select class="unit-select" style="max-width: 300px;"><option value="">-- <?php echo Olama_School_Helpers::translate('Select Unit'); ?> --</option></select>' +
                '</div>' +
                '<div class="lessons-container"></div>' +
                '<button type="button" class="button button-small add-lesson-row-btn" style="background: #eef2ff; color: #4f46e5; border-color: #c7d2fe;">' +
                '<span class="dashicons dashicons-plus-alt" style="vertical-align: middle;"></span> <?php echo Olama_School_Helpers::translate('Add Lesson'); ?>' +
                '</button>' +
                '</div>');

            $('#curriculum-blocks-container').append(unitBlock);

            var populateUnits = function (units) {
                var unitSelect = unitBlock.find('.unit-select');
                units.forEach(function (unit) {
                    unitSelect.append('<option value="' + unit.id + '">' + unit.unit_name + '</option>');
                });
                if (unitId) unitSelect.val(unitId);
            };

            if (currentUnits.length > 0) {
                populateUnits(currentUnits);
            } else {
                loadUnits(gradeId, subjectId, semesterId, function (units) {
                    currentUnits = units;
                    populateUnits(units);
                });
            }

            return unitBlock;
        }

        function addLessonRow(unitBlock, lessonId, material, providedUnitId) {
            var lessonsContainer = unitBlock.find('.lessons-container');
            var unitId = providedUnitId || unitBlock.find('.unit-select').val();

            var lessonRow = $('<div class="lesson-row">' +
                '<button type="button" class="remove-lesson" title="<?php echo Olama_School_Helpers::translate('Remove Lesson'); ?>"><span class="dashicons dashicons-no"></span></button>' +
                '<div style="flex: 1;">' +
                '<label style="display:block; font-size: 11px; color: #64748b; margin-bottom: 4px;"><?php echo Olama_School_Helpers::translate('Lesson'); ?></label>' +
                '<select class="lesson-select" style="width: 100%;"><option value="">-- <?php echo Olama_School_Helpers::translate('Select Lesson'); ?> --</option></select>' +
                '</div>' +
                '<div style="flex: 1.5;">' +
                '<label style="display:block; font-size: 11px; color: #64748b; margin-bottom: 4px;"><?php echo Olama_School_Helpers::translate('Required Material'); ?></label>' +
                '<input type="text" class="material-input" style="width: 100%;" placeholder="<?php echo Olama_School_Helpers::translate('e.g., Pages 10-15'); ?>">' +
                '</div>' +
                '</div>');

            lessonsContainer.append(lessonRow);

            if (unitId) {
                loadLessonsForSelect(lessonRow.find('.lesson-select'), unitId, lessonId);
            }

            if (material) lessonRow.find('.material-input').val(material);

            return lessonRow;
        }

        function loadUnits(gradeId, subjectId, semesterId, callback) {
            $.post(ajaxurl, {
                action: 'olama_get_units',
                nonce: olama_admin_ajax.nonce,
                grade_id: gradeId,
                subject_id: subjectId,
                semester_id: semesterId
            }, function (response) {
                if (response.success) {
                    callback(response.data);
                } else {
                    callback([]);
                }
            });
        }

        function loadLessonsForSelect(selectElement, unitId, selectedLessonId) {
            if (unitLessonsCache[unitId]) {
                populateLessonSelect(selectElement, unitLessonsCache[unitId], selectedLessonId);
            } else {
                $.post(ajaxurl, {
                    action: 'olama_get_lessons',
                    nonce: olama_admin_ajax.nonce,
                    unit_id: unitId
                }, function (response) {
                    if (response.success) {
                        unitLessonsCache[unitId] = response.data;
                        populateLessonSelect(selectElement, response.data, selectedLessonId);
                    }
                });
            }
        }

        function populateLessonSelect(selectElement, lessons, selectedLessonId) {
            selectElement.empty().append('<option value="">-- <?php echo Olama_School_Helpers::translate('Select Lesson'); ?> --</option>');
            lessons.forEach(function (lesson) {
                selectElement.append('<option value="' + lesson.id + '">' + lesson.lesson_title + '</option>');
            });
            if (selectedLessonId) selectElement.val(selectedLessonId);
        }

        function serializeCurriculumData() {
            var items = [];
            $('.unit-block').each(function () {
                var unitBlock = $(this);
                var unitId = unitBlock.find('.unit-select').val();

                unitBlock.find('.lesson-row').each(function () {
                    var lessonId = $(this).find('.lesson-select').val();
                    var material = $(this).find('.material-input').val();

                    if (unitId || lessonId || material) {
                        items.push({
                            unit_id: unitId ? parseInt(unitId) : null,
                            lesson_id: lessonId ? parseInt(lessonId) : null,
                            material: material
                        });
                    }
                });
            });

            return {
                curriculum_items: items,
                notebook_material: $('#material_notebook_material').val(),
                teacher_notes: $('#material_teacher_notes').val()
            };
        }

        $('#add-unit-btn').on('click', function () {
            var unitBlock = addUnitBlock();
            addLessonRow(unitBlock);
        });

        $(document).on('click', '.add-lesson-row-btn', function () {
            var unitBlock = $(this).closest('.unit-block');
            addLessonRow(unitBlock);
        });

        $(document).on('change', '.unit-select', function () {
            var unitBlock = $(this).closest('.unit-block');
            var unitId = $(this).val();
            unitBlock.find('.lesson-select').empty().append('<option value="">-- <?php echo Olama_School_Helpers::translate('Select Lesson'); ?> --</option>');

            if (unitId) {
                unitBlock.find('.lesson-row').each(function () {
                    loadLessonsForSelect($(this).find('.lesson-select'), unitId);
                });
            }
        });

        $(document).on('click', '.remove-unit', function () {
            if (confirm('<?php echo Olama_School_Helpers::translate('Remove this unit and all its lessons?'); ?>')) {
                $(this).closest('.unit-block').remove();
            }
        });

        $(document).on('click', '.remove-lesson', function () {
            $(this).closest('.lesson-row').remove();
        });

        $(document).on('click', '.edit-exam-material', function () {
            var data = $(this).data('exam');
            $('#material_exam_id').val(data.id);
            $('#material_academic_year_id').val(data.academic_year_id);
            $('#material_semester_id').val(data.semester_id);
            $('#material_grade_id').val(data.grade_id);
            $('#material_subject_id').val(data.subject_id);
            $('#material_semester_exam_id').val(data.semester_exam_id);

            // Clear previous data
            $('#curriculum-blocks-container').empty();
            currentUnits = [];
            unitLessonsCache = {};

            // Parse existing JSON data if available
            var materialJson = null;
            if (data.exam_material_json) {
                try {
                    materialJson = JSON.parse(data.exam_material_json);
                } catch (e) {
                    console.error('Invalid JSON:', e);
                }
            }

            if (materialJson && materialJson.curriculum_items && materialJson.curriculum_items.length > 0) {
                // Group items by unit_id to reconstruct hierarchy
                var groupedByUnit = {};
                materialJson.curriculum_items.forEach(function (item) {
                    var uId = item.unit_id || 'no-unit';
                    if (!groupedByUnit[uId]) groupedByUnit[uId] = [];
                    groupedByUnit[uId].push(item);
                });

                Object.keys(groupedByUnit).forEach(function (uId) {
                    var unitIdValue = uId === 'no-unit' ? null : uId;
                    var unitBlock = addUnitBlock(unitIdValue);
                    groupedByUnit[uId].forEach(function (item) {
                        addLessonRow(unitBlock, item.lesson_id, item.material, unitIdValue);
                    });
                });

                $('#material_notebook_material').val(materialJson.notebook_material || materialJson.booklets_notebooks || '');
                $('#material_teacher_notes').val(materialJson.teacher_notes || '');
            } else {
                // Add one empty unit block with one lesson row for new entries
                var unitBlock = addUnitBlock();
                addLessonRow(unitBlock);
                $('#material_notebook_material').val(data.notebook_material || '');
                $('#material_teacher_notes').val(data.teacher_notes || '');
            }

            $('#material_description').val(data.description || '');
            $('#material_supervisor_comments').val(data.supervisor_comments || '');
            $('#material_status').val(data.status || 'draft');

            $('#material-modal').fadeIn(200);
        });

        // Open Upload Modal
        $(document).on('click', '.open-upload-modal', function () {
            var examId = $(this).data('exam-id');
            $('#upload_exam_id').val(examId);
            $('#upload-modal').fadeIn(200);
            loadAttachmentInfo(examId);
        });

        function loadAttachmentInfo(examId) {
            $('#attachment-status, #attachment-upload-section').hide();
            $('#attachment-loading').show();
            $('#attachment-filename').text('');
            $('#attachment-badge').text('').css('background', 'transparent');

            // Reset delete button state
            $('#delete-attachment-btn').prop('disabled', false).html('<span class="dashicons dashicons-trash" style="vertical-align: middle;"></span> <?php echo Olama_School_Helpers::translate('Delete File'); ?>');

            $.post(ajaxurl, {
                action: 'olama_get_exam_attachment',
                nonce: $('#olama_exam_nonce_field').val() || $('#olama_material_nonce_field').val(),
                exam_id: examId
            }, function (response) {
                $('#attachment-loading').hide();
                if (response.success && response.data) {
                    var info = response.data;
                    $('#attachment-filename').text(info.stored_filename || info.original_filename);
                    $('#attachment-badge')
                        .text(info.file_status.charAt(0).toUpperCase() + info.file_status.slice(1))
                        .css('background', getStatusColor(info.file_status));
                    $('#download-attachment-btn').attr('href', info.download_url);
                    $('#attachment-status').fadeIn(200);

                    // Show upload section too for replacement
                    $('#attachment-upload-section').show();
                    $('#upload-exam-btn').text('<?php echo Olama_School_Helpers::translate('Replace File'); ?>');
                } else {
                    $('#attachment-upload-section').show();
                    $('#upload-exam-btn').html('<span class="dashicons dashicons-upload"></span> <?php echo Olama_School_Helpers::translate('Upload File'); ?>');
                }
            }).fail(function () {
                $('#attachment-loading').hide();
                $('#attachment-upload-section').show();
            });
        }

        function getStatusColor(status) {
            switch (status) {
                case 'approved': return '#def7ec';
                case 'rejected': return '#fde8e8';
                case 'uploaded': return '#e1effe';
                default: return '#f3f4f6';
            }
        }

        $('#upload-exam-btn').on('click', function () {
            var fileInput = $('#exam_file_input')[0];
            var examId = $('#upload_exam_id').val();

            if (fileInput.files.length === 0) {
                alert('<?php echo Olama_School_Helpers::translate('Please select a file first.'); ?>');
                return;
            }

            var formData = new FormData();
            formData.append('action', 'olama_upload_exam_file');
            formData.append('nonce', $('#olama_exam_nonce_field').val() || $('#olama_material_nonce_field').val());
            formData.append('exam_id', examId);
            formData.append('exam_file', fileInput.files[0]);

            var btn = $(this);
            btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (response) {
                    if (response.success) {
                        alert('<?php echo Olama_School_Helpers::translate('File uploaded successfully'); ?>');
                        loadAttachmentInfo(examId);
                        fileInput.value = '';
                    } else {
                        alert('Error: ' + response.data);
                    }
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-upload"></span> <?php echo Olama_School_Helpers::translate('Upload File'); ?>');
                },
                error: function () {
                    alert('Upload failed');
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-upload"></span> <?php echo Olama_School_Helpers::translate('Upload File'); ?>');
                }
            });
        });

        $(document).on('click', '#delete-attachment-btn', function () {
            var examId = $('#upload_exam_id').val();
            if (!confirm('<?php echo Olama_School_Helpers::translate('Are you sure you want to delete this file?'); ?>')) return;

            var btn = $(this);
            btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');

            $.post(ajaxurl, {
                action: 'olama_delete_exam_attachment',
                nonce: $('#olama_exam_nonce_field').val() || $('#olama_material_nonce_field').val(),
                exam_id: examId
            }, function (response) {
                if (response.success) {
                    loadAttachmentInfo(examId);
                } else {
                    alert('Error: ' + response.data);
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> <?php echo Olama_School_Helpers::translate('Delete File'); ?>');
                }
            }).fail(function (xhr) {
                alert('Request failed: ' + xhr.statusText);
                btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> <?php echo Olama_School_Helpers::translate('Delete File'); ?>');
            });
        });
        // Note: JSON serialization for material-form is handled in the combined subm it handler below

        $(document).on('click', '#header-bulk-add-subjects', function () {
            // Validate required selections
            var semesterExamId = '<?php echo $selected_semester_exam_id; ?>';
            var gradeId = '<?php echo $selected_grade_id; ?>';

            if (!semesterExamId || semesterExamId == '0') {
                alert('<?php echo Olama_School_Helpers::translate('Please select an exam first.'); ?>');
                return;
            }
            if (!gradeId || gradeId == '0') {
                alert('<?php echo Olama_School_Helpers::translate('Please select a grade first.'); ?>');
                return;
            }

            if (!confirm('<?php echo Olama_School_Helpers::translate('This will initialize all subjects for this grade in the selected exam. Continue?'); ?>')) return;

            var data = {
                action: 'olama_bulk_add_exam_subjects',
                nonce: $('#olama_exam_nonce_field').val() || $('#olama_material_nonce_field').val(),
                academic_year_id: '<?php echo $selected_year_id; ?>',
                semester_id: '<?php echo $selected_semester_id; ?>',
                semester_exam_id: '<?php echo $selected_semester_exam_id; ?>',
                grade_id: '<?php echo $selected_grade_id; ?>'
            };

            var btn = $(this);
            btn.prop('disabled', true).text('<?php _e('Processing...', 'olama-school'); ?>');

            $.post(ajaxurl, data, function (response) {
                if (response.success) {
                    alert(response.data.message);
                    window.location.reload();
                } else {
                    alert('Error: ' + response.data);
                    btn.prop('disabled', false).text('<?php echo Olama_School_Helpers::translate('Init All Subjects'); ?>');
                }
            }).fail(function (xhr, status, error) {
                console.error('AJAX Error:', error);
                alert('Connection error: ' + error);
                btn.prop('disabled', false).text('<?php echo Olama_School_Helpers::translate('Init All Subjects'); ?>');
            });
        });

        $('.olama-modal-close').on('click', function () {
            $(this).closest('.olama-modal').fadeOut(200);
        });

        // Approve Exam Action
        $(document).on('click', '.approve-exam', function () {
            var btn = $(this);
            var examId = btn.data('exam-id');

            if (!confirm('<?php echo Olama_School_Helpers::translate('Are you sure you want to approve this exam?'); ?>')) return;

            btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');

            var nonceValue = $('#olama_exam_nonce_field').val() || $('input[name="olama_material_nonce_field"]').val();

            $.post(ajaxurl, {
                action: 'olama_save_exam',
                nonce: nonceValue,
                id: examId,
                status: 'approved',
                academic_year_id: btn.data('year-id'),
                semester_id: btn.data('semester-id'),
                grade_id: btn.data('grade-id'),
                subject_id: btn.data('subject-id')
            }, function (response) {
                if (response.success) {
                    window.location.reload();
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span>');
                }
            }).fail(function () {
                alert('Connection error');
                btn.prop('disabled', false).html('<span class="dashicons dashicons-yes-alt"></span>');
            });
        });

        $('#date-form, #material-form, #exam-form').on('submit', function (e) {
            e.preventDefault();
            var form = $(this);

            // Serialize JSON data for Material form BEFORE form.serialize()
            if (form.attr('id') === 'material-form') {
                // Validation
                var description = $('#material_description').val().trim();
                var booklets = $('#material_notebook_material').val().trim();
                var teacherNotes = $('#material_teacher_notes').val().trim();

                var jsonData = serializeCurriculumData();
                var hasCurriculum = jsonData.curriculum_items && jsonData.curriculum_items.length > 0;

                var missing = [];
                if (!description) missing.push('<?php echo Olama_School_Helpers::translate('Exam Description'); ?>');
                if (!hasCurriculum) missing.push('<?php echo Olama_School_Helpers::translate('Curriculum Material'); ?>');
                if (!booklets) missing.push('<?php echo Olama_School_Helpers::translate('Booklets & Notebooks'); ?>');
                if (!teacherNotes) missing.push('<?php echo Olama_School_Helpers::translate('Teacher Notes'); ?>');

                if (missing.length > 0 && $('#material_status').val() === 'approved') {
                    alert('<?php echo Olama_School_Helpers::translate('Please fill the following required fields for Approval:'); ?>\n- ' + missing.join('\n- '));
                    return;
                }

                $('#exam_material_json').val(JSON.stringify(jsonData));
            }

            // Date validation for forms that have exam_date
            var dateVal = form.find('[name="exam_date"]').val();
            if (dateVal && !validateDate(dateVal)) {
                return;
            }

            var nonce = form.find('[name="olama_exam_nonce_field"]').val() || form.find('[name="olama_material_nonce_field"]').val();
            var formData = form.serialize() + '&action=olama_save_exam&nonce=' + nonce;

            $.post(ajaxurl, formData, function (response) {
                if (response.success) {
                    window.location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            });
        });
    });
</script>
