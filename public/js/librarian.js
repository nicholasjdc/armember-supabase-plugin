/**
 * Librarian CRUD Interface JavaScript
 * Handles DataTable, modal forms, and CRUD operations
 * Updated for SGS Library Records schema
 */

jQuery(document).ready(function($) {
    'use strict';

    var dataTable = null;
    var currentRecordTitle = null;
    var originalTitle = null; // Store original title for updates

    // Initialize DataTable
    function initDataTable() {
        dataTable = $('#librarian-records-table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: supabaseLibrarian.restUrl + 'librarian-data',
                type: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', supabaseLibrarian.nonce);
                },
                error: function(xhr, error, thrown) {
                    console.error('DataTables AJAX Error:', error, thrown);
                    showMessage('Error loading records. Please refresh the page.', 'error');
                }
            },
            columns: [
                {
                    data: 'Title',
                    render: function(data, type, row) {
                        return '<strong>' + escapeHtml(data || '') + '</strong>';
                    }
                },
                {
                    data: 'Author',
                    render: function(data) {
                        return escapeHtml(data || '—');
                    }
                },
                {
                    data: 'Publisher',
                    render: function(data) {
                        return escapeHtml(data || '—');
                    }
                },
                {
                    data: 'Call Number',
                    render: function(data) {
                        return escapeHtml(data || '—');
                    }
                },
                {
                    data: 'New',
                    render: function(data) {
                        if (data === 'Yes' || data === 'yes' || data === true) {
                            return '<span class="badge badge-new">New</span>';
                        }
                        return '—';
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    render: function(data, type, row) {
                        var title = escapeHtml(row.Title || '');
                        return '<div class="action-buttons">' +
                            '<button type="button" class="button button-small edit-record-btn" data-title="' + title + '">Edit</button> ' +
                            '<button type="button" class="button button-small button-danger delete-record-btn" data-title="' + title + '">Delete</button>' +
                            '</div>';
                    }
                }
            ],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            order: [[0, 'asc']],
            language: {
                processing: '<span class="dashicons dashicons-update spin"></span> Loading records...',
                search: 'Search:',
                lengthMenu: 'Show _MENU_ records per page',
                info: 'Showing _START_ to _END_ of _TOTAL_ records',
                infoEmpty: 'No records available',
                infoFiltered: '(filtered from _MAX_ total records)',
                zeroRecords: 'No matching records found',
                emptyTable: 'No records in the library. Click "Add New Record" to get started.',
                paginate: {
                    first: 'First',
                    last: 'Last',
                    next: 'Next',
                    previous: 'Previous'
                }
            },
            rowCallback: function(row, data) {
                $(row).data('fullRecord', data.full_data);
            }
        });
    }

    // Initialize on page load
    initDataTable();

    // =====================
    // Modal Handling
    // =====================

    // Open Add Modal
    $('#add-record-btn').on('click', function() {
        openModal('create');
    });

    // Open Edit Modal (delegated event)
    $('#librarian-records-table').on('click', '.edit-record-btn', function() {
        var recordTitle = $(this).data('title');
        var $row = $(this).closest('tr');
        var fullData = $row.data('fullRecord');

        if (fullData) {
            openModal('edit', recordTitle, fullData);
        } else {
            showMessage('Error: Could not load record data.', 'error');
        }
    });

    // Close modal buttons
    $('.librarian-modal-close, #cancel-form-btn').on('click', function() {
        closeModal();
    });

    // Close modal on background click
    $('.librarian-modal').on('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });

    // Close modal on Escape key
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
            closeDeleteModal();
        }
    });

    /**
     * Open the add/edit modal
     */
    function openModal(mode, recordTitle, data) {
        var $modal = $('#librarian-modal');
        var $form = $('#librarian-record-form');

        // Reset form
        $form[0].reset();
        $('#form-mode').val(mode);
        originalTitle = null;

        if (mode === 'create') {
            $('#modal-title').text('Add New Record');
            $('#submit-form-btn').text('Create Record');
        } else {
            $('#modal-title').text('Edit Record');
            $('#submit-form-btn').text('Update Record');
            originalTitle = recordTitle; // Store for update

            // Populate form with existing data
            if (data) {
                populateForm(data);
            }
        }

        $modal.fadeIn(200);
        $('#field-Title').focus();
    }

    /**
     * Populate form with record data
     * Uses exact column names from SGS Library Records
     */
    function populateForm(data) {
        // Map column names to form field IDs
        var fieldMapping = {
            'Title': 'field-Title',
            'Author': 'field-Author',
            'Description': 'field-Description',
            'Publisher': 'field-Publisher',
            'Publisher Location': 'field-Publisher-Location',
            'Pub. Year': 'field-Pub-Year',
            'Reprint Year': 'field-Reprint-Year',
            'Location': 'field-Location',
            'Media Type': 'field-Media-Type',
            'Call Number': 'field-Call-Number',
            'ISBN': 'field-ISBN',
            'Physical Location': 'field-Physical-Location',
            'SPL Collection': 'field-SPL-Collection',
            'Link': 'field-Link',
            'Acq. Year': 'field-Acq-Year',
            'Donor': 'field-Donor',
            'Librarian Notes': 'field-Librarian-Notes',
            'New': 'field-New'
        };

        for (var column in fieldMapping) {
            if (data.hasOwnProperty(column)) {
                var $field = $('#' + fieldMapping[column]);
                if ($field.length && data[column] !== null && data[column] !== undefined) {
                    $field.val(data[column]);
                }
            }
        }
    }

    /**
     * Close the add/edit modal
     */
    function closeModal() {
        $('#librarian-modal').fadeOut(200);
        originalTitle = null;
    }

    // =====================
    // Form Submission
    // =====================

    $('#librarian-record-form').on('submit', function(e) {
        e.preventDefault();

        var mode = $('#form-mode').val();

        // Gather form data with exact column names
        var formData = {
            'Title': $('#field-Title').val(),
            'Author': $('#field-Author').val(),
            'Description': $('#field-Description').val(),
            'Publisher': $('#field-Publisher').val(),
            'Publisher Location': $('#field-Publisher-Location').val(),
            'Pub. Year': $('#field-Pub-Year').val(),
            'Reprint Year': $('#field-Reprint-Year').val(),
            'Location': $('#field-Location').val(),
            'Media Type': $('#field-Media-Type').val(),
            'Call Number': $('#field-Call-Number').val(),
            'ISBN': $('#field-ISBN').val(),
            'Physical Location': $('#field-Physical-Location').val(),
            'SPL Collection': $('#field-SPL-Collection').val(),
            'Link': $('#field-Link').val(),
            'Acq. Year': $('#field-Acq-Year').val(),
            'Donor': $('#field-Donor').val(),
            'Librarian Notes': $('#field-Librarian-Notes').val(),
            'New': $('#field-New').val()
        };

        // Validate required fields
        if (!formData['Title'].trim()) {
            showMessage('Title is required.', 'error');
            $('#field-Title').focus();
            return;
        }

        // Disable submit button
        var $submitBtn = $('#submit-form-btn');
        $submitBtn.prop('disabled', true).text(mode === 'create' ? 'Creating...' : 'Updating...');

        if (mode === 'create') {
            createRecord(formData, $submitBtn);
        } else {
            formData.original_title = originalTitle;
            updateRecord(formData, $submitBtn);
        }
    });

    /**
     * Create a new record
     */
    function createRecord(data, $submitBtn) {
        $.ajax({
            url: supabaseLibrarian.restUrl + 'librarian-record',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(data),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', supabaseLibrarian.nonce);
            },
            success: function(response) {
                console.log('Create response:', response);
                if (response.success) {
                    showMessage('Record created successfully!', 'success');
                    closeModal();
                    dataTable.ajax.reload();
                } else {
                    showMessage(response.message || 'Failed to create record', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('Create error:', xhr.status, xhr.responseText);
                var errorMsg = 'Failed to create record';
                try {
                    var response = JSON.parse(xhr.responseText);
                    errorMsg = response.message || errorMsg;
                } catch (e) {
                    errorMsg = error || errorMsg;
                }
                showMessage(errorMsg, 'error');
            },
            complete: function() {
                $submitBtn.prop('disabled', false).text('Create Record');
            }
        });
    }

    /**
     * Update an existing record
     */
    function updateRecord(data, $submitBtn) {
        $.ajax({
            url: supabaseLibrarian.restUrl + 'librarian-record',
            type: 'PATCH',
            contentType: 'application/json',
            data: JSON.stringify(data),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', supabaseLibrarian.nonce);
            },
            success: function(response) {
                console.log('Update response:', response);
                if (response.success) {
                    showMessage('Record updated successfully!', 'success');
                    closeModal();
                    dataTable.ajax.reload();
                } else {
                    showMessage(response.message || 'Failed to update record', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('Update error:', xhr.status, xhr.responseText);
                var errorMsg = 'Failed to update record';
                try {
                    var response = JSON.parse(xhr.responseText);
                    errorMsg = response.message || errorMsg;
                } catch (e) {
                    errorMsg = error || errorMsg;
                }
                showMessage(errorMsg, 'error');
            },
            complete: function() {
                $submitBtn.prop('disabled', false).text('Update Record');
            }
        });
    }

    // =====================
    // Delete Handling
    // =====================

    // Open delete confirmation modal
    $('#librarian-records-table').on('click', '.delete-record-btn', function() {
        var recordTitle = $(this).data('title');

        currentRecordTitle = recordTitle;
        $('#delete-record-title').text('"' + recordTitle + '"');
        $('#delete-confirm-modal').fadeIn(200);
    });

    // Cancel delete
    $('#cancel-delete-btn, #delete-confirm-modal .librarian-modal-close').on('click', function() {
        closeDeleteModal();
    });

    // Confirm delete
    $('#confirm-delete-btn').on('click', function() {
        if (!currentRecordTitle) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Deleting...');

        $.ajax({
            url: supabaseLibrarian.restUrl + 'librarian-record',
            type: 'DELETE',
            contentType: 'application/json',
            data: JSON.stringify({ title: currentRecordTitle }),
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', supabaseLibrarian.nonce);
            },
            success: function(response) {
                if (response.success) {
                    showMessage('Record deleted successfully!', 'success');
                    closeDeleteModal();
                    dataTable.ajax.reload();
                } else {
                    showMessage('Error: ' + (response.message || 'Failed to delete record'), 'error');
                }
            },
            error: function(xhr) {
                var errorMsg = 'Failed to delete record';
                try {
                    var response = JSON.parse(xhr.responseText);
                    errorMsg = response.message || errorMsg;
                } catch (e) {}
                showMessage('Error: ' + errorMsg, 'error');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Delete Record');
            }
        });
    });

    /**
     * Close delete confirmation modal
     */
    function closeDeleteModal() {
        $('#delete-confirm-modal').fadeOut(200);
        currentRecordTitle = null;
    }

    // =====================
    // Utility Functions
    // =====================

    /**
     * Show status message
     */
    function showMessage(message, type) {
        var $container = $('#librarian-status-message');
        var className = 'librarian-message librarian-message-' + type;

        $container.html('<div class="' + className + '">' + escapeHtml(message) + '</div>');

        // Auto-hide success messages after 5 seconds
        if (type === 'success') {
            setTimeout(function() {
                $container.find('.librarian-message').fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
        }
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        if (text === null || text === undefined) {
            return '';
        }

        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };

        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }
});
