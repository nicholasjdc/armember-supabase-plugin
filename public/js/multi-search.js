/**
 * Multi-Database Search JavaScript
 * Handles search interactions, DataTables initialization, and AJAX requests
 */

jQuery(document).ready(function($) {
    var dataTable = null;

    // Handle "Select All" checkbox
    $('#select-all-databases').on('change', function() {
        var isChecked = $(this).is(':checked');
        $('.database-select').prop('checked', isChecked);
    });

    // Update "Select All" based on individual checkboxes
    $('.database-select').on('change', function() {
        var totalCheckboxes = $('.database-select').length;
        var checkedCheckboxes = $('.database-select:checked').length;
        $('#select-all-databases').prop('checked', totalCheckboxes === checkedCheckboxes);
    });

    // Toggle advanced search
    $('#toggle-advanced-search').on('click', function() {
        var $advancedSection = $('.advanced-search');
        var $icon = $(this).find('.dashicons');

        if ($advancedSection.is(':visible')) {
            $advancedSection.slideUp();
            $icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
            $(this).html('<span class="dashicons dashicons-arrow-down-alt2"></span> Show Advanced Search');
        } else {
            $advancedSection.slideDown();
            $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
            $(this).html('<span class="dashicons dashicons-arrow-up-alt2"></span> Hide Advanced Search');
        }
    });

    // Clear form
    $('#clear-multi-search').on('click', function() {
        // Clear all inputs
        $('#multi-search-keyword').val('');
        $('.advanced-search input').val('');
        $('.database-select').prop('checked', false);
        $('#select-all-databases').prop('checked', false);

        // Hide results
        $('.multi-search-results').hide();
        $('#search-status-message').html('');

        // Destroy DataTable if it exists
        if (dataTable) {
            dataTable.destroy();
            dataTable = null;
        }
    });

    // Execute search
    $('#execute-multi-search').on('click', function() {
        executeSearch();
    });

    // Allow Enter key to trigger search in keyword field
    $('#multi-search-keyword').on('keypress', function(e) {
        if (e.which === 13) { // Enter key
            e.preventDefault();
            executeSearch();
        }
    });

    /**
     * Execute the multi-database search
     */
    function executeSearch() {
        // Validate: at least one database must be selected
        var selectedDatabases = $('.database-select:checked').map(function() {
            return $(this).val();
        }).get();

        if (selectedDatabases.length === 0) {
            showMessage('Please select at least one database to search.', 'error');
            return;
        }

        // Get search parameters
        var searchValue = $('#multi-search-keyword').val().trim();
        var advancedFilters = getAdvancedFilters();

        // Validate: either keyword or advanced filters must be provided
        if (!searchValue && Object.keys(advancedFilters).length === 0) {
            showMessage('Please enter search keywords or use advanced filters.', 'error');
            return;
        }

        // Show loading message
        showMessage('Searching across ' + selectedDatabases.length + ' database(s)...', 'info');

        // Initialize or reload DataTable
        initializeDataTable(selectedDatabases, searchValue, advancedFilters);

        // Show results section
        $('.multi-search-results').show();

        // Announce to screen readers that results are loading
        $('#results-summary').html('<p role="status" aria-live="polite">Loading search results...</p>');

        // Move focus to results section after a brief delay to allow DataTables to initialize
        setTimeout(function() {
            $('#results-heading').attr('tabindex', '-1').focus();
        }, 500);
    }

    /**
     * Get advanced search filters
     */
    function getAdvancedFilters() {
        var filters = {};

        $('.advanced-search input[type="text"], .advanced-search input[type="number"]').each(function() {
            var name = $(this).attr('name');
            var value = $(this).val().trim();

            if (value) {
                filters[name] = value;
            }
        });

        return filters;
    }

    /**
     * Initialize DataTables with server-side processing
     */
    function initializeDataTable(databases, searchValue, advancedFilters) {
        // Destroy existing table if it exists
        if (dataTable) {
            dataTable.destroy();
            $('#multi-search-results-table').empty();
        }

        // Initialize DataTable with server-side processing
        dataTable = $('#multi-search-results-table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: supabaseMultiSearch.restUrl,
                type: 'GET',
                data: function(d) {
                    // Add custom search parameters
                    d.databases = databases;
                    d.search_value = searchValue;
                    d.advanced_filters = advancedFilters;
                },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', supabaseMultiSearch.nonce);
                },
                dataSrc: function(json) {
                    // Update results summary
                    updateResultsSummary(json);

                    // Show error if present
                    if (json.error) {
                        showMessage(json.error, 'error');
                    } else {
                        $('#search-status-message').html('');
                    }

                    return json.data;
                },
                error: function(xhr, error, thrown) {
                    console.error('Multi-search AJAX Error:', {
                        status: xhr.status,
                        statusText: xhr.statusText,
                        responseText: xhr.responseText,
                        error: error,
                        thrown: thrown
                    });
                    showMessage('An error occurred while searching. Please try again.', 'error');
                }
            },
            columns: [
                {
                    data: '_source_database_display',
                    title: 'Database Source',
                    render: function(data, type, row) {
                        if (type === 'display') {
                            return '<span class="database-badge">' + data + '</span>';
                        }
                        return data;
                    }
                },
                {
                    data: null,
                    title: 'Record Data',
                    render: function(data, type, row) {
                        if (type === 'display') {
                            return renderRecordData(row);
                        }
                        return '';
                    }
                },
                {
                    data: null,
                    title: 'Actions',
                    orderable: false,
                    render: function(data, type, row) {
                        if (type === 'display') {
                            var buttons = '<div class="record-actions">';
                            buttons += '<button type="button" class="button button-small expand-record-btn" aria-expanded="false" aria-label="View full record details">View Full Record</button>';
                            buttons += '<button type="button" class="button button-small print-record-btn" aria-label="Print full record"><span class="dashicons dashicons-printer"></span> Print</button>';
                            buttons += '<button type="button" class="button button-small copy-record-btn" aria-label="Copy full record to clipboard"><span class="dashicons dashicons-clipboard"></span> Copy</button>';
                            buttons += '</div>';
                            return buttons;
                        }
                        return '';
                    }
                }
            ],
            pageLength: 25,
            lengthMenu: [10, 25, 50, 100],
            order: [[0, 'asc']], // Sort by database source by default
            dom: 'Bfrtip', // Add buttons to the DOM
            buttons: [
                {
                    extend: 'copyHtml5',
                    text: '<span class="dashicons dashicons-clipboard"></span> Copy',
                    titleAttr: 'Copy to clipboard',
                    exportOptions: {
                        columns: [0, 1] // Export only database source and record data
                    }
                },
                {
                    extend: 'csvHtml5',
                    text: '<span class="dashicons dashicons-media-spreadsheet"></span> CSV',
                    titleAttr: 'Export to CSV',
                    filename: 'genealogy-search-results',
                    exportOptions: {
                        columns: [0, 1]
                    }
                },
                {
                    extend: 'excelHtml5',
                    text: '<span class="dashicons dashicons-media-spreadsheet"></span> Excel',
                    titleAttr: 'Export to Excel',
                    filename: 'genealogy-search-results',
                    exportOptions: {
                        columns: [0, 1]
                    }
                }
            ],
            language: {
                processing: '<span class="dashicons dashicons-update spin"></span> Searching databases...',
                search: 'Filter results:',
                lengthMenu: 'Show _MENU_ results per page',
                info: 'Showing _START_ to _END_ of _TOTAL_ results',
                infoEmpty: 'No results found',
                infoFiltered: '(filtered from _MAX_ total results)',
                zeroRecords: 'No matching records found',
                emptyTable: 'No results found. Try adjusting your search criteria.',
                paginate: {
                    first: 'First',
                    last: 'Last',
                    next: 'Next',
                    previous: 'Previous'
                }
            },
            responsive: true,
            rowCallback: function(row, data) {
                // Store full row data for expansion
                $(row).data('fullRecord', data);
            }
        });

        // Handle expand record button clicks (use event delegation once)
        // Remove any existing handlers first to prevent duplicates
        $('#multi-search-results-table').off('click', '.expand-record-btn');

        $('#multi-search-results-table').on('click', '.expand-record-btn', function(e) {
            e.preventDefault();
            e.stopPropagation(); // Prevent event bubbling

            try {
                var $btn = $(this);

                // Prevent rapid double-clicks
                if ($btn.data('expanding')) {
                    console.log('Already expanding, ignoring duplicate click');
                    return;
                }

                $btn.data('expanding', true);

                var $tr = $btn.closest('tr');
                var fullRecord = $tr.data('fullRecord');

                // Debug: Log the record data
                console.log('Expanding record:', fullRecord);

                if (!fullRecord) {
                    console.error('No record data found for row');
                    alert('Error: Could not load record data. Please refresh the page and try again.');
                    $btn.data('expanding', false);
                    return;
                }

                var nextRow = $tr.next();

                // Check if detail row already exists
                if (nextRow.hasClass('record-detail-row')) {
                    // Toggle visibility
                    if (nextRow.is(':visible')) {
                        nextRow.hide();
                        $btn.text('View Full Record')
                            .attr('aria-expanded', 'false')
                            .attr('aria-label', 'View full record details');
                    } else {
                        nextRow.show();
                        $btn.text('Hide Full Record')
                            .attr('aria-expanded', 'true')
                            .attr('aria-label', 'Hide full record details');
                        // Move focus to the expanded content for screen reader users
                        nextRow.find('.full-record-details').attr('tabindex', '-1').focus();
                    }
                } else {
                    // Create new detail row
                    var detailHtml = renderFullRecordDetails(fullRecord);
                    var $detailRow = $('<tr class="record-detail-row"><td colspan="3" class="record-detail-cell">' + detailHtml + '</td></tr>');
                    $tr.after($detailRow);
                    $btn.text('Hide Full Record')
                        .attr('aria-expanded', 'true')
                        .attr('aria-label', 'Hide full record details');
                    // Move focus to the expanded content for screen reader users
                    setTimeout(function() {
                        $detailRow.find('.full-record-details').attr('tabindex', '-1').focus();
                    }, 100);
                }

                // Reset the expanding flag after a short delay
                setTimeout(function() {
                    $btn.data('expanding', false);
                }, 300);

            } catch (error) {
                console.error('Error expanding record:', error);
                alert('Error expanding record: ' + error.message);
                $(this).data('expanding', false);
            }
        });

        // Handle print record button clicks
        $('#multi-search-results-table').off('click', '.print-record-btn');
        $('#multi-search-results-table').on('click', '.print-record-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();

            var $tr = $(this).closest('tr');
            var fullRecord = $tr.data('fullRecord');

            if (!fullRecord) {
                alert('Error: Could not load record data.');
                return;
            }

            printRecord(fullRecord);
        });

        // Handle copy record button clicks
        $('#multi-search-results-table').off('click', '.copy-record-btn');
        $('#multi-search-results-table').on('click', '.copy-record-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();

            var $tr = $(this).closest('tr');
            var fullRecord = $tr.data('fullRecord');

            if (!fullRecord) {
                alert('Error: Could not load record data.');
                return;
            }

            copyRecordToClipboard(fullRecord, $(this));
        });
    }

    /**
     * Print a full record
     */
    function printRecord(record) {
        // Generate HTML for the record
        var html = renderFullRecordDetails(record);

        // Create a new window for printing
        var printWindow = window.open('', '_blank', 'width=800,height=600');

        // Write the HTML content
        printWindow.document.write('<!DOCTYPE html>');
        printWindow.document.write('<html><head><title>Database Record</title>');
        printWindow.document.write('<style>');
        printWindow.document.write('body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 20px; max-width: 800px; margin: 0 auto; }');
        printWindow.document.write('h4 { margin-top: 0; margin-bottom: 1.5rem; padding-bottom: 0.5rem; border-bottom: 2px solid #212529; }');
        printWindow.document.write('.record-details-grid { display: grid; grid-template-columns: 1fr; gap: 1rem; }');
        printWindow.document.write('.record-detail-item { padding: 0.75rem; border: 1px solid #dee2e6; border-radius: 4px; page-break-inside: avoid; }');
        printWindow.document.write('.record-detail-label { font-weight: 600; color: #495057; margin-bottom: 0.25rem; font-size: 0.9rem; }');
        printWindow.document.write('.record-detail-value { color: #212529; font-size: 1rem; }');
        printWindow.document.write('@media print { body { padding: 10px; } }');
        printWindow.document.write('</style>');
        printWindow.document.write('</head><body>');
        printWindow.document.write(html);
        printWindow.document.write('</body></html>');
        printWindow.document.close();

        // Wait for content to load, then print
        printWindow.onload = function() {
            printWindow.focus();
            printWindow.print();
            printWindow.onafterprint = function() {
                printWindow.close();
            };
        };
    }

    /**
     * Copy record to clipboard
     */
    function copyRecordToClipboard(record, $button) {
        // Create formatted text from record
        var text = 'DATABASE RECORD\n';
        text += '================\n\n';

        for (var key in record) {
            if (record.hasOwnProperty(key) && !key.startsWith('_') && record[key] !== null && record[key] !== '') {
                var label = key.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
                var value = record[key];

                // Format value based on type
                if (typeof value === 'boolean') {
                    value = value ? 'Yes' : 'No';
                } else if (typeof value === 'object') {
                    value = JSON.stringify(value, null, 2);
                }

                text += label + ': ' + value + '\n';
            }
        }

        // Copy to clipboard
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                // Show success feedback
                var originalText = $button.html();
                $button.html('<span class="dashicons dashicons-yes"></span> Copied!');
                setTimeout(function() {
                    $button.html(originalText);
                }, 2000);
            }).catch(function(err) {
                console.error('Failed to copy:', err);
                alert('Failed to copy to clipboard. Please try again.');
            });
        } else {
            // Fallback for older browsers
            var textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-9999px';
            document.body.appendChild(textArea);
            textArea.select();
            try {
                document.execCommand('copy');
                var originalText = $button.html();
                $button.html('<span class="dashicons dashicons-yes"></span> Copied!');
                setTimeout(function() {
                    $button.html(originalText);
                }, 2000);
            } catch (err) {
                console.error('Failed to copy:', err);
                alert('Failed to copy to clipboard. Please try again.');
            }
            document.body.removeChild(textArea);
        }
    }

    /**
     * Render full record details in expandable section
     */
    function renderFullRecordDetails(record) {
        try {
            var html = '<div class="full-record-details">';
            html += '<h4>Complete Record Details</h4>';
            html += '<div class="record-details-grid">';

            var fieldCount = 0;

            // Display all fields except internal ones
            for (var key in record) {
                if (record.hasOwnProperty(key) && !key.startsWith('_') && record[key] !== null && record[key] !== '') {
                    try {
                        var label = key.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
                        var value = record[key];

                        // Format value based on type
                        if (typeof value === 'boolean') {
                            value = value ? 'Yes' : 'No';
                        } else if (typeof value === 'object') {
                            try {
                                value = JSON.stringify(value, null, 2);
                            } catch (jsonError) {
                                console.error('Error stringifying object for key:', key, jsonError);
                                value = '[Complex Object]';
                            }
                        } else {
                            value = escapeHtml(String(value));
                        }

                        html += '<div class="record-detail-item">';
                        html += '<div class="record-detail-label">' + escapeHtml(label) + '</div>';
                        html += '<div class="record-detail-value">' + value + '</div>';
                        html += '</div>';

                        fieldCount++;
                    } catch (fieldError) {
                        console.error('Error rendering field:', key, fieldError);
                        // Continue with other fields
                    }
                }
            }

            html += '</div>';

            if (fieldCount === 0) {
                html += '<p><em>No additional details available for this record.</em></p>';
            }

            // Add link to database page if available
            var tableUrl = supabaseMultiSearch.tablePageUrls[record._source_database];
            if (tableUrl && record._source_database_display) {
                html += '<div class="record-actions">';
                html += '<a href="' + tableUrl + '" class="button button-secondary" target="_blank">View All ' + escapeHtml(record._source_database_display) + ' Records</a>';
                html += '</div>';
            }

            html += '</div>';
            return html;
        } catch (error) {
            console.error('Error in renderFullRecordDetails:', error);
            return '<div class="full-record-details"><p style="color: red;">Error rendering record details. Check console for details.</p></div>';
        }
    }

    /**
     * Render record data in a readable format
     */
    function renderRecordData(row) {
        var html = '<div class="record-data">';
        var displayedFields = 0;
        var maxFields = 5; // Limit displayed fields for readability

        // Priority fields to display (common genealogical fields)
        var priorityFields = [
            'first_name', 'firstname', 'given_name',
            'last_name', 'lastname', 'surname',
            'birth_date', 'birth_year', 'birthyear',
            'birth_place', 'birthplace',
            'death_date', 'death_year',
            'residence', 'location'
        ];

        // Display priority fields first
        for (var i = 0; i < priorityFields.length && displayedFields < maxFields; i++) {
            var field = priorityFields[i];
            if (row[field] && row[field] !== '' && row[field] !== null) {
                var label = field.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
                html += '<div class="record-field"><strong>' + label + ':</strong> ' + escapeHtml(row[field]) + '</div>';
                displayedFields++;
            }
        }

        // If we haven't displayed enough fields, show other fields
        if (displayedFields < maxFields) {
            for (var key in row) {
                if (displayedFields >= maxFields) break;
                if (row.hasOwnProperty(key) &&
                    !key.startsWith('_') &&
                    priorityFields.indexOf(key) === -1 &&
                    row[key] && row[key] !== '' && row[key] !== null) {
                    var label = key.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
                    html += '<div class="record-field"><strong>' + label + ':</strong> ' + escapeHtml(row[key]) + '</div>';
                    displayedFields++;
                }
            }
        }

        html += '</div>';
        return html;
    }

    /**
     * Update results summary
     */
    function updateResultsSummary(json) {
        var total = json.recordsTotal || 0;
        var message = 'Found <strong>' + total + '</strong> result' + (total !== 1 ? 's' : '');

        $('#results-summary').html('<p class="results-summary-text">' + message + '</p>');
    }

    /**
     * Show status message
     */
    function showMessage(message, type) {
        var className = 'search-message search-message-' + type;
        var $statusDiv = $('#search-status-message');
        $statusDiv.html('<div class="' + className + '" role="alert">' + message + '</div>');

        // Move focus to status message for screen readers if it's an error
        if (type === 'error') {
            // Delay slightly to ensure the message is rendered
            setTimeout(function() {
                $statusDiv.attr('tabindex', '-1').focus();
            }, 100);
        }
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        if (typeof text !== 'string') {
            text = String(text);
        }
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
});
