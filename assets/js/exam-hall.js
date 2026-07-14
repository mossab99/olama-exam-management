/**
 * Exam Hall Distribution System – Canvas-based frontend
 * Redesigned: grade/section → add halls to canvas → distribute
 */
(function ($) {
    'use strict';

    /* ─── Config ─────────────────────────────────────────────────────────────── */
    const EH = {
        ajaxUrl:    olamaExamHall.ajaxUrl,
        nonce:      olamaExamHall.nonce,
        yearId:     parseInt(olamaExamHall.yearId)     || 0,
        semesterId: parseInt(olamaExamHall.semesterId) || 0,
        isAdmin:    olamaExamHall.isAdmin   === '1',
        isArabic:   olamaExamHall.isArabic  === '1',
        allHalls:   Array.isArray(olamaExamHall.halls) ? olamaExamHall.halls : [],
        sections:   olamaExamHall.sections  || {},

        // Canvas session state
        canvas: {
            gradeId:    0,
            sectionId:  0,
            halls:      [],   // [{id, hall_name, capacity}] on canvas
            students:   [],   // all students from selected grade/section
            assignments:{},   // { hall_id: [studentObj, ...] }
            invigilators:{},   // { hall_id: [invigilatorObj, ...] }
            occupancy:  {},   // { hall_id: totalCount }
        },
        dragSrc: null,
    };

    /* ─── Persistence (localStorage) ─────────────────────────────────────────── */
    const STORE_KEY = 'eh_canvas_' + EH.yearId + '_' + EH.semesterId;

    function saveState() {
        try {
            localStorage.setItem(STORE_KEY, JSON.stringify({
                gradeId:   EH.canvas.gradeId,
                sectionId: EH.canvas.sectionId,
                hallIds:   EH.canvas.halls.map(h => parseInt(h.id)),
            }));
        } catch (e) {}
    }

    function restoreState() {
        try {
            const d = JSON.parse(localStorage.getItem(STORE_KEY) || '{}');
            if (!d.hallIds || !d.hallIds.length) return false;

            EH.canvas.gradeId   = d.gradeId   || 0;
            EH.canvas.sectionId = d.sectionId || 0;
            EH.canvas.halls     = EH.allHalls.filter(h => d.hallIds.includes(parseInt(h.id)));

            if (EH.canvas.gradeId) {
                $('#eh-filter-grade').val(EH.canvas.gradeId);
                populateSections(EH.canvas.gradeId, EH.canvas.sectionId);
            }
            return true;
        } catch (e) { return false; }
    }

    /* ─── AJAX helper ─────────────────────────────────────────────────────────── */
    function ajax(action, data, cb) {
        $.ajax({
            url:    EH.ajaxUrl,
            method: 'POST',
            data:   Object.assign({
                action,
                nonce:            EH.nonce,
                academic_year_id: EH.yearId,
                semester_id:      EH.semesterId,
            }, data),
            success: r => r.success ? cb && cb(null, r.data) : cb && cb((r.data && r.data.message) || 'Error'),
            error:   () => cb && cb('Network error'),
        });
    }

    /* ─── Toast ───────────────────────────────────────────────────────────────── */
    function toast(msg, type = 'info', ms = 3500) {
        const icons = { success: '✓', error: '✕', info: 'ℹ' };
        const $t = $('<div class="eh-toast ' + type + '">').html(
            '<span>' + (icons[type] || '') + '</span><span>' + msg + '</span>'
        ).appendTo('body');
        setTimeout(() => $t.fadeOut(400, function () { $(this).remove(); }), ms);
    }

    /* ─── Section cascade ─────────────────────────────────────────────────────── */
    function populateSections(gradeId, selectedSectionId) {
        const $sel = $('#eh-filter-section').empty().append('<option value="">-- الشعبة / Section --</option>');
        const secs = EH.sections[gradeId] || [];
        secs.forEach(s => $sel.append($('<option>').val(s.id).text(s.section_name)));
        if (selectedSectionId) $sel.val(selectedSectionId);
    }

    $(document).on('change', '#eh-filter-grade', function () {
        populateSections($(this).val(), 0);
    });

    /* ─── Load students ───────────────────────────────────────────────────────── */
    function loadStudents(cb) {
        const gradeId   = parseInt($('#eh-filter-grade').val())   || 0;
        const sectionId = parseInt($('#eh-filter-section').val()) || 0;

        if (!gradeId && !sectionId) {
            toast('اختر الصف والشعبة أولاً / Select grade and section', 'error');
            return;
        }

        EH.canvas.gradeId   = gradeId;
        EH.canvas.sectionId = sectionId;
        saveState();

        $('#eh-student-panel-body').html(
            '<p style="text-align:center;padding:24px;"><span class="spinner is-active" style="float:none;"></span></p>'
        );

        ajax('olama_eh_get_students', {
            grade_id:        gradeId,
            section_id:      sectionId,
            canvas_hall_ids: EH.canvas.halls.map(h => h.id),
        }, function (err, data) {
            if (err) { toast(err, 'error'); return; }
            EH.canvas.students    = data.students    || [];
            EH.canvas.assignments = data.assignments || {};
            EH.canvas.occupancy   = data.occupancy   || {};
            // Ensure each canvas hall is keyed in assignments
            EH.canvas.halls.forEach(h => {
                if (!EH.canvas.assignments[h.id]) EH.canvas.assignments[h.id] = [];
            });

            renderUnassigned(data.unassigned || []);
            renderCanvas();
            updateStats();
            cb && cb();
        });
    }

    $(document).on('click', '#btn-eh-load-students', function () {
        loadStudents();
    });

    // Auto-load when both grade+section are selected
    $(document).on('change', '#eh-filter-grade, #eh-filter-section', function () {
        if ($('#eh-filter-grade').val() && $('#eh-filter-section').val()) {
            loadStudents();
        }
    });

    /* ─── Render: unassigned panel ────────────────────────────────────────────── */
    function renderUnassigned(students) {
        const $body = $('#eh-student-panel-body').empty();
        $('#eh-student-count').text(students.length);

        if (!EH.canvas.gradeId && !EH.canvas.sectionId) {
            $body.html('<p style="color:#9ca3af;text-align:center;padding:24px;font-size:13px;">اختر الصف والشعبة<br>لعرض الطلاب</p>');
        } else if (!students.length) {
            $body.html('<p style="color:#22c55e;text-align:center;padding:20px;">✓ جميع الطلاب موزعون</p>');
        } else {
            students.forEach((s, i) => $body.append(makeStudentCard(s, '')));
        }
        bindDragDrop();
    }

    /* ─── Render: canvas grid ─────────────────────────────────────────────────── */
    function renderCanvas() {
        const $grid = $('#eh-canvas-grid').empty();

        if (!EH.canvas.halls.length) {
            $grid.html(`
                <div class="eh-canvas-empty">
                    <span class="dashicons dashicons-building" style="font-size:40px;width:40px;height:40px;color:#d1d5db;"></span>
                    <p>انقر <strong>"+إضافة قاعة"</strong> للبدء<br><em>Click "+ Add Hall" to start</em></p>
                </div>`);
            return;
        }

        EH.canvas.halls.forEach(hall => {
            const students = EH.canvas.assignments[hall.id] || [];
            $grid.append(makeHallCard(hall, students));
        });

        bindDragDrop();
    }

    function makeHallCard(hall, students) {
        const cap  = parseInt(hall.capacity);
        const cnt  = students.length;
        const totalOcc = parseInt(EH.canvas.occupancy[hall.id] || cnt);
        const full = totalOcc >= cap;
        const pct  = cap > 0 ? Math.min(100, Math.round(totalOcc / cap * 100)) : 0;

        const $card = $('<div class="eh-hall-card">')
            .attr({ 'data-hall-id': hall.id, 'data-capacity': cap })
            .attr('id', 'eh-hall-' + hall.id)
            .toggleClass('full', full);

        // Header
        const $headerRight = $('<div style="display:flex;gap:6px;align-items:center;">').append(
            $('<span class="eh-hall-capacity-badge' + (full ? ' full' : '') + '">').text(totalOcc + '/' + cap),
            $('<button class="btn-eh-print-hall" type="button" title="طباعة هذه القاعة" style="background:rgba(255,255,255,.18);border:none;color:#fff;border-radius:5px;padding:3px 8px;cursor:pointer;font-size:12px;">').html('🖨')
                .data('hall-id', hall.id).data('hall-name', hall.hall_name),
            EH.isAdmin
                ? $('<button class="btn-remove-canvas-hall" type="button" title="Remove">✕</button>').data('hall-id', hall.id)
                : ''
        );
        $card.append($('<div class="eh-hall-card-header">').append(
            $('<div>').append(
                $('<h3>').html('<span class="dashicons dashicons-building" style="color:#fff;font-size:16px;"></span> ' + esc(hall.hall_name)),
                $('<div style="margin-top:6px;">').append(
                    $('<div class="eh-hall-inv-list">').attr('data-hall-id', hall.id).css({ 'margin-top': '2px', 'display': 'flex', 'gap': '4px', 'flex-wrap': 'wrap' })
                )
            ),
            $headerRight
        ));

        // Trigger load for this hall's invigilators
        loadInvigilators(hall.id);

        // Body
        const $body = $('<div class="eh-hall-card-body">').toggleClass('is-empty', !cnt);
        if (!cnt) {
            $body.append($('<div class="eh-drop-hint">').text('← اسحب الطلاب هنا →'));
        } else {
            students.forEach((s, i) => $body.append(makeStudentCard(s, s.seat_number || i + 1)));
        }
        $card.append($body);

        // Progress bar
        $card.append($('<div class="eh-hall-progress">').append(
            $('<div class="eh-hall-progress-fill' + (full ? ' full' : '') + '">').css('width', pct + '%')
        ));

        return $card;
    }

    function makeStudentCard(s, seat) {
        const id   = s.id || s.student_id;
        const meta = [s.grade_name, s.section_name].filter(Boolean).join(' › ');
        return $('<div class="eh-student-item">')
            .attr({ 'data-student-id': id, 'data-student-name': s.student_name })
            .append(
                $('<span class="seat-badge">').text(seat || ''),
                $('<div class="student-info">').append(
                    $('<div class="student-name">').text(s.student_name),
                    $('<div class="student-meta">').text(meta)
                ),
                EH.isAdmin ? $('<button class="btn-remove-student" type="button" title="Unassign">✕</button>') : ''
            );
    }

    /* ─── Helpers ─────────────────────────────────────────────────────────────── */
    function getUnassignedList() {
        const assignedIds = new Set();
        Object.values(EH.canvas.assignments).forEach(list => list.forEach(s => assignedIds.add(parseInt(s.id || s.student_id))));
        return EH.canvas.students.filter(s => !assignedIds.has(parseInt(s.id || s.student_id)));
    }

    function findStudentObj(sid) {
        sid = parseInt(sid);
        return EH.canvas.students.find(s => parseInt(s.id || s.student_id) === sid);
    }

    function esc(str) {
        return $('<span>').text(str).html();
    }

    /* ─── Canvas badges in toolbar ────────────────────────────────────────────── */
    function renderCanvasBadges() {
        const $b = $('#eh-canvas-badges').empty();
        EH.canvas.halls.forEach(h => {
            $b.append($('<span class="eh-canvas-badge">').append(
                $('<span>').text(h.hall_name),
                $('<button class="btn-remove-canvas-hall" type="button" style="background:none;border:none;cursor:pointer;font-size:14px;color:#ef4444;">✕</button>')
                    .data('hall-id', h.id)
            ));
        });
    }

    /* ─── Stats ───────────────────────────────────────────────────────────────── */
    function updateStats() {
        let assigned = 0;
        EH.canvas.halls.forEach(h => {
            const cnt = (EH.canvas.assignments[h.id] || []).length;
            assigned += cnt;
            const totalOcc = parseInt(EH.canvas.occupancy[h.id] || cnt);
            const cap  = parseInt(h.capacity);
            const pct  = cap ? Math.min(100, Math.round(totalOcc / cap * 100)) : 0;
            const $c   = $('#eh-hall-' + h.id);
            $c.find('.eh-hall-capacity-badge').text(totalOcc + '/' + cap).toggleClass('full', totalOcc >= cap);
            $c.toggleClass('full', totalOcc >= cap);
            $c.find('.eh-hall-progress-fill').css('width', pct + '%').toggleClass('full', pct >= 100);
        });

        const total      = EH.canvas.students.length;
        const unassigned = Math.max(0, total - assigned);

        $('#eh-stat-students').text(assigned);
        $('#eh-stat-unassigned').text(unassigned);
        $('#eh-student-count').text(unassigned);
    }

    /* ─── Hall picker ─────────────────────────────────────────────────────────── */
    $(document).on('click', '#btn-eh-add-hall', function () {
        const onCanvas = EH.canvas.halls.map(h => parseInt(h.id));
        const avail    = EH.allHalls.filter(h => !onCanvas.includes(parseInt(h.id)));

        const $list = $('#eh-hall-picker-list').empty();
        if (!avail.length) {
            $list.html('<p style="text-align:center;color:#6b7280;">كل القاعات مضافة للوحة توزيع القاعات بالفعل.</p>');
        } else {
            avail.forEach(h => {
                $list.append(
                    $('<div class="eh-hall-picker-item">').append(
                        $('<div>').append(
                            $('<strong>').text(h.hall_name),
                            $('<span style="margin-right:8px;color:#6b7280;font-size:13px;">').text('(' + h.capacity + ' طالب)')
                        ),
                        $('<button class="button button-primary btn-pick-hall" type="button">').text('+ إضافة').data('hall', h)
                    )
                );
            });
        }
        $('#eh-hall-picker-modal').addClass('active');
    });

    $(document).on('click', '.btn-pick-hall', function () {
        const hall = $(this).data('hall');
        if (!EH.canvas.halls.find(h => parseInt(h.id) === parseInt(hall.id))) {
            EH.canvas.halls.push(hall);
            if (!EH.canvas.assignments[hall.id]) EH.canvas.assignments[hall.id] = [];
        }
        $('#eh-hall-picker-modal').removeClass('active');
        renderCanvas();
        renderCanvasBadges();
        updateStats();
        saveState();

        // Reload students from server with updated canvas halls
        if (EH.canvas.gradeId || EH.canvas.sectionId) loadStudents();
        toast(hall.hall_name + ' أضيفت للوحة توزيع القاعات ✓', 'success');
    });

    // Remove hall from canvas
    $(document).on('click', '.btn-remove-canvas-hall', function (e) {
        e.stopPropagation();
        const hallId = parseInt($(this).data('hall-id'));
        const hall   = EH.canvas.halls.find(h => parseInt(h.id) === hallId);
        if (!hall) return;
        if (!confirm('إزالة "' + hall.hall_name + '" من لوحة توزيع القاعات؟')) return;

        EH.canvas.halls = EH.canvas.halls.filter(h => parseInt(h.id) !== hallId);
        delete EH.canvas.assignments[hallId];
        renderCanvas();
        renderCanvasBadges();
        if (EH.canvas.gradeId || EH.canvas.sectionId) {
            // Refresh unassigned (students who were in that hall are now unassigned)
            renderUnassigned(getUnassignedList());
        }
        updateStats();
        saveState();
    });

    /* ─── Drag & Drop (jQuery UI Draggable/Droppable) ─────────────────────────── */
    // HTML5 native D&D is unreliable with dynamic/delegated events.
    // jQuery UI Draggable/Droppable (already bundled with WordPress) is used instead.

    EH.dragSrc = null; // { studentId, fromHallId }

    function bindDragDrop() {
        if (!EH.isAdmin) return;

        if (typeof $.fn.sortable === 'undefined') {
            console.error('[EH] ERROR: jQuery UI Sortable NOT found! Drag-and-drop will not work.');
            return;
        }

        const $lists = $('#eh-student-panel-body, .eh-hall-card-body');
        if (!$lists.length) return;

        // Clean up old instances
        $lists.each(function() {
            if ($(this).hasClass('ui-sortable')) {
                try { $(this).sortable('destroy'); } catch(e) {}
            }
        });

        console.log('[EH] Initializing Sortable on ' + $lists.length + ' lists');

        $lists.sortable({
            connectWith: '#eh-student-panel-body, .eh-hall-card-body',
            items: '.eh-student-item',
            placeholder: 'eh-student-item sortable-placeholder',
            tolerance: 'pointer',
            cursor: 'grabbing',
            helper: 'clone',
            appendTo: 'body',
            zIndex: 999999,
            start: function(event, ui) {
                ui.item.addClass('dragging');
                ui.placeholder.css({
                    'visibility': 'visible',
                    'background-color': 'rgba(255,255,255,0.4)',
                    'border': '2px dashed #fb923c',
                    'height': ui.item.outerHeight(),
                    'border-radius': '8px',
                    'margin-bottom': '5px'
                });
            },
            stop: function(event, ui) {
                ui.item.removeClass('dragging');
            },
            receive: function(event, ui) {
                const $item = ui.item;
                const sid = parseInt($item.attr('data-student-id'));
                const $targetList = $(this);
                const $sourceList = ui.sender;

                const toHallIdRaw = $targetList.closest('.eh-hall-card').attr('data-hall-id');
                const fromIdRaw   = $sourceList.closest('.eh-hall-card').attr('data-hall-id');
                
                const toHallId = toHallIdRaw ? parseInt(toHallIdRaw) : 'unassigned';
                const fromId   = fromIdRaw   ? parseInt(fromIdRaw)   : 'unassigned';

                if (toHallId === fromId) return;

                console.log('[EH] Moving student ' + sid + ' from ' + fromId + ' to ' + toHallId);

                const student = findStudentObj(sid);
                if (!student) {
                    console.error('[EH] Error: Student ' + sid + ' not found in master list.');
                    toast('خطأ: الطالب غير موجود', 'error');
                    $(ui.sender).sortable('cancel');
                    return;
                }

                if (toHallId !== 'unassigned') {
                    const hall = EH.canvas.halls.find(h => parseInt(h.id) === toHallId);
                    const totalOcc = parseInt(EH.canvas.occupancy[toHallId] || 0);
                    const capacity = hall ? parseInt(hall.capacity) : 0;
                    
                    console.log('[EH] Capacity Check: Hall=' + toHallId + ' Occupancy=' + totalOcc + ' Capacity=' + capacity);

                    if (capacity > 0 && totalOcc >= capacity) {
                        toast('القاعة ممتلئة / Hall is full', 'error');
                        $(ui.sender).sortable('cancel');
                        return;
                    }
                }

                // State sync: Remove from source
                if (fromId !== 'unassigned') {
                    EH.canvas.assignments[fromId] = (EH.canvas.assignments[fromId] || [])
                        .filter(s => parseInt(s.id || s.student_id) !== sid);
                    EH.canvas.occupancy[fromId] = Math.max(0, (EH.canvas.occupancy[fromId] || 1) - 1);
                }
                
                // State sync: Add to target
                if (toHallId !== 'unassigned') {
                    if (!EH.canvas.assignments[toHallId]) EH.canvas.assignments[toHallId] = [];
                    const exists = EH.canvas.assignments[toHallId].some(s => parseInt(s.id || s.student_id) === sid);
                    if (!exists) {
                        EH.canvas.assignments[toHallId].push(student);
                        EH.canvas.occupancy[toHallId] = (EH.canvas.occupancy[toHallId] || 0) + 1;
                    }
                }

                console.log('[EH] Assignments State:', JSON.parse(JSON.stringify(EH.canvas.assignments)));

                // Refresh UI after a small delay
                setTimeout(() => {
                    renderCanvas();
                    renderUnassigned(getUnassignedList());
                    updateStats();
                }, 150);

                const action = toHallId === 'unassigned' ? 'olama_eh_remove_student' : 'olama_eh_move_student';
                const data   = { student_id: sid };
                if (toHallId !== 'unassigned') data.hall_id = toHallId;

                ajax(action, data, function (err, res) {
                    if (err) { 
                        console.error('[EH] AJAX Error:', err);
                        toast(err, 'error'); 
                        loadStudents(); 
                    } else {
                        // Sync occupancy from server if returned
                        if (res && res.occupancy) {
                            Object.assign(EH.canvas.occupancy, res.occupancy);
                            updateStats();
                        }
                        toast('تم النقل ✓', 'success');
                    }
                });
            },
            update: function(event, ui) {
                // Triggered when reordering within the same list OR after receive
                const $targetList = $(this);
                const isHall = $targetList.hasClass('eh-hall-card-body');
                if (!isHall) return;

                const hallId = parseInt($targetList.closest('.eh-hall-card').attr('data-hall-id'));
                if (!hallId) return;

                // Only handle if it was an internal reorder (ui.sender is null)
                // OR if it was a receive (ui.sender is NOT null) but we handle it here collectively
                // Wait, sortable 'update' fires for both.
                
                const studentIds = [];
                $targetList.find('.eh-student-item').each(function() {
                    studentIds.push(parseInt($(this).attr('data-student-id')));
                });

                console.log('[EH] Saving sort order for hall ' + hallId, studentIds);

                ajax('olama_eh_sort_students', {
                    hall_id: hallId,
                    student_ids: studentIds
                }, function(err) {
                    if (err) toast(err, 'error');
                    else {
                        toast('تم حفظ الترتيب ✓', 'success');
                        // Update seat badges locally
                        $targetList.find('.eh-student-item').each(function(idx) {
                            $(this).find('.seat-badge').text(idx + 1);
                        });
                    }
                });
            }
        });
    }

    // ── Remove student button (✕) ──────────────────────────────────────────────
    $(document).on('click', '.btn-remove-student', function (e) {
        if (!EH.isAdmin) return;
        e.stopPropagation();
        const $item  = $(this).closest('.eh-student-item');
        const sid    = parseInt($item.attr('data-student-id'));
        const hallId = parseInt($item.closest('.eh-hall-card').attr('data-hall-id'));
        if (!hallId) return;

        EH.canvas.assignments[hallId] = (EH.canvas.assignments[hallId] || [])
            .filter(s => parseInt(s.id || s.student_id) !== sid);

        renderCanvas();
        renderUnassigned(getUnassignedList());
        updateStats();

        ajax('olama_eh_remove_student', { student_id: sid }, err => {
            if (err) toast(err, 'error');
        });
    });

    function findStudentObj(id) {
        const sid = parseInt(id);
        if (isNaN(sid)) return null;

        // Master list is the most reliable source for the student object
        if (Array.isArray(EH.canvas.students)) {
            const found = EH.canvas.students.find(s => parseInt(s.id || s.student_id) === sid);
            if (found) return found;
        }

        // Fallback to assignments search
        for (const hId in EH.canvas.assignments) {
            const found = EH.canvas.assignments[hId].find(s => parseInt(s.id || s.student_id) === sid);
            if (found) return found;
        }

        return null;
    }

    function getUnassignedList() {
        const assignedSet = new Set();
        Object.values(EH.canvas.assignments).forEach(arr =>
            arr.forEach(s => {
                const id = parseInt(s.id || s.student_id);
                if (!isNaN(id)) assignedSet.add(id);
            })
        );
        return EH.canvas.students.filter(s => !assignedSet.has(parseInt(s.id || s.student_id)));
    }

    /* ─── Auto Distribute ─────────────────────────────────────────────────────── */
    $(document).on('click', '#btn-eh-auto-distribute', function () {
        if (!EH.canvas.halls.length) { toast('أضف قاعة أولاً / Add halls first', 'error'); return; }
        if (!EH.canvas.gradeId && !EH.canvas.sectionId) { toast('اختر الصف والشعبة / Select grade and section first', 'error'); return; }

        const unassigned = getUnassignedList();
        if (!confirm('توزيع ' + unassigned.length + ' طالب على ' + EH.canvas.halls.length + ' قاعة؟\nDistribute ' + unassigned.length + ' students across ' + EH.canvas.halls.length + ' halls?')) return;

        const $btn = $(this).addClass('eh-loading');

        ajax('olama_eh_auto_distribute', {
            grade_id:        EH.canvas.gradeId,
            section_id:      EH.canvas.sectionId,
            canvas_hall_ids: EH.canvas.halls.map(h => h.id),
            clear_existing:  1,
        }, function (err, data) {
            $btn.removeClass('eh-loading');
            if (err) { toast(err, 'error'); return; }

            EH.canvas.assignments = data.assignments || {};
            if (data.occupancy) Object.assign(EH.canvas.occupancy, data.occupancy);

            EH.canvas.halls.forEach(h => {
                if (!EH.canvas.assignments[h.id]) EH.canvas.assignments[h.id] = [];
            });

            renderCanvas();
            renderUnassigned(data.unassigned || getUnassignedList());
            updateStats();
            toast(data.message, 'success', 5500);
        });
    });

    /* ─── Clear canvas context ────────────────────────────────────────────────── */
    $(document).on('click', '#btn-eh-clear-all', function () {
        if (!confirm('مسح توزيع الطلاب المحددين على القاعات؟ / Clear current assignments?')) return;

        ajax('olama_eh_clear_context', {
            grade_id:        EH.canvas.gradeId,
            section_id:      EH.canvas.sectionId,
            canvas_hall_ids: EH.canvas.halls.map(h => h.id),
        }, function (err, data) {
            if (err) { toast(err, 'error'); return; }
            if (data.occupancy) Object.assign(EH.canvas.occupancy, data.occupancy);
            EH.canvas.assignments = data.assignments || {};
            // Ensure each canvas hall is keyed
            EH.canvas.halls.forEach(h => {
                if (!EH.canvas.assignments[h.id]) EH.canvas.assignments[h.id] = [];
            });

            renderCanvas();
            renderUnassigned(data.unassigned || getUnassignedList());
            updateStats();
            toast(data.message, 'info');
        });
    });

    /* ─── Print ───────────────────────────────────────────────────────────────── */

    /**
     * Safe HTML entity encoding – works in the popup window context too.
     */
    function h(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /**
     * Open a rich HTML print report in a new tab.
     * @param {number|null} filterHallId – when set, only print that one hall.
     * @param {Array|null} overrideHalls – list of hall objects
     * @param {Object|null} overrideAssignments – { hall_id: [studentObjs] }
     */
    function buildPrintReport(filterHallId, overrideHalls = null, overrideAssignments = null) {
        let gradeName     = h($('#eh-filter-grade option:selected').text().trim());
        let sectionName   = h($('#eh-filter-section option:selected').text().trim());
        const yearName    = h(olamaExamHall.yearName    || '');
        const semName     = h(olamaExamHall.semesterName || '');
        const today       = new Date().toLocaleDateString('ar-SA', { year:'numeric', month:'long', day:'numeric' });
        const schoolName  = h(document.querySelector('#wpadminbar .ab-item') ? (document.title.length > 4 ? document.title.split(/[–\-|]/)[0].trim() : '') : '');

        if (overrideAssignments) {
            // Global report - don't use specific grade/section names in subheader
            gradeName = '';
            sectionName = '';
        }

        let assignments = overrideAssignments || EH.canvas.assignments;
        let halls = [];
        
        if (overrideHalls) {
            halls = filterHallId 
                ? overrideHalls.filter(h => parseInt(h.id) === parseInt(filterHallId))
                : overrideHalls;
        } else {
            halls = filterHallId
                ? EH.canvas.halls.filter(hall => parseInt(hall.id) === parseInt(filterHallId))
                : EH.canvas.halls;
        }

        if (!halls.length) { toast('لا توجد بيانات للطباعة', 'error'); return; }

        let totalStudents = 0;
        let hallsHtml = '';

        halls.forEach((hall, hallIdx) => {
            const rawList = assignments[hall.id] || [];
            // Sort by Grade (ASC), Section (ASC), then Name (ASC)
            const students = rawList.slice().sort((a, b) => {
                const gA = a.grade_name || '';
                const gB = b.grade_name || '';
                if (gA !== gB) return gA.localeCompare(gB);

                const sA = a.section_name || '';
                const sB = b.section_name || '';
                if (sA !== sB) return sA.localeCompare(sB);

                return (a.student_name || '').localeCompare(b.student_name || '');
            });
            totalStudents += students.length;
            const pct = hall.capacity > 0 ? Math.min(100, Math.round(students.length / hall.capacity * 100)) : 0;

            const rowsHtml = students.map((s, i) => {
                const gradeCell   = h(s.grade_name   || '');
                const sectionCell = h(s.section_name || '');
                const nameCell    = h(s.student_name || '');
                const seatNo      = i + 1; // Sequential serial number for report
                const rowClass    = i % 2 === 0 ? '' : ' class="alt"';
                return `<tr${rowClass}>
                    <td class="seat">${seatNo}</td>
                    <td class="name">${nameCell}</td>
                    <td>${gradeCell}</td>
                    <td>${sectionCell}</td>
                    <td class="sig"></td>
                </tr>`;
            }).join('');

            const breakClass = hallIdx > 0 ? ' page-top' : '';
            hallsHtml += `
            <div class="hall-section${breakClass}">
                <div class="hall-header">
                    <div class="hall-title">🏛 ${h(hall.hall_name)}</div>
                    <div class="hall-chips">
                        <span class="chip">السعة: ${h(hall.capacity)}</span>
                        <span class="chip assigned">المُسجَّلون: ${students.length}</span>
                        <span class="chip fill">الامتلاء: ${pct}%</span>
                    </div>
                </div>
                <table class="student-table">
                    <thead>
                        <tr>
                            <th class="th-seat">م / Seat</th>
                            <th>اسم الطالب / Student Name</th>
                            <th class="th-sm">الصف</th>
                            <th class="th-sm">الشعبة</th>
                            <th class="th-sig">التوقيع / Sig.</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rowsHtml || '<tr><td colspan="5" class="empty-row">لا يوجد طلاب مُوزَّعون في هذه القاعة</td></tr>'}
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" style="font-weight:700;padding:8px 12px;">الإجمالي: ${students.length} طالب</td>
                            <td colspan="3" style="text-align:left;padding:8px 12px;font-size:11px;color:#666;">Verified by: _____________ Date: _______</td>
                        </tr>
                    </tfoot>
                </table>
            </div>`;
        });

        const subTitle = filterHallId
            ? 'كشف قاعة: ' + h(halls[0] ? halls[0].hall_name : '')
            : 'كشف توزيع طلاب قاعات الاختبار';

        const contextLine = (gradeName || sectionName)
            ? `<div class="context-bar">
                <span>📋 الصف: <strong>${gradeName}</strong></span>
                <span>📌 الشعبة: <strong>${sectionName}</strong></span>
                <span>🏫 القاعات: <strong>${halls.length}</strong></span>
                <span>👥 إجمالي الطلاب: <strong>${totalStudents}</strong></span>
               </div>`
            : '';

        const html = `<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
<meta charset="UTF-8">
<title>${subTitle}</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body {
    font-family: 'Segoe UI', Tahoma, 'Arial', sans-serif;
    font-size: 12px; color: #000; background: #fff; direction: rtl;
  }
  
  /* ── Report Header ── */
  .report-header {
    text-align: center; padding: 15px 20px;
    border-bottom: 2px solid #000; margin-bottom: 10px;
  }
  .school-name { font-size: 18px; font-weight: 800; margin-bottom: 5px; }
  .report-title { font-size: 16px; font-weight: 700; margin-bottom: 10px; text-decoration: underline; }
  .meta-row {
    display: flex; justify-content: center; gap: 10px;
    font-size: 11px;
  }
  .meta-chip {
    border: 1px solid #ccc; border-radius: 4px;
    padding: 2px 8px; background: #f9f9f9;
  }

  /* ── Context Bar ── */
  .context-bar {
    background: #f0f0f0; border: 1px solid #ddd;
    padding: 8px 15px; margin: 0 20px 15px;
    display: flex; gap: 20px; align-items: center;
    justify-content: center; border-radius: 4px;
  }
  .context-bar strong { font-size: 14px; }

  /* ── Hall Section ── */
  .hall-section { margin: 0 20px 25px; page-break-inside: avoid; }
  .hall-header {
    display: flex; justify-content: space-between; align-items: center;
    background: #333; color: #fff; padding: 8px 12px;
    border: 1px solid #333;
  }
  .hall-title { font-size: 15px; font-weight: 700; }
  .hall-chips { display: flex; gap: 10px; font-size: 11px; }
  .chip { background: rgba(255,255,255,0.2); padding: 1px 6px; border-radius: 3px; }

  /* ── Student Table ── */
  .student-table {
    width: 100%; border-collapse: collapse;
    border: 1px solid #000;
  }
  .student-table th {
    padding: 6px 8px; text-align: right;
    font-size: 11px; font-weight: 700;
    background: #eee; border: 1px solid #000;
  }
  .student-table td {
    padding: 5px 8px; border: 1px solid #000;
    font-size: 12px;
  }
  .student-table tr.alt td { background: #fafafa; }
  .student-table td.seat {
    text-align: center; font-weight: 800; width: 45px;
  }
  .student-table td.name { font-weight: 600; }
  .student-table td.sig  { width: 120px; }
  .student-table tfoot td {
    background: #eee; font-weight: bold; padding: 6px 8px;
    border: 1px solid #000;
  }

  /* ── Summary & Footer ── */
  .summary-box {
    margin: 10px 20px; padding: 10px;
    border: 1px solid #000; background: #f9f9f9;
    display: flex; gap: 25px; font-size: 12px;
  }
  .summary-box .sval { font-weight: 800; }
  .report-footer {
    text-align: center; margin: 20px; padding-top: 10px;
    border-top: 1px dashed #ccc; font-size: 10px; color: #666;
  }

  @media print {
    @page { size: A4 portrait; margin: 10mm; }
    .no-print { display: none !important; }
    .hall-section.page-top { page-break-before: always; }
    body { font-size: 11px; }
  }

  @media screen {
    body { background: #e0e0e0; padding-bottom: 50px; }
    .page-container {
      background: #fff; width: 210mm; margin: 20px auto;
      box-shadow: 0 0 10px rgba(0,0,0,0.2); min-height: 297mm;
    }
    .print-btn-bar {
      position: sticky; top: 0; z-index: 1000;
      background: #2c3e50; color: #fff; padding: 10px 20px;
      display: flex; justify-content: space-between; align-items: center;
    }
    .print-btn {
      background: #27ae60; color: #fff; border: none; padding: 8px 16px;
      border-radius: 4px; cursor: pointer; font-weight: bold;
    }
    .close-btn {
      background: #e74c3c; color: #fff; border: none; padding: 8px 16px;
      border-radius: 4px; cursor: pointer; margin-right: 10px;
    }
  }
</style>
</head>
<body>
  <div class="print-btn-bar no-print">
    <span style="font-weight:bold;">🖨 معاينة التقارير / Print Preview</span>
    <div>
      <button class="close-btn" onclick="window.close()">إغلاق / Close</button>
      <button class="print-btn" onclick="window.print()">طباعة / Print</button>
    </div>
  </div>

  <div class="page-container">
    <div class="report-header">
      <div class="school-name">${schoolName}</div>
      <div class="report-title">${subTitle}</div>
      <div class="meta-row">
        <span class="meta-chip">العام: ${yearName}</span>
        <span class="meta-chip">الفصل: ${semName}</span>
        <span class="meta-chip">التاريخ: ${today}</span>
      </div>
    </div>

    ${contextLine}

    ${hallsHtml}

    <div class="summary-box">
      <div>طلاب: <span class="sval">${totalStudents}</span></div>
      <div>قاعات: <span class="sval">${halls.length}</span></div>
      <div>سعة: <span class="sval">${halls.reduce((s,h) => s + parseInt(h.capacity||0), 0)}</span></div>
    </div>

    <div class="report-footer">
      نظام توزيع قاعات الاختبار - ${new Date().toLocaleString('ar-SA')}
    </div>
  </div>
</body>
</html>`;


        const popup = window.open('', '_blank', 'width=900,height=700,scrollbars=yes');
        if (!popup) { toast('يرجى السماح بالنوافذ المنبثقة / Allow popups', 'error', 5000); return; }
        popup.document.open();
        popup.document.write(html);
        popup.document.close();
    }

    // Print Section (Current context)
    $(document).on('click', '#btn-eh-print-section', function () {
        if (!EH.canvas.gradeId && !EH.canvas.sectionId) {
            toast('اختر الصف والشعبة أولاً / Select grade & section', 'error');
            return;
        }
        buildPrintReport(null);
    });

    // Print All Halls on Canvas
    $(document).on('click', '#btn-eh-print-all-halls', function () {
        if (!EH.canvas.halls.length) { toast('لا توجد قاعات على لوحة توزيع القاعات', 'error'); return; }
        buildPrintReport(null);
    });

    // Print Hall (Show selection modal)
    $(document).on('click', '#btn-eh-print-hall-select', function () {
        const $list = $('#eh-hall-print-list').empty();
        if (!EH.allHalls.length) {
            $list.html('<p>لا توجد قاعات مضافة.</p>');
        } else {
            EH.allHalls.forEach(h => {
                const $item = $('<div class="eh-hall-picker-item">').append(
                    $('<div>').append($('<strong>').text(h.hall_name), $('<small>').text(' (' + h.capacity + ')')),
                    $('<button class="button btn-do-print-hall">').text('🖨 طباعة').data('hall-id', h.id)
                );
                $list.append($item);
            });
        }
        $('#eh-hall-print-modal').addClass('active');
    });

    $(document).on('click', '.btn-do-print-hall', function() {
        const hallId = $(this).data('hall-id');
        $('#eh-hall-print-modal').removeClass('active');
        
        // Fetch specific hall data globally
        ajax('olama_eh_get_global_report', {}, function(err, data) {
            if (err) { toast(err, 'error'); return; }
            const halls = EH.allHalls;
            buildPrintReport(hallId, halls, data.assignments);
        });
    });

    // Print All Sections (Global)
    $(document).on('click', '#btn-eh-print-all-sections', function () {
        const $btn = $(this).prop('disabled', true);
        ajax('olama_eh_get_global_report', {}, function(err, data) {
            $btn.prop('disabled', false);
            if (err) { toast(err, 'error'); return; }
            
            // Build unique hall list from assignments if needed, 
            // but usually EH.allHalls is sufficient
            buildPrintReport(null, EH.allHalls, data.assignments);
        });
    });

    // Per-hall print button (already exists on card header)
    $(document).on('click', '.btn-eh-print-hall', function (e) {
        e.stopPropagation();
        const hallId = $(this).data('hall-id');
        buildPrintReport(hallId);
    });

    /* ─── Modal close ─────────────────────────────────────────────────────────── */
    $(document).on('click', '.eh-modal-close, .eh-modal-overlay', function (e) {
        if ($(e.target).is('.eh-modal-overlay') || $(e.target).is('.eh-modal-close') || $(e.target).closest('.eh-modal-close').length) {
            $('.eh-modal-overlay').removeClass('active');
        }
    });

    /* ─── Hall Form (Halls Tab) ───────────────────────────────────────────────── */
    $(document).on('click', '#btn-eh-add-hall-form', function () {
        $('#eh-hall-id').val('');
        $('#eh-hall-form')[0] && $('#eh-hall-form')[0].reset();
        $('#eh-hall-modal').addClass('active');
    });

    $(document).on('click', '.btn-eh-edit-hall', function () {
        $('#eh-hall-id').val($(this).data('hall-id'));
        $('#eh-hall-name').val($(this).data('hall-name'));
        $('#eh-hall-capacity').val($(this).data('hall-cap'));
        $('#eh-hall-modal').addClass('active');
    });

    $(document).on('submit', '#eh-hall-form', function (e) {
        e.preventDefault();
        const $btn = $(this).find('[type=submit]').addClass('eh-loading');
        ajax('olama_eh_save_hall', {
            id:        $('#eh-hall-id').val(),
            hall_name: $('#eh-hall-name').val(),
            capacity:  $('#eh-hall-capacity').val(),
        }, function (err, data) {
            $btn.removeClass('eh-loading');
            if (err) { toast(err, 'error'); return; }
            $('#eh-hall-modal').removeClass('active');
            // Update global hall list
            EH.allHalls = data.halls || EH.allHalls;
            renderHallMgmt(data.halls);
            toast(data.message, 'success');
        });
    });

    $(document).on('click', '.btn-eh-delete-hall', function () {
        const hallId = $(this).data('hall-id');
        const name   = $(this).data('hall-name');
        if (!confirm('حذف قاعة "' + name + '"؟')) return;
        ajax('olama_eh_delete_hall', { hall_id: hallId }, function (err, data) {
            if (err) { toast(err, 'error'); return; }
            EH.allHalls = data.halls || [];
            renderHallMgmt(data.halls);
            toast(data.message, 'info');
        });
    });

    function renderHallMgmt(halls) {
        const $g = $('#eh-halls-manage-grid').empty();
        if (!halls || !halls.length) { $g.html('<p style="text-align:center;color:#6b7280;padding:30px;">لا توجد قاعات.</p>'); return; }
        halls.forEach(h => {
            $g.append($('<div class="eh-hall-manage-card">').append(
                $('<div class="hall-name">').html('<span class="dashicons dashicons-building" style="color:#1a73e8;"></span> ' + esc(h.hall_name)),
                $('<div class="hall-cap">').text('السعة: ' + h.capacity + ' طالب'),
                $('<div style="margin-top:8px;">').append(
                    $('<span class="eh-hall-inv-label">').text('المراقبون:'),
                    $('<div class="eh-hall-inv-list" data-hall-id="' + h.id + '">').html('<small style="color:#94a3b8;">جاري تحميل المراقبين...</small>')
                ),
                $('<div class="hall-actions">').append(
                    $('<button class="button btn-eh-edit-hall">').attr({ 'data-hall-id': h.id, 'data-hall-name': h.hall_name, 'data-hall-cap': h.capacity })
                        .html('<span class="dashicons dashicons-edit"></span> تعديل'),
                    $('<button class="button btn-eh-manage-invigilators">').attr({ 'data-hall-id': h.id, 'data-hall-name': h.hall_name })
                        .html('<span class="dashicons dashicons-id-alt"></span> المراقبين'),
                    $('<button class="button btn-eh-delete-hall">').attr({ 'data-hall-id': h.id, 'data-hall-name': h.hall_name })
                        .css({ color: '#dc2626' })
                        .html('<span class="dashicons dashicons-trash"></span> حذف')
                )
            ));
            // Trigger load for this hall's invigilators
            loadInvigilators(h.id);
        });
    }

    /* ─── Invigilators Logic ─────────────────────────────────────────────────── */
    function loadInvigilators(hallId) {
        // Show cached if available
        if (EH.canvas.invigilators[hallId]) {
            updateInvDisplay(hallId, EH.canvas.invigilators[hallId]);
        }

        ajax('olama_eh_get_invigilators', { hall_id: hallId }, function (err, data) {
            if (err) return;
            EH.canvas.invigilators[hallId] = data.assigned || [];
            updateInvDisplay(hallId, data.assigned || []);
        });
    }

    function updateInvDisplay(hallId, assigned) {
        // Update the badge in management grid
        const $list = $('.eh-hall-inv-list[data-hall-id="' + hallId + '"]').empty();
        if (assigned && assigned.length) {
            assigned.forEach(inv => {
                $list.append($('<span class="eh-hall-inv-badge">').text(inv.display_name));
            });
        } else {
            $list.html('<small style="color:#94a3b8;">لا يوجد مراقبون</small>');
        }

        // Also update canvas if present
        const $canvasList = $('#eh-hall-' + hallId + ' .eh-hall-inv-list').empty();
        if (assigned && assigned.length) {
            assigned.forEach(inv => {
                $canvasList.append($('<span class="eh-hall-inv-badge">').text(inv.display_name));
            });
        }
    }

    $(document).on('click', '.btn-eh-manage-invigilators', function () {
        const hallId = $(this).data('hall-id');
        const name   = $(this).data('hall-name');
        $('#eh-inv-hall-name').text(name);
        $('#btn-eh-add-invigilator').data('hall-id', hallId);
        
        const $modal = $('#eh-invigilator-modal').addClass('active');
        const $list  = $('#eh-inv-current-list').html('<p style="text-align:center;width:100%;"><span class="spinner is-active" style="float:none;"></span></p>');
        const $picker = $('#eh-inv-picker').empty().append('<option value="">-- اختر المعلم / الإداري --</option>');

        ajax('olama_eh_get_invigilators', { hall_id: hallId }, function (err, data) {
            if (err) { toast(err, 'error'); return; }
            
            // Render current
            $list.empty();
            if (!data.assigned || !data.assigned.length) {
                $list.html('<p style="color:#94a3b8;font-size:13px;">لا يوجد مراقبون حالياً</p>');
            } else {
                data.assigned.forEach(inv => {
                    $list.append(
                        $('<div class="eh-inv-tag">').append(
                            $('<span>').text(inv.display_name),
                            $('<button class="btn-remove-inv">✕</button>').data({ hall_id: hallId, inv_id: inv.invigilator_id })
                        )
                    );
                });
            }

            // Populate picker
            const assignedIds = (data.all_assigned || []).map(a => parseInt(a.invigilator_id));
            (data.available || []).forEach(u => {
                const isAssigned = assignedIds.includes(parseInt(u.ID));
                const $opt = $('<option>').val(u.ID).text(u.display_name + (isAssigned ? ' (موزع مسبقاً)' : ''));
                if (isAssigned) $opt.prop('disabled', true);
                $picker.append($opt);
            });
        });
    });

    $(document).on('click', '#btn-eh-add-invigilator', function () {
        const hallId = $(this).data('hall-id');
        const invId  = $('#eh-inv-picker').val();
        if (!invId) { toast('اختر مراقباً أولاً', 'error'); return; }

        const $btn = $(this).prop('disabled', true);
        ajax('olama_eh_assign_invigilator', { hall_id: hallId, invigilator_id: invId }, function (err, data) {
            $btn.prop('disabled', false);
            if (err) { toast(err, 'error'); return; }
            toast(data.message, 'success');
            $('.btn-eh-manage-invigilators[data-hall-id="' + hallId + '"]').click(); // Refresh modal
            loadInvigilators(hallId); // Refresh background cards
        });
    });

    $(document).on('click', '.btn-remove-inv', function () {
        const hallId = $(this).data('hall_id');
        const invId  = $(this).data('inv_id');
        if (!confirm('إزالة هذا المراقب؟')) return;

        ajax('olama_eh_remove_invigilator', { hall_id: hallId, invigilator_id: invId }, function (err, data) {
            if (err) { toast(err, 'error'); return; }
            toast(data.message, 'info');
            $('.btn-eh-manage-invigilators[data-hall-id="' + hallId + '"]').click(); // Refresh modal
            loadInvigilators(hallId); // Refresh background cards
        });
    });

    /* ─── Attendance Tab ──────────────────────────────────────────────────────── */

    function loadAttendance() {
        const hallId  = $('#eh-att-hall').val();
        const date    = $('#eh-att-date').val();
        const session = $('#eh-att-session').val() || '';

        if (!hallId) { $('#eh-att-table-wrap').hide(); return; }

        ajax('olama_eh_get_students', { hall_id: hallId, exam_date: date, session_label: session }, function (err, data) {
            if (err) return;
            const students = data.students || [];
            const $tbody = $('#eh-att-tbody').empty();
            if (!students.length) {
                $tbody.append('<tr><td colspan="4" style="text-align:center;padding:20px;color:#6b7280;">لا يوجد طلاب في هذه القاعة</td></tr>');
                $('#eh-att-table-wrap').show();
                return;
            }
            students.forEach(s => {
                const sid = s.student_id || s.id;
                const status = s.attendance_status || 'present';
                const $row = $('<tr>').append(
                    $('<td>').text(s.seat_number || ''),
                    $('<td>').html('<strong>' + esc(s.student_name) + '</strong>'),
                    $('<td>').text([s.grade_name, s.section_name].filter(Boolean).join(' / ')),
                    $('<td>').append(
                        $('<div class="status-toggle">').append(
                            $('<button type="button">')
                                .addClass('status-btn present' + (status === 'present' ? ' active' : ''))
                                .attr('data-sid', sid)
                                .attr('data-status', 'present')
                                .text('حاضر'),
                            $('<button type="button">')
                                .addClass('status-btn absent' + (status === 'absent' ? ' active' : ''))
                                .attr('data-sid', sid)
                                .attr('data-status', 'absent')
                                .text('غائب')
                        )
                    )
                );
                $tbody.append($row);
            });
            $('#print-att-date').text(date);
            $('#print-att-session').text(session);
            $('#eh-att-table-wrap').show();
        });
    }

    // Trigger load when hall dropdown changes
    $(document).on('change', '#eh-att-hall', function () {
        loadAttendance();
    });

    $(document).on('click', '.status-btn', function () {
        $(this).closest('.status-toggle').find('.status-btn').removeClass('active');
        $(this).addClass('active');
    });

    $(document).on('click', '#btn-mark-all-present', function () {
        $('#eh-att-tbody .status-toggle').each(function () {
            $(this).find('.status-btn').removeClass('active');
            $(this).find('.present').addClass('active');
        });
    });
    $(document).on('click', '#btn-mark-all-absent', function () {
        $('#eh-att-tbody .status-toggle').each(function () {
            $(this).find('.status-btn').removeClass('active');
            $(this).find('.absent').addClass('active');
        });
    });

    $(document).on('click', '#btn-eh-save-attendance', function () {
        const hallId  = $('#eh-att-hall').val();
        const date    = $('#eh-att-date').val();
        const session = $('#eh-att-session').val() || '';
        if (!hallId || !date) { toast('اختر قاعة وتاريخاً', 'error'); return; }

        const statuses = {};
        // Use attr() to read HTML data-* attributes reliably (avoids jQuery camelCase issue)
        $('#eh-att-tbody .status-btn.active').each(function () {
            const sid    = $(this).attr('data-sid');
            const status = $(this).attr('data-status');
            if (sid) statuses[sid] = status;
        });

        if (Object.keys(statuses).length === 0) {
            toast('لا يوجد طلاب لحفظ حضورهم', 'error');
            return;
        }

        const $btn = $(this).addClass('eh-loading');
        ajax('olama_eh_save_attendance', { hall_id: hallId, exam_date: date, session_label: session, statuses }, function (err, data) {
            $btn.removeClass('eh-loading');
            if (err) toast(err, 'error'); else toast(data.message, 'success');
        });
    });

    /* ─── Notes Tab ───────────────────────────────────────────────────────────── */
    $(document).on('change', '#eh-notes-hall', function () {
        const hallId = $(this).val();
        if (!hallId) return;
        ajax('olama_eh_get_students', { hall_id: hallId }, function (err, data) {
            if (err) return;
            const $sel = $('#eh-notes-student').empty().append('<option value="">-- اختر الطالب --</option>');
            (data.students || []).forEach(s => $sel.append($('<option>').val(s.student_id || s.id).text(s.student_name)));
        });
    });

    $(document).on('submit', '#eh-note-form', function (e) {
        e.preventDefault();
        const $btn = $(this).find('[type=submit]').addClass('eh-loading');
        ajax('olama_eh_save_note', {
            hall_id:    $('#eh-notes-hall').val(),
            student_id: $('#eh-notes-student').val(),
            exam_date:  $('#eh-notes-date').val() || new Date().toISOString().slice(0, 10),
            note_type:  $('#eh-note-type').val(),
            note_text:  $('#eh-note-text').val(),
        }, function (err, data) {
            $btn.removeClass('eh-loading');
            if (err) { toast(err, 'error'); return; }
            toast(data.message, 'success');
            $('#eh-note-form')[0].reset();
        });
    });

    $(document).on('click', '.btn-eh-delete-note', function () {
        const noteId = $(this).data('note-id');
        if (!confirm('حذف هذه الملاحظة؟')) return;
        ajax('olama_eh_delete_note', { note_id: noteId }, function (err, data) {
            if (err) { toast(err, 'error'); return; }
            toast(data.message, 'info');
        });
    });

    /* ─── Init ────────────────────────────────────────────────────────────────── */
    $(function () {
        const today = new Date().toISOString().slice(0, 10);
        if ($('#eh-att-date').length && !$('#eh-att-date').val()) $('#eh-att-date').val(today);
        if ($('#eh-notes-date').length && !$('#eh-notes-date').val()) $('#eh-notes-date').val(today);

        if ($('#eh-canvas-grid').length) {
            bindDragDrop(); // Global init
            // Restore previous canvas if any
            const restored = restoreState();
            renderCanvas();
            renderCanvasBadges();
            if (restored && (EH.canvas.halls.length > 0) &&
                (EH.canvas.gradeId || EH.canvas.sectionId)) {
                loadStudents();
            }
        }
    });

    // Expose loadAttendance globally so inline onclick in PHP templates can call it
    window.loadAttendance = loadAttendance;

})(jQuery);

