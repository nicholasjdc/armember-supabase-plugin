jQuery(document).ready(function($) {
    
    // Debug: Check if supabaseAdmin is available
    if (typeof supabaseAdmin === 'undefined') {
        console.error('supabaseAdmin is not defined. Check if the script is properly localized.');
    } else {
        console.log('supabaseAdmin loaded:', supabaseAdmin);
    }

    // Sync users button
    $('#sync-users-btn').on('click', function() {
        var $btn = $(this);
        var $status = $('#sync-users-status');

        if (!confirm('This will sync all WordPress users to Supabase. Continue?')) {
            return;
        }

        $btn.prop('disabled', true).text('Syncing...');
        $status.hide().removeClass('notice-success notice-error');

        $.ajax({
            url: supabaseAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'supabase_sync_users',
                nonce: supabaseAdmin.syncUsersNonce
            },
            success: function(response) {
                if (response.success) {
                    $status
                        .addClass('notice notice-success')
                        .html('<p>' + response.data.message + '</p>')
                        .show();
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    $status
                        .addClass('notice notice-error')
                        .html('<p>Error: ' + response.data.message + '</p>')
                        .show();
                    $btn.prop('disabled', false).text('Sync All Users to Supabase');
                }
            },
            error: function() {
                $status
                    .addClass('notice notice-error')
                    .html('<p>An error occurred. Please try again.</p>')
                    .show();
                $btn.prop('disabled', false).text('Sync All Users to Supabase');
            }
        });
    });

    // Sync tables button
    $('#sync-tables-btn').on('click', function() {
        var $btn = $(this);
        var $status = $('#sync-status');

        $btn.prop('disabled', true).text('Syncing...');
        $status.hide().removeClass('notice-success notice-error');

        $.ajax({
            url: supabaseAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'supabase_sync_tables',
                nonce: supabaseAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $status
                        .addClass('notice-success')
                        .html('<p>' + response.data.message + '</p>')
                        .show();

                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $status
                        .addClass('notice-error')
                        .html('<p>Error: ' + response.data.message + '</p>')
                        .show();
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Sync Tables from Supabase');
                }
            },
            error: function() {
                $status
                    .addClass('notice-error')
                    .html('<p>An error occurred. Please try again.</p>')
                    .show();
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Sync Tables from Supabase');
            }
        });
    });

    // Create page button
    $(document).on('click', '.create-page-btn', function() {
        var $btn = $(this);
        var tableName = $btn.data('table');

        if (!confirm('Create a new page for the "' + tableName + '" table?')) {
            return;
        }

        $btn.prop('disabled', true).text('Creating...');

        $.ajax({
            url: supabaseAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'supabase_create_table_page',
                nonce: supabaseAdmin.nonce,
                table: tableName
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message + '\n\nThe page has been created as a draft. You can now edit and publish it.');
                    location.reload();
                } else {
                    alert('Error: ' + response.data.message);
                    $btn.prop('disabled', false).text('Create Page');
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
                $btn.prop('disabled', false).text('Create Page');
            }
        });
    });

    // Delete page button
    $(document).on('click', '.delete-page-btn', function() {
        var $btn = $(this);
        var tableName = $btn.data('table');
        var pageId = $btn.data('page-id');

        if (!confirm('Are you sure you want to delete the page for "' + tableName + '"?\n\nThis will move the page to trash. You can restore it from the WordPress trash if needed.')) {
            return;
        }

        $btn.prop('disabled', true).text('Deleting...');

        $.ajax({
            url: supabaseAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'supabase_delete_table_page',
                nonce: supabaseAdmin.nonce,
                table: tableName,
                page_id: pageId
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message + '\n\nThe page has been moved to trash.');
                    location.reload();
                } else {
                    alert('Error: ' + response.data.message);
                    $btn.prop('disabled', false).text('Delete Page');
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
                $btn.prop('disabled', false).text('Delete Page');
            }
        });
    });

    // View columns button
    $(document).on('click', '.view-columns-btn', function() {
        var tableName = $(this).data('table');
        var $columnsRow = $('#columns-' + tableName);

        $columnsRow.toggle();

        var btnText = $columnsRow.is(':visible') ? 'Hide Columns' : 'View Columns';
        $(this).text(btnText);
    });

    // Table lock checkbox handler
    $(document).on('change', '.table-lock-checkbox', function() {
        var $checkbox = $(this);
        var tableName = $checkbox.data('table');
        var isLocked = $checkbox.is(':checked');

        $checkbox.prop('disabled', true);

        $.ajax({
            url: supabaseAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'supabase_toggle_table_lock',
                nonce: supabaseAdmin.nonce,
                table: tableName,
                is_locked: isLocked ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
                    var msg = isLocked ? 'Table locked' : 'Table unlocked';
                    var $notice = $('<div class="notice notice-success is-dismissible"><p>' + msg + '</p></div>');
                    $('.wrap h1').first().after($notice);
                    setTimeout(function() { $notice.fadeOut(); }, 3000);
                } else {
                    $checkbox.prop('checked', !isLocked);
                    alert('Error: ' + response.data.message);
                }
            },
            error: function() {
                $checkbox.prop('checked', !isLocked);
                alert('An error occurred while updating lock status.');
            },
            complete: function() {
                $checkbox.prop('disabled', false);
            }
        });
    });

    // Set library table button handler
    $(document).on('click', '.set-library-btn', function() {
        var $btn = $(this);
        var tableName = $btn.data('table');
        var actionType = $btn.data('action');
        var confirmMsg = actionType === 'set'
            ? 'Set "' + tableName + '" as the library table?\n\nThis will remove the library designation from any other table.'
            : 'Remove library designation from "' + tableName + '"?';

        if (!confirm(confirmMsg)) {
            return;
        }

        $btn.prop('disabled', true).text(actionType === 'set' ? 'Setting...' : 'Removing...');

        $.ajax({
            url: supabaseAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'supabase_set_library_table',
                nonce: supabaseAdmin.nonce,
                table: tableName,
                action_type: actionType
            },
            success: function(response) {
                if (response.success) {
                    var msg = actionType === 'set' ? 'Library table set successfully' : 'Library designation removed';
                    var $notice = $('<div class="notice notice-success is-dismissible"><p>' + msg + '</p></div>');
                    $('.wrap h1').first().after($notice);
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    alert('Error: ' + response.data.message);
                    $btn.prop('disabled', false).text(actionType === 'set' ? 'Set as Library' : 'Remove');
                }
            },
            error: function() {
                alert('An error occurred while updating library designation.');
                $btn.prop('disabled', false).text(actionType === 'set' ? 'Set as Library' : 'Remove');
            }
        });
    });

    // PDF column selector handler - use event delegation to ensure it works
    $(document).on('change', '.pdf-column-select', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var $select = $(this);
        var tableName = $select.data('table');
        var pdfColumn = $select.val();
        var previousValue = $select.data('previous-value') || $select.find('option[selected]').val() || '';

        console.log('PDF Column Change Event Fired:', {
            table: tableName, 
            column: pdfColumn, 
            previous: previousValue,
            selectElement: $select[0],
            hasDataTable: $select.data('table') !== undefined
        });

        // Store current value in case we need to revert
        $select.data('previous-value', pdfColumn);

        if (!tableName) {
            console.error('Table name not found in data-table attribute. Select element:', $select[0]);
            alert('Error: Table name not found. Please refresh the page and try again.');
            if (previousValue) {
                $select.val(previousValue);
            }
            return;
        }

        if (typeof supabaseAdmin === 'undefined' || !supabaseAdmin.ajaxUrl || !supabaseAdmin.nonce) {
            console.error('supabaseAdmin not properly initialized:', supabaseAdmin);
            alert('Error: Admin configuration not loaded. Please refresh the page and try again.');
            if (previousValue) {
                $select.val(previousValue);
            }
            return;
        }

        $select.prop('disabled', true);

        $.ajax({
            url: supabaseAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'supabase_set_pdf_column',
                nonce: supabaseAdmin.nonce,
                table: tableName,
                pdf_column: pdfColumn
            },
            success: function(response) {
                console.log('AJAX Success Response:', response);
                if (response && response.success) {
                    var msg = pdfColumn 
                        ? 'PDF column set to "' + pdfColumn + '"' 
                        : 'PDF column designation removed';
                    var $notice = $('<div class="notice notice-success is-dismissible"><p>' + msg + '</p></div>');
                    // Try multiple selectors to find the right place to insert the notice
                    var $target = $('.wrap h1').first();
                    if ($target.length === 0) {
                        $target = $('.wrap').first();
                    }
                    if ($target.length === 0) {
                        $target = $('h1').first();
                    }
                    $target.after($notice);
                    // Auto-dismiss after 3 seconds
                    setTimeout(function() { $notice.fadeOut(function() { $(this).remove(); }); }, 3000);
                } else {
                    var errorMsg = (response && response.data && response.data.message) 
                        ? response.data.message 
                        : 'Unknown error occurred';
                    console.error('AJAX Error Response:', response);
                    alert('Error: ' + errorMsg);
                    // Revert to previous value
                    $select.val(previousValue);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', {xhr: xhr, status: status, error: error});
                var errorMsg = 'An error occurred while updating PDF column designation.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMsg = xhr.responseJSON.data.message;
                }
                alert(errorMsg);
                // Revert to previous value
                $select.val(previousValue);
            },
            complete: function() {
                $select.prop('disabled', false);
            }
        });
    });

    // Debug: Verify PDF column selects exist and handler is attached
    setTimeout(function() {
        var $pdfSelects = $('.pdf-column-select');
        console.log('PDF Column Selects Found:', $pdfSelects.length);
        if ($pdfSelects.length > 0) {
            $pdfSelects.each(function(index) {
                var $select = $(this);
                console.log('PDF Select ' + index + ':', {
                    table: $select.data('table'),
                    value: $select.val(),
                    element: $select[0]
                });
            });
        } else {
            console.warn('No PDF column selects found on page');
        }
    }, 1000);

    // Table searchability checkbox handler (General Search settings)
    $(document).on('change', '.table-searchable-checkbox', function() {
        var $checkbox = $(this);
        var tableName = $checkbox.data('table');
        var isSearchable = $checkbox.is(':checked');

        $checkbox.prop('disabled', true);

        $.ajax({
            url: supabaseAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'supabase_toggle_table_searchable',
                nonce: supabaseAdmin.nonce,
                table: tableName,
                is_searchable: isSearchable ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
                    var msg = isSearchable 
                        ? '"' + tableName + '" added to General Search' 
                        : '"' + tableName + '" removed from General Search';
                    var $notice = $('<div class="notice notice-success is-dismissible"><p>' + msg + '</p></div>');
                    $('.wrap h1').first().after($notice);
                    setTimeout(function() { $notice.fadeOut(function() { $(this).remove(); }); }, 3000);
                } else {
                    $checkbox.prop('checked', !isSearchable);
                    alert('Error: ' + response.data.message);
                }
            },
            error: function() {
                $checkbox.prop('checked', !isSearchable);
                alert('An error occurred while updating searchability.');
            },
            complete: function() {
                $checkbox.prop('disabled', false);
            }
        });
    });

    function showAdminNotice(message, type) {
        var noticeType = type || 'success';
        var $notice = $('<div class="notice is-dismissible"></div>');
        $notice.addClass('notice-' + noticeType);
        $notice.append($('<p></p>').text(message));
        $('.wrap h1').first().after($notice);
        setTimeout(function() {
            $notice.fadeOut(function() { $(this).remove(); });
        }, 3000);
    }

    function getSearchFieldsListForTable(tableName) {
        return $('.table-search-fields-list').filter(function() {
            return $(this).data('table') === tableName;
        }).first();
    }

    function updateSearchFieldCount($list) {
        var total = $list.find('.table-search-field-checkbox').length;
        var selected = $list.find('.table-search-field-checkbox:checked').length;
        $list.find('.search-fields-selection-count').text(selected + ' of ' + total + ' fields selected');
    }

    function applySelectionToList($list, selectedFields) {
        $list.find('.table-search-field-checkbox').each(function() {
            var shouldBeChecked = selectedFields.indexOf($(this).val()) !== -1;
            $(this).prop('checked', shouldBeChecked);
        });
        updateSearchFieldCount($list);
    }

    function saveSearchFields(tableName, $list) {
        var selectedFields = $list.find('.table-search-field-checkbox:checked').map(function() {
            return $(this).val();
        }).get();

        var previouslySaved = $list.data('saved-fields') || [];

        if (selectedFields.length === 0) {
            alert('At least one field must remain selected for search.');
            applySelectionToList($list, previouslySaved);
            return;
        }

        $list.find('.table-search-field-checkbox').prop('disabled', true);

        $.ajax({
            url: supabaseAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'supabase_set_table_search_fields',
                nonce: supabaseAdmin.nonce,
                table: tableName,
                search_fields: selectedFields
            },
            success: function(response) {
                if (response && response.success) {
                    var savedFields = (response.data && response.data.search_fields) ? response.data.search_fields : selectedFields;
                    $list.data('saved-fields', savedFields);
                    applySelectionToList($list, savedFields);
                    showAdminNotice('Search fields updated for "' + tableName + '".', 'success');
                } else {
                    var errorMsg = (response && response.data && response.data.message) ? response.data.message : 'Failed to save search fields.';
                    alert('Error: ' + errorMsg);
                    applySelectionToList($list, previouslySaved);
                }
            },
            error: function() {
                alert('An error occurred while saving search fields.');
                applySelectionToList($list, previouslySaved);
            },
            complete: function() {
                $list.find('.table-search-field-checkbox').prop('disabled', false);
            }
        });
    }

    // Search field visibility settings (General Search)
    if ($('#search-fields-table-select').length > 0) {
        $('.table-search-fields-list').each(function() {
            var $list = $(this);
            var initialSelection = $list.find('.table-search-field-checkbox:checked').map(function() {
                return $(this).val();
            }).get();
            $list.data('saved-fields', initialSelection);
            updateSearchFieldCount($list);
        });

        function showSelectedTableFieldList() {
            var selectedTable = $('#search-fields-table-select').val();
            $('.table-search-fields-list').hide();
            if (selectedTable) {
                getSearchFieldsListForTable(selectedTable).show();
            }
        }

        $('#search-fields-table-select').on('change', showSelectedTableFieldList);
        showSelectedTableFieldList();

        $(document).on('change', '.table-search-field-checkbox', function() {
            var tableName = $(this).data('table');
            var $list = getSearchFieldsListForTable(tableName);
            updateSearchFieldCount($list);
            saveSearchFields(tableName, $list);
        });
    }

});
