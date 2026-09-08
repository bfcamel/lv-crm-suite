(function ($) {
    'use strict';

    var lastFocusedElement = null;

    function responseMessage(response, fallback) {
        return response && response.data && response.data.message ? response.data.message : (fallback || LVApps.error);
    }

    function toast(message, type) {
        var $region = $('.lv-toast-region');
        if (!$region.length) {
            $region = $('<div class="lv-toast-region" role="status" aria-live="polite" aria-atomic="true"></div>').appendTo('body');
        }
        var kind = type === 'success' ? 'success' : (type === 'error' ? 'error' : 'info');
        var icon = kind === 'success' ? 'dashicons-yes-alt' : (kind === 'error' ? 'dashicons-warning' : 'dashicons-info-outline');
        var $toast = $('<div class="lv-toast" role="status"></div>')
            .addClass('is-' + kind)
            .append($('<span></span>').addClass('dashicons ' + icon))
            .append($('<span></span>').text(message || LVApps.error))
            .appendTo($region);
        window.requestAnimationFrame(function () { $toast.addClass('is-visible'); });
        window.setTimeout(function () {
            $toast.removeClass('is-visible');
            window.setTimeout(function () { $toast.remove(); }, 200);
        }, kind === 'error' ? 5200 : 3200);
    }

    function rememberFocus(element) {
        lastFocusedElement = element && element.focus ? element : document.activeElement;
    }

    function restoreFocus() {
        if (lastFocusedElement && document.documentElement.contains(lastFocusedElement)) {
            lastFocusedElement.focus();
        }
        lastFocusedElement = null;
    }

    function syncStatus($toggle, processed, label) {
        var $container = $toggle.closest('.lv-status-toggle, .lv-switch-card');
        $container.toggleClass('is-processed', processed);
        $container.find('.lv-status-text, .lv-switch-label').text(label);
    }

    function updateUnreadCount(count) {
        count = parseInt(count, 10) || 0;
        var $menu = $('#toplevel_page_lv-applications .wp-menu-name');
        var $bubble = $menu.find('.awaiting-mod');
        if (count > 0) {
            if (!$bubble.length) {
                $menu.append(' <span class="awaiting-mod"><span class="pending-count"></span></span>');
                $bubble = $menu.find('.awaiting-mod');
            }
            $bubble.find('.pending-count').text(count);
        } else {
            $bubble.remove();
        }
        var $bar = $('#wp-admin-bar-lv-applications-unprocessed .ab-label');
        if ($bar.length) {
            if (count > 0) $bar.text('Заявки: ' + count);
            else $('#wp-admin-bar-lv-applications-unprocessed').remove();
        }
    }

    $(document).on('change', '.lv-processed-toggle', function () {
        var $toggle = $(this);
        var id = parseInt($toggle.data('id'), 10);
        var processed = $toggle.is(':checked');
        var previous = !processed;
        var $container = $toggle.closest('.lv-status-toggle, .lv-switch-card');
        if (!id) return;

        $toggle.prop('disabled', true);
        $container.addClass('is-saving');

        $.post(LVApps.ajaxUrl, {
            action: 'lv_apps_toggle_processed',
            nonce: LVApps.toggleNonce,
            application_id: id,
            processed: processed ? '1' : '0'
        }).done(function (response) {
            if (!response || !response.success) {
                $toggle.prop('checked', previous);
                toast(responseMessage(response), 'error');
                return;
            }
            syncStatus($toggle, !!response.data.processed, response.data.label);
            updateUnreadCount(response.data.unprocessedCount);
            $('.lv-processed-toggle[data-id="' + id + '"]').not($toggle).each(function () {
                $(this).prop('checked', !!response.data.processed);
                syncStatus($(this), !!response.data.processed, response.data.label);
            });
        }).fail(function () {
            $toggle.prop('checked', previous);
            toast(LVApps.error, 'error');
        }).always(function () {
            $toggle.prop('disabled', false);
            $container.removeClass('is-saving');
        });
    });

    function selectedIds() {
        var ids = [];
        $('.lv-bulk-form tbody input[name="application[]"]:checked').each(function () {
            ids.push(String($(this).val()));
        });
        return ids;
    }

    function applicationSelectionScope() {
        return $('.lv-bulk-form input[name="selection_scope"]').val() === 'filtered' ? 'filtered' : 'page';
    }

    function selectedApplicationCount() {
        if (applicationSelectionScope() === 'filtered') {
            return parseInt($('.lv-selection-toolbar').data('total'), 10) || 0;
        }
        return selectedIds().length;
    }

    function resetFilteredApplicationScope() {
        $('.lv-bulk-form input[name="selection_scope"]').val('page');
    }

    function updateSelectionUi() {
        var ids = selectedIds();
        var count = ids.length;
        var totalFiltered = parseInt($('.lv-selection-toolbar').data('total'), 10) || 0;
        var totalPage = $('.lv-bulk-form tbody input[name="application[]"]').length;
        var filtered = applicationSelectionScope() === 'filtered';

        $('.lv-bulk-form input[name="selected_ids"]').val(ids.join(','));
        $('.lv-selected-count').text('Сейчас выбрано: ' + (filtered ? totalFiltered : count));
        $('.lv-export-form input[name="selected_ids"]').val(ids.join(','));
        $('.lv-bulk-form tbody tr').each(function () {
            $(this).toggleClass('is-selected', filtered || $(this).find('input[name="application[]"]').is(':checked'));
        });

        var $bar = $('.lv-selection-toolbar');
        var visible = filtered || count > 0;
        $bar.prop('hidden', !visible);
        $bar.find('.lv-selection-count').text('Выбрано: ' + (filtered ? totalFiltered : count));
        $bar.find('.lv-selection-scope-text').text(filtered ? 'Выбрана вся текущая выборка по фильтрам.' : 'Выбраны записи на текущей странице.');
        $bar.find('.lv-select-all-filtered').toggle(!filtered && totalFiltered > count && totalPage > 0 && count === totalPage);

        var checked = totalPage > 0 && count === totalPage;
        $('.lv-bulk-form thead .check-column input, .lv-bulk-form tfoot .check-column input')
            .prop('checked', checked || filtered)
            .prop('indeterminate', !filtered && count > 0 && count < totalPage);
    }

    $(document).on('change', '.lv-bulk-form thead .check-column input, .lv-bulk-form tfoot .check-column input', function () {
        resetFilteredApplicationScope();
        $('.lv-bulk-form tbody .check-column input').prop('checked', $(this).is(':checked'));
        updateSelectionUi();
    });
    $(document).on('change', '.lv-bulk-form tbody input[name="application[]"]', function () {
        resetFilteredApplicationScope();
        updateSelectionUi();
    });
    $(document).on('click', '.lv-select-all-filtered', function () {
        $('.lv-bulk-form input[name="selection_scope"]').val('filtered');
        $('.lv-bulk-form tbody input[name="application[]"]').prop('checked', true);
        updateSelectionUi();
    });
    $(document).on('click', '.lv-clear-application-selection', function () {
        resetFilteredApplicationScope();
        $('.lv-bulk-form input[name="application[]"], .lv-bulk-form thead .check-column input, .lv-bulk-form tfoot .check-column input').prop('checked', false);
        updateSelectionUi();
    });

    $(document).on('change', '.lv-per-page', function () {
        var base = String($(this).data('base-url') || window.location.href);
        try {
            var url = new URL(base, window.location.origin);
            url.searchParams.set('per_page', String($(this).val()));
            url.searchParams.delete('paged');
            window.location.href = url.toString();
        } catch (e) {
            window.location.href = base + (base.indexOf('?') >= 0 ? '&' : '?') + 'per_page=' + encodeURIComponent($(this).val());
        }
    });

    $(document).on('submit', '.lv-bulk-form', function (event) {
        var mode = $(this).find('select[name="bulk_status"]').val();
        var selected = selectedApplicationCount();
        if (!mode) {
            event.preventDefault();
            return;
        }
        if (!selected) {
            event.preventDefault();
            toast('Сначала выберите хотя бы одну заявку.', 'error');
            return;
        }
        // Moving to trash is reversible for 10 minutes, so confirmation only
        // remains for permanent deletion.
        if (mode === 'delete_permanently' && !window.confirm('Удалить выбранные заявки навсегда (' + selected + ')? Восстановление невозможно.')) event.preventDefault();
    });

    $(document).on('click', '.lv-delete-permanent', function (event) {
        if (!window.confirm(LVApps.deleteConfirm)) event.preventDefault();
    });

    // Highlight an item after restore/trash navigation.
    try {
        var highlightId = new URL(window.location.href).searchParams.get('lv_highlight');
        if (highlightId) {
            var $row = $('.lv-bulk-form tbody input[name="application[]"][value="' + highlightId + '"]').closest('tr');
            if ($row.length) {
                $row.addClass('is-highlighted');
                window.setTimeout(function () { $row.removeClass('is-highlighted'); }, 4500);
            }
        }
    } catch (e) {}

    // Saved filters.
    $(document).on('click', '.lv-show-save-filter', function () {
        $('.lv-save-filter-form').prop('hidden', false).hide().slideDown(140);
        $('#lv-filter-name').trigger('focus');
    });
    $(document).on('click', '.lv-cancel-save-filter', function () {
        $('.lv-save-filter-form').slideUp(140, function () { $(this).prop('hidden', true); });
    });

    // Quick-view drawer.
    function openDrawer(trigger) {
        rememberFocus(trigger);
        $('.lv-drawer-backdrop').prop('hidden', false).addClass('is-open');
        $('.lv-quick-drawer').attr('aria-hidden', 'false').addClass('is-open');
        $('body').addClass('lv-no-scroll');
        window.setTimeout(function () { $('.lv-drawer-close').trigger('focus'); }, 30);
    }
    function closeDrawer() {
        $('.lv-drawer-backdrop').removeClass('is-open');
        $('.lv-quick-drawer').attr('aria-hidden', 'true').removeClass('is-open');
        $('body').removeClass('lv-no-scroll');
        window.setTimeout(function () { $('.lv-drawer-backdrop').prop('hidden', true); }, 180);
        restoreFocus();
    }
    $(document).on('click', '.lv-quick-view', function () {
        var id = parseInt($(this).data('id'), 10);
        if (!id) return;
        $('.lv-drawer-content').html('<div class="lv-drawer-loading"><span class="spinner is-active"></span><p>Загрузка заявки…</p></div>');
        openDrawer(this);
        $.post(LVApps.ajaxUrl, { action: 'lv_apps_quick_view', nonce: LVApps.quickViewNonce, application_id: id, return_to: window.location.href })
            .done(function (response) {
                if (response && response.success && response.data.html) $('.lv-drawer-content').html(response.data.html);
                else $('.lv-drawer-content').html('<div class="lv-drawer-error">' + (response && response.data && response.data.message ? response.data.message : LVApps.error) + '</div>');
            })
            .fail(function () { $('.lv-drawer-content').html('<div class="lv-drawer-error">' + LVApps.error + '</div>'); });
    });
    $(document).on('click', '.lv-drawer-close, .lv-drawer-backdrop', closeDrawer);

    // Export modal.
    function openExport(event) {
        rememberFocus(event && event.currentTarget ? event.currentTarget : document.activeElement);
        updateSelectionUi();
        $('.lv-modal-backdrop').prop('hidden', false).addClass('is-open');
        $('.lv-export-modal').attr('aria-hidden', 'false').addClass('is-open');
        $('body').addClass('lv-no-scroll');
        window.setTimeout(function () { $('.lv-modal-close').trigger('focus'); }, 30);
    }
    function closeExport() {
        $('.lv-modal-backdrop').removeClass('is-open');
        $('.lv-export-modal').attr('aria-hidden', 'true').removeClass('is-open');
        $('body').removeClass('lv-no-scroll');
        window.setTimeout(function () { $('.lv-modal-backdrop').prop('hidden', true); }, 180);
        restoreFocus();
    }
    $(document).on('click', '.lv-open-export', openExport);
    $(document).on('click', '.lv-modal-close, .lv-modal-close-secondary', closeExport);
    $(document).on('click', '.lv-modal-backdrop', function(event){ if (event.target === this && this.id !== 'lv-contact-export-modal') closeExport(); });
    $(document).on('change', '.lv-export-form input[name="field_mode"]', function () {
        var custom = $('.lv-export-form input[name="field_mode"]:checked').val() === 'custom';
        $('.lv-custom-fields').prop('hidden', !custom).toggle(custom);
    });
    $(document).on('change', '.lv-export-form input[name="export_format"]', function () {
        var csv = $(this).val() === 'csv';
        $('.lv-export-form input[name="export_layout"]').prop('disabled', csv);
        if (csv) $('.lv-export-form input[name="export_layout"][value="single"]').prop('checked', true);
    });
    $(document).on('click', '.lv-check-all-fields', function () { $('.lv-custom-fields input[type="checkbox"]').prop('checked', true); });
    $(document).on('click', '.lv-uncheck-all-fields', function () { $('.lv-custom-fields input[type="checkbox"]').prop('checked', false); });
    $(document).on('submit', '.lv-export-form', function (event) {
        updateSelectionUi();
        if ($(this).find('input[name="export_scope"]:checked').val() === 'selected' && !selectedIds().length) {
            event.preventDefault();
            toast('Для экспорта выбранных заявок отметьте хотя бы одну строку в таблице.', 'error');
            return;
        }
        if ($(this).find('input[name="field_mode"]:checked').val() === 'custom' && !$(this).find('input[name="export_fields[]"]:checked').length) {
            event.preventDefault();
            toast('Выберите хотя бы одно поле для экспорта.', 'error');
        }
    });

    $(document).on('keydown', function (event) {
        if (event.key === 'Escape') {
            if ($('.lv-quick-drawer').hasClass('is-open')) closeDrawer();
            if ($('.lv-export-modal').hasClass('is-open')) closeExport();
            if ($('#lv-contact-export-modal').hasClass('is-open')) closeContactExport();
            return;
        }
        if (event.key === 'Tab') {
            var $dialog = $('.lv-export-modal.is-open');
            if (!$dialog.length) $dialog = $('.lv-contact-export-dialog.is-open');
            if (!$dialog.length) $dialog = $('.lv-quick-drawer.is-open');
            if (!$dialog.length) return;
            var $focusable = $dialog.find('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])').filter(':visible');
            if (!$focusable.length) { event.preventDefault(); $dialog.trigger('focus'); return; }
            var first = $focusable.get(0), last = $focusable.get($focusable.length - 1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });


    // Assignment.
    $(document).on('focus', '.lv-assignee-select', function () { $(this).data('previous', $(this).val()); });
    $(document).on('change', '.lv-assignee-select', function () {
        var $select = $(this);
        var id = parseInt($select.data('id'), 10);
        var assignee = parseInt($select.val(), 10) || 0;
        var previous = $select.data('previous');
        if (typeof previous === 'undefined') previous = $select.find('option:selected').prop('defaultSelected') ? $select.val() : null;
        $select.prop('disabled', true).closest('dd, td, .lv-qv-assignee').find('.lv-assignee-feedback').text('Сохраняем…');
        $.post(LVApps.ajaxUrl, {
            action: 'lv_apps_assign', nonce: LVApps.assignNonce,
            application_id: id, assignee_id: assignee
        }).done(function (response) {
            if (!response || !response.success) {
                toast(responseMessage(response), 'error');
                if (previous !== null) $select.val(previous);
                return;
            }
            $('.lv-assignee-select[data-id="' + id + '"]').val(String(assignee)).data('previous', String(assignee));
            $select.closest('dd, td, .lv-qv-assignee').find('.lv-assignee-feedback').text('Сохранено');
            window.setTimeout(function(){ $('.lv-assignee-feedback').text(''); }, 1300);
        }).fail(function(){ toast(LVApps.error, 'error'); }).always(function(){ $select.prop('disabled', false); });
    });
    $('.lv-assignee-select').each(function(){ $(this).data('previous', $(this).val()); });


    // Claim an unassigned application for the current author/contributor.
    $(document).on('click', '.lv-claim-button', function () {
        var $button = $(this);
        var id = parseInt($button.data('id'), 10);
        var userId = parseInt($button.data('user-id'), 10);
        if (!id || !userId) return;
        if (!window.confirm('Взять эту заявку себе в работу?')) return;

        var original = $button.html();
        $button.prop('disabled', true).addClass('is-loading').html('<span class="spinner is-active"></span>Назначаем…');
        $.post(LVApps.ajaxUrl, {
            action: 'lv_apps_assign',
            nonce: LVApps.assignNonce,
            application_id: id,
            assignee_id: userId
        }).done(function (response) {
            if (!response || !response.success) {
                toast(responseMessage(response), 'error');
                $button.prop('disabled', false).removeClass('is-loading').html(original);
                return;
            }
            updateUnreadCount(response.data.unprocessedCount);
            $button.closest('.lv-unassigned-inline, .lv-unassigned-box, .lv-qv-assignee').addClass('is-claimed');
            $button.html('<span class="dashicons dashicons-yes-alt"></span>Назначено');
            window.setTimeout(function () { window.location.reload(); }, 450);
        }).fail(function () {
            toast(LVApps.error, 'error');
            $button.prop('disabled', false).removeClass('is-loading').html(original);
        });
    });

    // Internal notes.
    $(document).on('click', '.lv-add-note', function () {
        var $button = $(this);
        var id = parseInt($button.data('id'), 10);
        var $compose = $button.closest('.lv-note-compose');
        var $input = $compose.find('.lv-note-input');
        var message = $.trim($input.val());
        if (!message) { $input.trigger('focus'); return; }
        $button.prop('disabled', true).text('Сохраняем…');
        $.post(LVApps.ajaxUrl, {
            action: 'lv_apps_add_note', nonce: LVApps.noteNonce,
            application_id: id, message: message
        }).done(function(response){
            if (!response || !response.success) {
                toast(responseMessage(response), 'error');
                return;
            }
            $input.val('');
            $compose.siblings('.lv-notes-container').html(response.data.html);
        }).fail(function(){ toast(LVApps.error, 'error'); }).always(function(){ $button.prop('disabled', false).text($button.closest('.lv-note-compose--compact').length ? 'Добавить' : 'Добавить заметку'); });
    });
    $(document).on('keydown', '.lv-note-input', function(event){
        var $menu = $(this).closest('.lv-note-compose').find('.lv-mention-menu');
        if ($menu.length && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
            event.preventDefault();
            var $items = $menu.find('button'), current = $items.index($items.filter('.is-active'));
            current = event.key === 'ArrowDown' ? Math.min(current + 1, $items.length - 1) : (current < 0 ? $items.length - 1 : Math.max(current - 1, 0));
            $items.removeClass('is-active').attr('aria-selected', 'false');
            $items.eq(current).addClass('is-active').attr('aria-selected', 'true').get(0).scrollIntoView({block:'nearest'});
            return;
        }
        if ($menu.length && event.key === 'Enter' && $menu.find('button.is-active').length) {
            event.preventDefault();
            $menu.find('button.is-active').trigger('click');
            return;
        }
        if ($menu.length && event.key === 'Escape') {
            event.preventDefault();
            closeMentionMenus();
            return;
        }
        if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
            event.preventDefault();
            $(this).closest('.lv-note-compose').find('.lv-add-note').trigger('click');
        }
    });

    // Contact comments use the same mention UI but a separate endpoint.
    $(document).on('click', '.lv-add-contact-note', function () {
        var $button = $(this), id = parseInt($button.data('contact'), 10), $compose = $button.closest('.lv-note-compose'), $input = $compose.find('.lv-note-input'), message = $.trim($input.val());
        if (!message) { $input.trigger('focus'); return; }
        $button.prop('disabled', true).text('Сохраняем…');
        $.post(LVApps.ajaxUrl, { action: 'lv_crm_add_contact_note', nonce: LVApps.contactNoteNonce, contact_id: id, message: message })
            .done(function (response) {
                if (!response || !response.success) { toast(responseMessage(response), 'error'); return; }
                $input.val(''); $('.lv-contact-notes-container').html(response.data.html); closeMentionMenus();
            }).fail(function(){ toast(LVApps.error, 'error'); }).always(function(){ $button.prop('disabled', false).text('Добавить'); });
    });
    $(document).on('keydown', '.lv-contact-note-input', function(event){
        if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') { event.preventDefault(); $(this).closest('.lv-note-compose').find('.lv-add-contact-note').trigger('click'); }
    });

    $(document).on('click', '.lv-request-delete', function(event){
        if (!window.confirm('Отправить запрос на удаление этой заявки редактору или администратору?')) event.preventDefault();
    });
    $(document).on('click', '.lv-empty-trash', function(event){
        if (!window.confirm('Очистить корзину полностью? Все записи и приложенные файлы будут удалены без возможности восстановления.')) event.preventDefault();
    });


    // CRM priority.
    $(document).on('focus', '.lv-priority-select', function () { $(this).data('previous', $(this).val()); });
    $(document).on('change', '.lv-priority-select', function () {
        var $select = $(this), id = parseInt($select.data('id'), 10), priority = $select.val();
        var previous = $select.data('previous') || 'normal';
        if (!id) return;
        $select.prop('disabled', true);
        $.post(LVApps.ajaxUrl, { action: 'lv_crm_priority', nonce: LVApps.priorityNonce, application_id: id, priority: priority })
            .done(function (response) {
                if (!response || !response.success) {
                    $select.val(previous);
                    toast(responseMessage(response), 'error');
                    return;
                }
                $('.lv-priority-select[data-id="' + id + '"]').val(priority).data('previous', priority);
                $('.lv-priority-select[data-id="' + id + '"]').siblings('.lv-priority-preview').html(response.data.html);
            })
            .fail(function () { $select.val(previous); toast(LVApps.error, 'error'); })
            .always(function () { $select.prop('disabled', false); });
    });
    $('.lv-priority-select').each(function(){ $(this).data('previous', $(this).val()); });

    // Application tags.
    $(document).on('change', '.lv-application-tags-select', function () {
        var $select = $(this), id = parseInt($select.data('id'), 10), values = $select.val() || [];
        $select.prop('disabled', true);
        $.post(LVApps.ajaxUrl, { action: 'lv_crm_application_tags', nonce: LVApps.tagsNonce, application_id: id, tag_ids: values })
            .done(function (response) {
                if (!response || !response.success) { toast(responseMessage(response), 'error'); return; }
                $select.closest('dd').find('.lv-application-tags-display').html(response.data.html);
            }).fail(function(){ toast(LVApps.error, 'error'); }).always(function(){ $select.prop('disabled', false); });
    });

    // @mention autocomplete in internal notes.
    function closeMentionMenus() { $('.lv-mention-menu').remove(); }
    $(document).on('input', '.lv-note-input', function () {
        var input = this, $input = $(this), value = $input.val(), caret = input.selectionStart || value.length;
        var before = value.slice(0, caret), match = before.match(/(?:^|\s)@([A-Za-z0-9._-]*)$/);
        closeMentionMenus();
        if (!match || !LVApps.mentionUsers || !LVApps.mentionUsers.length) return;
        var query = (match[1] || '').toLowerCase();
        var users = LVApps.mentionUsers.filter(function(u){ return !query || String(u.login).toLowerCase().indexOf(query) === 0 || String(u.name).toLowerCase().indexOf(query) !== -1; }).slice(0, 8);
        if (!users.length) return;
        var $menu = $('<div class="lv-mention-menu" role="listbox" aria-label="Выберите коллегу"></div>');
        users.forEach(function(u){
            $('<button type="button" role="option" aria-selected="false" tabindex="-1"></button>').attr('data-login', u.login).append($('<strong></strong>').text(u.name)).append($('<span></span>').text('@' + u.login + ' · ' + u.role)).appendTo($menu);
        });
        $input.closest('.lv-note-compose').css('position','relative').append($menu);
    });
    $(document).on('click', '.lv-mention-menu button', function () {
        var login = $(this).data('login'), $input = $(this).closest('.lv-note-compose').find('.lv-note-input'), input = $input.get(0);
        var value = $input.val(), caret = input.selectionStart || value.length, before = value.slice(0, caret), after = value.slice(caret);
        before = before.replace(/(?:^|\s)@([A-Za-z0-9._-]*)$/, function(m){ var lead = /^\s/.test(m) ? ' ' : ''; return lead + '@' + login + ' '; });
        $input.val(before + after).trigger('focus'); closeMentionMenus();
    });
    $(document).on('click', function(e){ if (!$(e.target).closest('.lv-note-compose').length) closeMentionMenus(); });

    // Contact search/linking inside an application.
    var contactSearchTimer = null;
    $(document).on('input', '.lv-contact-search', function () {
        var $input = $(this), q = $.trim($input.val()), appId = parseInt($input.data('application'), 10), $results = $input.siblings('.lv-contact-search-results');
        window.clearTimeout(contactSearchTimer);
        if (q.length < 2) { $results.prop('hidden', true).empty(); $input.attr('aria-expanded', 'false'); return; }
        contactSearchTimer = window.setTimeout(function(){
            $results.attr({role:'listbox','aria-label':'Результаты поиска контактов'}).prop('hidden', false).html('<div class="lv-contact-search-loading">Ищем…</div>');
            $input.attr({'aria-expanded':'true','aria-autocomplete':'list'});
            $.post(LVApps.ajaxUrl, { action:'lv_crm_contact_search', nonce:LVApps.contactSearchNonce, q:q })
                .done(function(resp){
                    if (!resp || !resp.success || !resp.data.items.length) { $results.html('<div class="lv-contact-search-empty">Ничего не найдено</div>'); return; }
                    $results.empty();
                    resp.data.items.forEach(function(item){
                        var cls = item.type === 'account' ? 'lv-contact-search-item lv-link-account-suggestion is-account' : 'lv-contact-search-item';
                        var attrs = {'data-application':appId};
                        if (item.type === 'account') attrs['data-user'] = item.id; else attrs['data-contact'] = item.id;
                        var $b=$('<button type="button" role="option" aria-selected="false" tabindex="-1"></button>').addClass(cls).attr(attrs);
                        var icon = item.type === 'account' ? 'dashicons-wordpress-alt' : 'dashicons-id';
                        $b.append($('<span></span>').addClass('dashicons '+icon))
                          .append($('<span class="lv-contact-search-copy"></span>').append($('<strong></strong>').text(item.name)).append($('<small></small>').text(item.meta || '')))
                          .append($('<em></em>').text(item.badge || (item.type === 'account' ? 'Аккаунт WordPress' : 'Контакт CRM')))
                          .appendTo($results);
                    });
                }).fail(function(){ $results.html('<div class="lv-contact-search-empty">Ошибка поиска</div>'); });
        }, 220);
    });
    $(document).on('keydown', '.lv-contact-search', function(event){
        var $input=$(this), $results=$input.siblings('.lv-contact-search-results'), $items=$results.find('.lv-contact-search-item');
        if (!$items.length) return;
        var current=$items.index($items.filter('.is-active'));
        if (event.key==='ArrowDown' || event.key==='ArrowUp') {
            event.preventDefault();
            current=event.key==='ArrowDown'?Math.min(current+1,$items.length-1):(current<0?$items.length-1:Math.max(current-1,0));
            $items.removeClass('is-active').attr('aria-selected','false');
            $items.eq(current).addClass('is-active').attr('aria-selected','true').get(0).scrollIntoView({block:'nearest'});
        } else if (event.key==='Enter' && $items.filter('.is-active').length) {
            event.preventDefault();$items.filter('.is-active').trigger('click');
        } else if (event.key==='Escape') {
            event.preventDefault();$results.prop('hidden',true).empty();$input.attr('aria-expanded','false');
        }
    });
    $(document).on('click', function(event){
        if (!$(event.target).closest('.lv-contact-search-wrap').length) {
            $('.lv-contact-search-results').prop('hidden',true);
            $('.lv-contact-search').attr('aria-expanded','false');
        }
    });
    $(document).on('click', '.lv-contact-search-item:not(.is-account), .lv-contact-suggestion.lv-contact-search-item', function(){
        var $b=$(this), aid=parseInt($b.data('application'),10), cid=parseInt($b.data('contact'),10), $panel=$b.closest('.lv-contact-link-panel');
        $b.prop('disabled',true);
        $.post(LVApps.ajaxUrl,{action:'lv_crm_link_contact',nonce:LVApps.contactLinkNonce,application_id:aid,contact_id:cid})
            .done(function(resp){ if(resp&&resp.success){$panel.replaceWith(resp.data.html);toast('Контакт связан с заявкой.', 'success');}else toast(responseMessage(resp), 'error'); })
            .fail(function(){toast(LVApps.error, 'error');});
    });
    $(document).on('click', '.lv-link-account-suggestion', function(){
        var $b=$(this), aid=parseInt($b.data('application'),10), uid=parseInt($b.data('user'),10), $panel=$b.closest('.lv-contact-link-panel');
        if (!aid || !uid) return;
        $b.prop('disabled',true).addClass('is-loading');
        $.post(LVApps.ajaxUrl,{action:'lv_crm_link_account',nonce:LVApps.contactAccountNonce,application_id:aid,user_id:uid})
            .done(function(resp){ if(resp&&resp.success){$panel.replaceWith(resp.data.html);toast('Аккаунт связан с заявкой.', 'success');}else toast(responseMessage(resp), 'error'); })
            .fail(function(){toast(LVApps.error, 'error');})
            .always(function(){ $b.prop('disabled',false).removeClass('is-loading'); });
    });

    $(document).on('click','.lv-unlink-contact',function(){
        if(!window.confirm('Отвязать контакт от этой заявки? Сам контакт не будет удалён.'))return;
        var $b=$(this), aid=parseInt($b.data('application'),10), cid=parseInt($b.data('contact'),10), $panel=$b.closest('.lv-contact-link-panel');
        $.post(LVApps.ajaxUrl,{action:'lv_crm_unlink_contact',nonce:LVApps.contactLinkNonce,application_id:aid,contact_id:cid})
            .done(function(resp){ if(resp&&resp.success){$panel.replaceWith(resp.data.html);toast('Связь с контактом удалена.', 'success');}else toast(responseMessage(resp), 'error'); })
            .fail(function(){toast(LVApps.error, 'error');});
    });

    // Workload transfer preview.
    $(document).on('change', '.lv-transfer-form input, .lv-transfer-form select', function(){ $('.lv-transfer-submit').prop('disabled', true); });
    $(document).on('click', '.lv-transfer-preview-button', function(){
        var $form=$(this).closest('form'), source=parseInt($form.find('[name="source_user"]').val(),10)||0, recipients=[];
        $form.find('[name="recipients[]"]:checked').each(function(){recipients.push($(this).val());});
        if(!source||!recipients.length){toast('Выберите сотрудника-источник и хотя бы одного получателя.', 'error');return;}
        var $preview=$form.find('.lv-transfer-preview'); $preview.addClass('is-loading').html('<span class="spinner is-active"></span><div><strong>Считаем распределение…</strong></div>');
        $.post(LVApps.ajaxUrl,{action:'lv_crm_transfer_preview',nonce:LVApps.transferNonce,source_user:source,recipients:recipients})
            .done(function(resp){
                if(!resp||!resp.success){$preview.html('<div><strong>Не удалось рассчитать</strong></div>');return;}
                var html='<span class="dashicons dashicons-yes-alt"></span><div><strong>Будет передано: '+resp.data.total+'</strong><div class="lv-transfer-plan">';
                resp.data.rows.forEach(function(r){html+='<span><b>'+r.name+'</b><em>+'+r.count+'</em></span>';});html+='</div></div>';
                $preview.removeClass('is-loading').html(html); $form.find('.lv-transfer-submit').prop('disabled', resp.data.total < 1);
            }).fail(function(){$preview.html('<div><strong>Ошибка расчёта</strong></div>');});
    });
    $(document).on('submit','.lv-transfer-form',function(e){ if(!window.confirm('Выполнить рассчитанное перераспределение необработанных заявок?'))e.preventDefault(); });

    // Contact editor repeaters and custom fields.
    $(document).on('click','.lv-repeat-add',function(){
        var target=$(this).data('target'), type=target==='email'?'email':'text', placeholder=target==='email'?'name@example.ru':'+7 …';
        var removeLabel = target === 'email' ? 'Удалить email' : 'Удалить телефон';
        $(this).siblings('.lv-repeater').append('<div class="lv-repeat-row"><input type="'+type+'" name="'+target+'s[]" placeholder="'+placeholder+'"><button type="button" class="button-link lv-repeat-remove" aria-label="'+removeLabel+'">×</button></div>');
    });
    $(document).on('click','.lv-repeat-remove',function(){ var $rows=$(this).closest('.lv-repeater').find('.lv-repeat-row'); if($rows.length>1)$(this).closest('.lv-repeat-row').remove(); else $(this).siblings('input').val(''); });
    $(document).on('click','.lv-custom-field-add',function(){
        var options='<option value="text">Строка</option><option value="textarea">Многострочный текст</option><option value="number">Число</option><option value="date">Дата</option><option value="url">Ссылка</option><option value="email">Email</option><option value="phone">Телефон</option><option value="checkbox">Да / нет</option>';
        $('.lv-custom-field-list').append('<div class="lv-custom-field-row"><input type="hidden" name="custom_key[]" value=""><input name="custom_label[]" placeholder="Название поля"><select name="custom_type[]">'+options+'</select><input name="custom_value[]" placeholder="Значение"><button type="button" class="button-link lv-custom-field-remove" aria-label="Удалить дополнительное поле">×</button></div>');
    });
    $(document).on('click','.lv-custom-field-remove',function(){ if($('.lv-custom-field-row').length>1)$(this).closest('.lv-custom-field-row').remove(); else $(this).closest('.lv-custom-field-row').find('input').val(''); });


    // Contact selection, bulk management and export.
    function selectedContactIds() {
        var ids=[];
        $('.lv-contact-select:checked').each(function(){ ids.push(String($(this).val())); });
        return ids;
    }
    function contactSelectionScope(){
        return $('.lv-contact-bulk-form input[name="selection_scope"]').val()==='filtered'?'filtered':'page';
    }
    function resetFilteredContactScope(){
        $('.lv-contact-bulk-form input[name="selection_scope"]').val('page');
    }
    function selectedContactCount(){
        if(contactSelectionScope()==='filtered') return parseInt($('.lv-contact-selection-bar').data('total'),10)||0;
        return selectedContactIds().length;
    }
    function updateContactSelectionUi(){
        var ids=selectedContactIds(), count=ids.length, filtered=contactSelectionScope()==='filtered';
        var totalPage=$('.lv-contact-select').length;
        var totalFiltered=parseInt($('.lv-contact-selection-bar').data('total'),10)||0;
        $('.lv-contact-row').each(function(){ $(this).toggleClass('is-selected',filtered||$(this).find('.lv-contact-select').is(':checked')); });
        var $bar=$('.lv-contact-selection-bar');
        $bar.prop('hidden',!(filtered||count>0));
        $bar.find('.lv-selected-count').text(filtered?totalFiltered:count);
        $bar.find('.lv-select-all-filtered-contacts').toggle(!filtered&&totalPage>0&&count===totalPage&&totalFiltered>count);
        $('.lv-contact-select-all').prop('checked',filtered||(totalPage>0&&count===totalPage)).prop('indeterminate',!filtered&&count>0&&count<totalPage);
        $('.lv-contact-bulk-form input[name="selected_ids"]').val(ids.join(','));
        $('.lv-contact-export-form input[name="selected_ids"]').val(ids.join(','));
        $('.lv-export-selected-choice').toggleClass('is-disabled',count<1||filtered).find('input').prop('disabled',count<1||filtered);
    }
    $(document).on('change','.lv-contact-select',function(){ resetFilteredContactScope();updateContactSelectionUi(); });
    $(document).on('change','.lv-contact-select-all',function(){ resetFilteredContactScope();$('.lv-contact-select').prop('checked',$(this).is(':checked'));updateContactSelectionUi(); });
    $(document).on('click','.lv-select-all-filtered-contacts',function(){ $('.lv-contact-bulk-form input[name="selection_scope"]').val('filtered');$('.lv-contact-select').prop('checked',true);updateContactSelectionUi(); });
    $(document).on('click','.lv-clear-contact-selection',function(){ resetFilteredContactScope();$('.lv-contact-select,.lv-contact-select-all').prop('checked',false);updateContactSelectionUi(); });
    $(document).on('submit','.lv-contact-bulk-form',function(e){
        var ids=selectedContactIds(), action=$(this).find('select[name="bulk_action"]').val(), selected=selectedContactCount();
        if(!action){e.preventDefault();return false;}
        if(!selected){e.preventDefault();toast('Сначала выберите хотя бы один контакт.','error');return false;}
        $(this).find('input[name="selected_ids"]').val(ids.join(','));
        if(action==='purge'&&!window.confirm('Удалить выбранные контакты навсегда ('+selected+')? Будут удалены контактные данные, заметки и история согласий.')){e.preventDefault();return false;}
    });
    $(document).on('change','.lv-contact-per-page',function(){
        var base=String($(this).data('base-url')||window.location.href);
        try{var url=new URL(base,window.location.origin);url.searchParams.set('per_page',String($(this).val()));url.searchParams.delete('paged');window.location.href=url.toString();}
        catch(e){window.location.href=base+(base.indexOf('?')>=0?'&':'?')+'per_page='+encodeURIComponent($(this).val());}
    });

    function updateContactExportConsentUi($form){
        var $profile=$form.find('input[name="profile"]:checked'), mailing=String($profile.data('mailing'))==='1';
        var $row=$form.find('.lv-consent-override-row');
        if(!$row.length) return;
        $row.prop('hidden',!mailing).toggle(mailing);
        if(!mailing) $row.find('input[name="ignore_consent"]').prop('checked',false);
    }
    function openContactExport(trigger){
        rememberFocus(trigger);
        updateContactSelectionUi();
        var $backdrop=$('#lv-contact-export-modal'), $form=$backdrop.find('.lv-contact-export-form');
        if($(trigger).data('export-selected') && contactSelectionScope()!=='filtered' && selectedContactIds().length) $form.find('input[name="export_mode"][value="selected"]').prop('checked',true);
        else $form.find('input[name="export_mode"][value="current"]').prop('checked',true);
        updateContactExportConsentUi($form);
        $backdrop.prop('hidden',false).addClass('is-open');$backdrop.find('.lv-contact-export-dialog').addClass('is-open').attr('aria-hidden','false');$('body').addClass('lv-no-scroll');
        window.setTimeout(function(){ $backdrop.find('.lv-contact-modal-close').trigger('focus');$backdrop.find('.lv-export-refresh').trigger('click'); },30);
    }
    function closeContactExport(){
        var $backdrop=$('#lv-contact-export-modal');$backdrop.removeClass('is-open');$backdrop.find('.lv-contact-export-dialog').removeClass('is-open').attr('aria-hidden','true');$('body').removeClass('lv-no-scroll');
        window.setTimeout(function(){ $backdrop.prop('hidden',true); },180);restoreFocus();
    }
    $(document).on('click','.lv-open-contact-export',function(){openContactExport(this);});
    $(document).on('click','#lv-contact-export-modal [data-lv-close-modal]',function(){closeContactExport();});
    $(document).on('click','#lv-contact-export-modal',function(event){if(event.target===this)closeContactExport();});
    $(document).on('change','.lv-contact-export-form input[name="export_mode"]',function(){var $form=$(this).closest('form');updateContactSelectionUi();$form.find('.lv-export-refresh').trigger('click');});
    $(document).on('click','.lv-export-refresh',function(){
        var $form=$(this).closest('form'), mode=$form.find('input[name="export_mode"]:checked').val(), selected=selectedContactIds(), $preview=$form.find('.lv-export-preview');
        if(mode==='selected'&&!selected.length){toast('Сначала отметьте хотя бы один контакт.', 'error');return;}
        $preview.addClass('is-loading').html('<div class="lv-export-calculating"><span class="spinner is-active"></span><strong>Проверяем выборку…</strong></div>');
        $.post(LVApps.ajaxUrl,{action:'lv_crm_export_preview',nonce:LVApps.contactExportNonce,filters:$form.find('input[name="filters"]').val(),mode:mode,selected:selected,ignore_consent:$form.find('input[name="ignore_consent"]').is(':checked')?1:0})
            .done(function(resp){if(resp&&resp.success){$preview.removeClass('is-loading').html(resp.data.html);$form.data('exportStats',resp.data.stats);updateContactExportButton($form);}else{$preview.removeClass('is-loading').html('<div class="lv-empty-mini">'+responseMessage(resp)+'</div>');}})
            .fail(function(){$preview.removeClass('is-loading').html('<div class="lv-empty-mini">'+LVApps.error+'</div>');});
    });
    function updateContactExportButton($form){
        var stats=$form.data('exportStats'), $profile=$form.find('input[name="profile"]:checked'), mailing=String($profile.data('mailing'))==='1';
        var label='Скачать';if(stats){var n=mailing?(parseInt(stats.active_mailing_rows,10)||0):(parseInt(stats.contacts,10)||0);label=mailing?('Скачать '+n+' email'):('Скачать '+n+' контактов');}
        $form.find('.lv-export-submit-label').text(label);
    }
    $(document).on('change','.lv-contact-export-form input[name="profile"]',function(){var $form=$(this).closest('form');updateContactExportConsentUi($form);updateContactExportButton($form);$form.find('.lv-export-refresh').trigger('click');});
    $(document).on('change','.lv-contact-export-form input[name="ignore_consent"]',function(){var $form=$(this).closest('form');$form.find('.lv-export-refresh').trigger('click');});
    $(document).on('submit','.lv-contact-export-form',function(event){
        var mode=$(this).find('input[name="export_mode"]:checked').val(), ids=selectedContactIds();$(this).find('input[name="selected_ids"]').val(ids.join(','));
        if(mode==='selected'&&!ids.length){event.preventDefault();toast('Для экспорта выбранных контактов отметьте хотя бы одну строку.', 'error');}
    });


    // Keep the current CRM section visible when the tab row overflows.
    $('.lv-workspace-tabs').each(function () {
        var tabs = this;
        var active = tabs.querySelector('.is-active');
        if (!active) return;
        active.setAttribute('aria-current', 'page');
        if (tabs.scrollWidth <= tabs.clientWidth) return;
        window.requestAnimationFrame(function () {
            tabs.scrollLeft = Math.max(0, active.offsetLeft - ((tabs.clientWidth - active.offsetWidth) / 2));
        });
    });

    updateSelectionUi();

    // Unified confirmations only for irreversible/destructive operations.
    $(document).on('click','[data-lv-confirm]',function(e){
        var message=String($(this).data('lv-confirm')||'Подтвердить действие?');
        if(!window.confirm(message)){e.preventDefault();e.stopImmediatePropagation();return false;}
    });
    $(document).on('click','.lv-tag-delete-form [data-confirm]',function(e){
        var message=String($(this).data('confirm')||'Удалить метку?');
        if(!window.confirm(message)){e.preventDefault();return false;}
    });

    // Protect long contact forms from accidental navigation and double submit.
    var dirtyContactForm=false, contactFormSubmitting=false;
    $(document).on('input change','.lv-dirty-guard-form :input',function(){ if(!contactFormSubmitting)dirtyContactForm=true; });
    $(document).on('submit','.lv-dirty-guard-form',function(){
        contactFormSubmitting=true;dirtyContactForm=false;
        var $button=$(this).find('.lv-save-contact-button');
        if($button.length){var text=String($button.data('saving-text')||'Сохранение…');$button.prop('disabled',true).data('original-text',$button.text()).text(text);}
    });
    $(window).on('beforeunload',function(){ if(dirtyContactForm&&!contactFormSubmitting)return 'Есть несохранённые изменения.'; });

    updateContactSelectionUi();
})(jQuery);
