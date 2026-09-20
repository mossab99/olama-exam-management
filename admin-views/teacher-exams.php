<?php
/**
 * Teacher Exams View
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

$teacher_id = get_current_user_id();
$exams = Olama_School_Exam::get_teacher_exams($teacher_id, $selected_year_id, $selected_exam_id);

// Get semester exams for the dropdown
$semester_exams = Olama_School_Academic::get_semester_exams($selected_semester_id);
?>

<div class="wrap olama-teacher-exams-wrap">
    <div class="olama-header-section" style="margin-bottom: 20px;">
        <h1>
            <?php echo Olama_School_Helpers::translate('Teacher Exams'); ?>
        </h1>
    </div>

    <!-- Filter Bar -->
    <div class="olama-filter-bar"
        style="background: #fff; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 25px;">
        <form method="get" action="" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
            <input type="hidden" name="page" value="olama-exam-management">
            <input type="hidden" name="tab" value="teacher_exams">

            <?php
            // Academic Year locked filter
            $year_name = '—';
            foreach ($years as $y) {
                if (intval($y->id) === $selected_year_id) {
                    $year_name = $y->year_name;
                    break;
                }
            }
            echo Olama_School_Helpers::locked_filter_render(Olama_School_Helpers::translate('Academic Year'), $year_name, 'academic_year_id', $selected_year_id);

            // Semester locked filter
            $semester_name = '—';
            foreach ($semesters as $s) {
                if (intval($s->id) === $selected_semester_id) {
                    $semester_name = $s->semester_name;
                    break;
                }
            }
            echo Olama_School_Helpers::locked_filter_render(Olama_School_Helpers::translate('Semester'), $semester_name, 'semester_id', $selected_semester_id);
            ?>

            <?php
            // Active Exam locked filter
            $exam_name = Olama_School_Helpers::translate('Choose Exam');
            if ($active_exam) {
                $exam_name = $active_exam->exam_name;
            }
            echo Olama_School_Helpers::locked_filter_render(Olama_School_Helpers::translate('Active Exam'), $exam_name, 'semester_exam_id', $selected_exam_id);
            ?>

            <button type="submit" class="button button-secondary" style="height: 42px; padding: 0 20px;">
                <?php echo Olama_School_Helpers::translate('Search'); ?>
            </button>
        </form>
    </div>

    <div class="olama-table-container">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="font-weight: 600; width: 25%;">
                        <?php echo Olama_School_Helpers::translate('Subject'); ?>
                    </th>
                    <th style="font-weight: 600; width: 15%;">
                        <?php echo Olama_School_Helpers::translate('Date'); ?>
                    </th>
                    <th style="font-weight: 600; width: 35%;">
                        <?php echo Olama_School_Helpers::translate('Supervisor Comments'); ?>
                    </th>
                    <th style="font-weight: 600; width: 10%;">
                        <?php echo Olama_School_Helpers::translate('Status'); ?>
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
                            <?php echo Olama_School_Helpers::translate('No subjects assigned for the selected exam.'); ?>
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

                        // Get exam name from semester_exams array
                        $current_exam_name = '';
                        foreach ($semester_exams as $se) {
                            if ($se->id == $exam->semester_exam_id) {
                                $current_exam_name = $se->exam_name;
                                break;
                            }
                        }
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($exam->subject_name); ?></strong>
                                <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                                    <?php echo esc_html($current_exam_name ?: $exam->evaluation_type); ?> -
                                    <small><?php echo esc_html($exam->grade_name); ?></small>
                                </div>
                            </td>
                            <td>
                                <?php echo $exam->formatted_date; ?>
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
                                <button type="button" class="button button-primary fill-details"
                                    data-exam='<?php echo esc_attr(json_encode($exam)); ?>'>
                                    <?php echo Olama_School_Helpers::translate('Enter Details'); ?>
                                </button>
                                <button type="button" class="button button-small open-teacher-upload-modal"
                                    data-exam-id="<?php echo $exam->id; ?>"
                                    title="<?php echo Olama_School_Helpers::translate('Upload Exam File'); ?>"
                                    style="margin-left: 5px;">
                                    <span class="dashicons dashicons-upload"></span>
                                </button>
                                <?php if ($exam->attachment_id): ?>
                                    <a href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=olama_download_exam_file&exam_id=' . $exam->id), 'olama_download_file_' . $exam->id); ?>"
                                        class="button button-small"
                                        title="<?php echo Olama_School_Helpers::translate('Download Exam'); ?>"
                                        style="background: #e1effe; color: #1e429f; margin-right: 5px;">
                                        <span class="dashicons dashicons-download"></span>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Teacher Modal -->
    <div id="teacher-exam-modal" class="olama-modal" style="display: none;">
        <div class="olama-modal-content" style="max-width: 800px;">
            <div class="olama-modal-header">
                <h2>
                    <?php echo Olama_School_Helpers::translate('Exam Details'); ?> - <span
                        id="subject-name-title"></span>
                </h2>
                <span class="olama-modal-close" onclick="closeModal('teacher-exam-modal')">&times;</span>
            </div>
            <form id="teacher-exam-form" method="post">
                <?php wp_nonce_field('olama_save_exam', 'olama_exam_nonce_field'); ?>
                <input type="hidden" name="id" id="t_exam_id">
                <input type="hidden" name="academic_year_id" id="t_year_id">
                <input type="hidden" name="semester_id" id="t_semester_id">
                <input type="hidden" name="semester_exam_id" id="t_se_exam_id">
                <input type="hidden" name="grade_id" id="t_grade_id">
                <input type="hidden" name="subject_id" id="t_subject_id">
                <input type="hidden" name="exam_material_json" id="exam_material_json">
                <input type="hidden" name="status" value="completed">
                <input type="hidden" name="action" value="olama_save_exam">

                <div style="padding: 20px; max-height: 70vh; overflow-y: auto;">
                    <!-- Supervisor Comments (Show if exists) -->
                    <div id="supervisor-comments-container" class="material-section"
                        style="display: none; background: #fffbeb; border: 1px solid #fef3c7; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
                        <h3
                            style="margin: 0 0 10px; font-size: 14px; color: #92400e; border-bottom: 1px solid #fde68a; padding-bottom: 5px;">
                            <span class="dashicons dashicons-warning" style="font-size: 18px; margin-top: 2px;"></span>
                            <?php echo Olama_School_Helpers::translate('Supervisor Comments'); ?>
                        </h3>
                        <div id="supervisor-comments-text"
                            style="color: #92400e; font-style: italic; white-space: pre-wrap;"></div>
                    </div>

                    <!-- Description Section (Added back as requested by visual consistency) -->
                    <div class="material-section">
                        <h3
                            style="margin: 0 0 10px; font-size: 14px; color: #475569; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px;">
                            <?php echo Olama_School_Helpers::translate('Exam Description'); ?>
                        </h3>
                        <textarea name="description" id="t_description" rows="2" style="width: 100%;"
                            placeholder="<?php echo Olama_School_Helpers::translate('Enter general description or topic...'); ?>"></textarea>
                    </div>

                    <!-- Curriculum Material Section -->
                    <div class="material-section" style="margin-top: 20px;">
                        <h3
                            style="margin: 0 0 15px; font-size: 14px; color: #475569; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px;">
                            <?php echo Olama_School_Helpers::translate('Curriculum Material'); ?>
                        </h3>
                        <table id="curriculum-material-table" class="widefat" style="margin-bottom: 10px;">
                            <thead>
                                <tr>
                                    <th style="width: 30%;"><?php echo Olama_School_Helpers::translate('Unit'); ?></th>
                                    <th style="width: 30%;"><?php echo Olama_School_Helpers::translate('Lesson'); ?>
                                    </th>
                                    <th style="width: 35%;">
                                        <?php echo Olama_School_Helpers::translate('Required Material'); ?>
                                    </th>
                                    <th style="width: 5%;"></th>
                                </tr>
                            </thead>
                            <tbody id="curriculum-rows">
                                <!-- Dynamic rows will be added here -->
                            </tbody>
                        </table>
                        <button type="button" id="add-curriculum-row" class="button button-secondary">
                            <span class="dashicons dashicons-plus-alt2" style="vertical-align: middle;"></span>
                            <?php echo Olama_School_Helpers::translate('Add Row'); ?>
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

                </div>

                <div class="olama-modal-footer">
                    <button type="submit"
                        class="button button-primary"><?php echo Olama_School_Helpers::translate('Save Details'); ?></button>
                    <button type="button" class="button cancel-modal"
                        onclick="closeModal('teacher-exam-modal')"><?php echo Olama_School_Helpers::translate('Cancel'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Teacher Upload Modal -->
    <div id="teacher-upload-modal" class="olama-modal" style="display: none;">
        <div class="olama-modal-content" style="max-width: 500px;">
            <div class="olama-modal-header">
                <h2><?php echo Olama_School_Helpers::translate('Upload Exam File'); ?></h2>
                <span class="olama-modal-close" onclick="closeModal('teacher-upload-modal')">&times;</span>
            </div>
            <div style="padding: 20px;">
                <input type="hidden" id="t_upload_exam_id">
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
                                <?php echo Olama_School_Helpers::translate('Download My File'); ?>
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
                            <?php echo Olama_School_Helpers::translate('Upload New File'); ?>
                        </h4>
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
                    onclick="closeModal('teacher-upload-modal')"><?php echo Olama_School_Helpers::translate('Close'); ?></button>
            </div>
        </div>
    </div>
</div>

<style>
    .olama-teacher-exams-wrap {
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
        width: 95%;
        max-width: 800px;
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
    }

    .olama-modal-close {
        font-size: 28px;
        font-weight: bold;
        color: #94a3b8;
        cursor: pointer;
    }

    .material-section {
        margin-bottom: 20px;
    }

    .olama-modal-footer {
        padding: 20px 25px;
        border-top: 1px solid #eee;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }

    <?php if (Olama_School_Helpers::is_arabic()): ?>
        .olama-teacher-exams-wrap {
            direction: rtl;
        }

        .olama-modal-footer {
            justify-content: flex-start;
        }

    <?php endif; ?>
</style>

<script>
    function closeModal(id) { jQuery('#' + id).fadeOut(200); }

    jQuery(document).ready(function ($) {
        var currentUnits = [];
        var unitLessonsCache = {};

        function createCurriculumRow(unitId, lessonId, material) {
            var gradeId = $('#t_grade_id').val();
            var subjectId = $('#t_subject_id').val();
            var semesterId = $('#t_semester_id').val();

            var row = $('<tr class="curriculum-row">' +
                '<td><select class="unit-select" style="width: 100%;"><option value="">-- <?php echo Olama_School_Helpers::translate('Select Unit'); ?> --</option></select></td>' +
                '<td><select class="lesson-select" style="width: 100%;"><option value="">-- <?php echo Olama_School_Helpers::translate('Select Lesson'); ?> --</option></select></td>' +
                '<td><input type="text" class="material-input" style="width: 100%;" placeholder="<?php echo Olama_School_Helpers::translate('e.g., Pages 10-15'); ?>"></td>' +
                '<td><button type="button" class="button button-small remove-row" style="color: #dc2626;"><span class="dashicons dashicons-no"></span></button></td>' +
                '</tr>');

            if (currentUnits.length > 0) {
                var unitSelect = row.find('.unit-select');
                currentUnits.forEach(function (unit) { unitSelect.append('<option value="' + unit.id + '">' + unit.unit_name + '</option>'); });
                if (unitId) { unitSelect.val(unitId); loadLessonsForRow(row, unitId, lessonId); }
            } else {
                loadUnits(gradeId, subjectId, semesterId, function (units) {
                    currentUnits = units;
                    var unitSelect = row.find('.unit-select');
                    units.forEach(function (unit) { unitSelect.append('<option value="' + unit.id + '">' + unit.unit_name + '</option>'); });
                    if (unitId) { unitSelect.val(unitId); loadLessonsForRow(row, unitId, lessonId); }
                });
            }
            if (material) row.find('.material-input').val(material);
            return row;
        }

        function loadUnits(gradeId, subjectId, semesterId, callback) {
            $.post(ajaxurl, {
                action: 'olama_exam_get_units',
                nonce: $('#olama_exam_nonce_field').val(),
                exam_id: $('#t_exam_id').val(),
                grade_id: gradeId,
                subject_id: subjectId,
                semester_id: semesterId
            }, function (response) {
                if (response.success) { callback(response.data); } else { callback([]); }
            });
        }

        function loadLessonsForRow(row, unitId, selectedLessonId) {
            if (unitLessonsCache[unitId]) { populateLessons(row, unitLessonsCache[unitId], selectedLessonId); } else {
                $.post(ajaxurl, {
                    action: 'olama_exam_get_lessons',
                    nonce: $('#olama_exam_nonce_field').val(),
                    exam_id: $('#t_exam_id').val(),
                    unit_id: unitId
                }, function (response) {
                    if (response.success) { unitLessonsCache[unitId] = response.data; populateLessons(row, response.data, selectedLessonId); }
                });
            }
        }

        function populateLessons(row, lessons, selectedLessonId) {
            var lessonSelect = row.find('.lesson-select');
            lessonSelect.empty().append('<option value="">-- <?php echo Olama_School_Helpers::translate('Select Lesson'); ?> --</option>');
            lessons.forEach(function (lesson) { lessonSelect.append('<option value="' + lesson.id + '">' + lesson.lesson_title + '</option>'); });
            if (selectedLessonId) lessonSelect.val(selectedLessonId);
        }

        function serializeCurriculumData() {
            var items = [];
            $('#curriculum-rows tr.curriculum-row').each(function () {
                var unitId = $(this).find('.unit-select').val();
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
            return {
                curriculum_items: items,
                notebook_material: $('#material_notebook_material').val(),
                teacher_notes: $('#material_teacher_notes').val()
            };
        }

        $('.fill-details').on('click', function () {
            var data = $(this).data('exam');
            $('#t_exam_id').val(data.id);
            $('#t_year_id').val(data.academic_year_id);
            $('#t_semester_id').val(data.semester_id);
            $('#t_se_exam_id').val(data.semester_exam_id);
            $('#t_grade_id').val(data.grade_id);
            $('#t_subject_id').val(data.subject_id);
            $('#subject-name-title').text(data.subject_name);
            $('#t_description').val(data.description || '');

            if (data.supervisor_comments) {
                $('#supervisor-comments-text').text(data.supervisor_comments);
                $('#supervisor-comments-container').show();
            } else {
                $('#supervisor-comments-container').hide();
            }

            $('#curriculum-rows').empty();
            currentUnits = [];
            unitLessonsCache = {};

            var materialJson = null;
            if (data.exam_material_json) {
                try { materialJson = JSON.parse(data.exam_material_json); } catch (e) { console.error('Invalid JSON:', e); }
            }

            if (materialJson && materialJson.curriculum_items && materialJson.curriculum_items.length > 0) {
                materialJson.curriculum_items.forEach(function (item) {
                    $('#curriculum-rows').append(createCurriculumRow(item.unit_id, item.lesson_id, item.material));
                });
                $('#material_notebook_material').val(materialJson.notebook_material || '');
                $('#material_teacher_notes').val(materialJson.teacher_notes || '');
            } else {
                $('#curriculum-rows').append(createCurriculumRow());
                $('#material_notebook_material').val(data.notebook_material || '');
                $('#material_teacher_notes').val(data.teacher_notes || '');
            }
            $('#teacher-exam-modal').fadeIn(200);
        });

        // Open Teacher Upload Modal
        $(document).on('click', '.open-teacher-upload-modal', function () {
            var examId = $(this).data('exam-id');
            $('#t_upload_exam_id').val(examId);
            $('#teacher-upload-modal').fadeIn(200);
            loadAttachmentInfo(examId);
        });

        function loadAttachmentInfo(examId) {
            $('#attachment-status, #attachment-upload-section').hide();
            $('#attachment-loading').show();
            $('#attachment-filename').text('');
            $('#attachment-badge').text('').css('background', 'transparent');

            $.post(ajaxurl, {
                action: 'olama_get_exam_attachment',
                nonce: $('#olama_exam_nonce_field').val() || $('input[name="olama_exam_nonce_field"]').val(),
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

                    // Teachers can always replace/delete their own file version
                    $('#attachment-upload-section').show();
                    $('#upload-exam-btn').text('<?php echo Olama_School_Helpers::translate('Replace File'); ?>');
                    $('#delete-attachment-btn').show();
                } else {
                    $('#attachment-upload-section').show();
                    $('#upload-exam-btn').html('<span class="dashicons dashicons-upload"></span> <?php echo Olama_School_Helpers::translate('Upload Exam File'); ?>');
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
            var examId = $('#t_upload_exam_id').val();

            if (fileInput.files.length === 0) {
                alert('<?php echo Olama_School_Helpers::translate('Please select a file first.'); ?>');
                return;
            }

            var formData = new FormData();
            formData.append('action', 'olama_upload_exam_file');
            formData.append('nonce', $('#olama_exam_nonce_field').val() || $('input[name="olama_exam_nonce_field"]').val());
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
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-upload"></span> <?php echo Olama_School_Helpers::translate('Upload Exam File'); ?>');
                },
                error: function () {
                    alert('Upload failed');
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-upload"></span> <?php echo Olama_School_Helpers::translate('Upload Exam File'); ?>');
                }
            });
        });

        $(document).on('click', '#delete-attachment-btn', function () {
            var examId = $('#t_upload_exam_id').val();
            if (!confirm('<?php echo Olama_School_Helpers::translate('Are you sure you want to delete this file?'); ?>')) return;

            var btn = $(this);
            btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');

            $.post(ajaxurl, {
                action: 'olama_delete_exam_attachment',
                nonce: $('#olama_exam_nonce_field').val() || $('input[name="olama_exam_nonce_field"]').val(),
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

        $('#add-curriculum-row').on('click', function () { $('#curriculum-rows').append(createCurriculumRow()); });
        $(document).on('change', '.unit-select', function () {
            var row = $(this).closest('tr');
            var unitId = $(this).val();
            if (unitId) { loadLessonsForRow(row, unitId); } else {
                row.find('.lesson-select').empty().append('<option value="">-- <?php echo Olama_School_Helpers::translate('Select Lesson'); ?> --</option>');
            }
        });
        $(document).on('click', '.remove-row', function () { $(this).closest('tr').remove(); });
        $('.olama-modal-close, .cancel-modal').on('click', function () { closeModal('teacher-exam-modal'); });

        $('#teacher-exam-form').on('submit', function (e) {
            e.preventDefault();

            // Validation
            var description = $('#t_description').val().trim();
            var booklets = $('#material_notebook_material').val().trim();
            var teacherNotes = $('#material_teacher_notes').val().trim();

            var jsonData = serializeCurriculumData();
            var hasCurriculum = jsonData.curriculum_items && jsonData.curriculum_items.length > 0;

            var missing = [];
            if (!description) missing.push('<?php echo Olama_School_Helpers::translate('Exam Description'); ?>');
            if (!hasCurriculum) missing.push('<?php echo Olama_School_Helpers::translate('Curriculum Material'); ?>');
            if (!booklets) missing.push('<?php echo Olama_School_Helpers::translate('Booklets & Notebooks'); ?>');
            if (!teacherNotes) missing.push('<?php echo Olama_School_Helpers::translate('Teacher Notes'); ?>');

            if (missing.length > 0) {
                alert('<?php echo Olama_School_Helpers::translate('Please fill the following required fields:'); ?>\n- ' + missing.join('\n- '));
                return;
            }

            $('#exam_material_json').val(JSON.stringify(jsonData));
            var nonce = $(this).find('[name="olama_exam_nonce_field"]').val();
            var formData = $(this).serialize() + '&action=olama_save_exam&nonce=' + nonce;
            $.post(ajaxurl, formData, function (response) {
                if (response.success) { window.location.reload(); } else { alert('Error: ' + response.data); }
            });
        });
    });
</script>
