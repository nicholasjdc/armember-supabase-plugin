jQuery(document).ready(function($) {

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

});
