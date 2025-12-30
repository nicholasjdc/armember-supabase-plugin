/**
 * Library Catalog JavaScript
 */

jQuery(document).ready(function($) {
    'use strict';

    // Initialize DataTable
    var table = $('#library-results-table').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: supabaseLibrary.apiUrl,
            type: 'GET',
            data: function(d) {
                // Add search form parameters
                d.title = $('#search-title').val();
                d.author = $('#search-author').val();
                d.keyword = $('#search-keyword').val();
                d.physical_location = $('#search-physical-location').val();
                d.new = $('#search-new').is(':checked') ? '1' : '';

                // Debug logging
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', supabaseLibrary.nonce);
            },
            error: function(xhr, error, thrown) {
                console.error('DataTables error:', error, thrown);
                console.error('Response:', xhr.responseText);
                alert('Error loading library data. Please try again.');
            }
        },
        columns: [
            {
                data: 'title',
                render: function(data, type, row) {
                    return '<strong>' + escapeHtml(data) + '</strong>';
                }
            },
            {
                data: 'author',
                render: function(data, type, row) {
                    return escapeHtml(data);
                }
            },
            {
                data: 'publisher',
                render: function(data, type, row) {
                    return escapeHtml(data || '—');
                }
            },
            {
                data: 'publication_date',
                render: function(data, type, row) {
                    return escapeHtml(data || '—');
                }
            },
            {
                data: null,
                orderable: false,
                searchable: false,
                render: function(data, type, row) {
                    return '<button class="view-details-btn" data-row="' + escapeHtml(JSON.stringify(row.full_data)) + '">View Details</button>';
                }
            }
        ],
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        language: {
            emptyTable: 'No items found in the library catalog',
            zeroRecords: 'No matching items found',
            info: 'Showing _START_ to _END_ of _TOTAL_ items',
            infoEmpty: 'Showing 0 to 0 of 0 items',
            infoFiltered: '(filtered from _MAX_ total items)',
            lengthMenu: 'Show _MENU_ items per page',
            search: 'Quick search:',
            paginate: {
                first: 'First',
                last: 'Last',
                next: 'Next',
                previous: 'Previous'
            },
            processing: '<div class="library-loading">Loading library data</div>'
        },
        dom: '<"top"lf>rt<"bottom"ip><"clear">',
        order: [[0, 'asc']]
    });

    // Search form submission
    $(document).on('submit', '#library-search-form', function(e) {
        e.preventDefault();
        table.ajax.reload();
        return false;
    });

    // Search button click (backup handler)
    $(document).on('click', '#library-search-form button[type="submit"]', function(e) {
        e.preventDefault();
        table.ajax.reload();
        return false;
    });

    // Clear search button
    $('#clear-search').on('click', function(e) {
        e.preventDefault();
        $('#library-search-form')[0].reset();
        table.ajax.reload();
    });

    // Trigger search on input change (with debounce) - DISABLED for explicit search
    // Users must click Search button to search
    /*
    var searchTimeout;
    $('.search-input, .search-select, #search-new').on('change keyup', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            table.ajax.reload();
        }, 500);
    });
    */

    // View details button
    $(document).on('click', '.view-details-btn', function() {
        var rowData = $(this).data('row');

        if (typeof rowData === 'string') {
            try {
                rowData = JSON.parse(rowData);
            } catch (e) {
                console.error('Error parsing row data:', e);
                return;
            }
        }

        // If the data has a full_data property, use that (it contains the complete Supabase row)
        if (rowData && rowData.full_data) {
            rowData = rowData.full_data;
        }

        showItemDetail(rowData);
    });

    // Modal close button
    $('.library-modal-close').on('click', function() {
        $('#item-detail-modal').hide();
    });

    // Close modal when clicking outside
    $(window).on('click', function(event) {
        if (event.target.id === 'item-detail-modal') {
            $('#item-detail-modal').hide();
        }
    });

    // Close modal on Escape key
    $(document).on('keydown', function(event) {
        if (event.key === 'Escape') {
            $('#item-detail-modal').hide();
        }
    });

    // Print button handler
    $('#print-item-btn').on('click', function() {
        printItemDetails();
    });

    /**
     * Print item details in a new window
     */
    function printItemDetails() {
        // Get the modal content
        var content = $('#item-detail-content').html();

        // Create a new window for printing
        var printWindow = window.open('', '_blank', 'width=800,height=600');

        // Write the HTML content
        printWindow.document.write('<!DOCTYPE html>');
        printWindow.document.write('<html><head><title>Library Item Details</title>');
        printWindow.document.write('<style>');
        printWindow.document.write('body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 20px; max-width: 800px; margin: 0 auto; }');
        printWindow.document.write('h3 { margin-top: 0; margin-bottom: 1.5rem; padding-bottom: 0.5rem; border-bottom: 2px solid #212529; color: #212529; }');
        printWindow.document.write('.detail-field { margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid #e9ecef; page-break-inside: avoid; }');
        printWindow.document.write('.detail-field:last-child { border-bottom: none; }');
        printWindow.document.write('.detail-label { font-weight: 600; color: #495057; margin-bottom: 0.5rem; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; }');
        printWindow.document.write('.detail-value { color: #212529; font-size: 1rem; line-height: 1.5; }');
        printWindow.document.write('.detail-value.empty { color: #6c757d; font-style: italic; }');
        printWindow.document.write('.detail-value a { color: #2271b1; text-decoration: none; }');
        printWindow.document.write('@media print { body { padding: 10px; } }');
        printWindow.document.write('</style>');
        printWindow.document.write('</head><body>');
        printWindow.document.write(content);
        printWindow.document.write('</body></html>');
        printWindow.document.close();

        // Wait for content to load, then print
        printWindow.onload = function() {
            printWindow.focus();
            printWindow.print();
            // Close the window after printing (user can cancel)
            printWindow.onafterprint = function() {
                printWindow.close();
            };
        };
    }

    /**
     * Show item detail modal
     */
    function showItemDetail(data) {
        
        var titleValue = getValueCaseInsensitive(data, 'Title') || getValueCaseInsensitive(data, 'title') || 'Untitled';
        var html = '<h3>' + escapeHtml(titleValue) + '</h3>';

        // Field definitions with labels - matching actual Supabase schema
        // Each field can have multiple possible key names to try
        var fields = [
            { keys: ['Author'], label: 'Author' },
            { keys: ['Description'], label: 'Description' },
            { keys: ['Publisher'], label: 'Publisher' },
            { keys: ['Publisher Location', 'PublisherLocation', 'publisher_location'], label: 'Publisher Location' },
            { keys: ['Pub. Year', 'Pub Year', 'Publication Year', 'publication_date', 'pub_year'], label: 'Publication Year' },
            { keys: ['Reprint Year', 'ReprintYear', 'reprint_date', 'reprint_year'], label: 'Reprint Year' },
            { keys: ['Location'], label: 'Location' },
            { keys: ['Media Type', 'MediaType', 'media_type'], label: 'Media Type' },
            { keys: ['Call Number', 'CallNumber', 'call_number'], label: 'Call Number' },
            { keys: ['ISBN'], label: 'ISBN' },
            { keys: ['Physical Location', 'PhysicalLocation', 'physical_location'], label: 'Physical Location' },
            { keys: ['SPL Collection', 'SPLCollection', 'spl_collection'], label: 'SPL Collection' },
            { keys: ['Link', 'link_url', 'Link URL'], label: 'Link' },
            { keys: ['Acq. Year', 'Acq Year', 'Acquisition Year', 'acquisition_date', 'acq_year'], label: 'Acquisition Year' },
            { keys: ['Donor', 'donor_or_purchase', 'Donor or Purchase'], label: 'Donor' },
            { keys: ['Librarian Notes', 'LibrarianNotes', 'librarian_notes'], label: 'Librarian Notes' },
            { keys: ['updatedByName', 'updated_by', 'Updated By'], label: 'Updated By' },
            { keys: ['Last Updated Date', 'LastUpdatedDate', 'updated', 'last_updated_date'], label: 'Last Updated Date' },
            { keys: ['New'], label: 'New Item', render: function(val) {
                // Handle text values: "Yes", "No", true, false, 1, 0, etc.
                if (val === true || val === 'true' || val === 1 || val === '1' || 
                    (typeof val === 'string' && val.toLowerCase() === 'yes')) {
                    return 'Yes';
                }
                return 'No';
            }}
        ];

        fields.forEach(function(field) {
            // Try each possible key name until we find a match
            var value = null;
            for (var i = 0; i < field.keys.length; i++) {
                value = getValueCaseInsensitive(data, field.keys[i]);
                if (value !== null && value !== undefined && value !== '') {
                    break;
                }
            }
            var isEmpty = value === null || value === undefined || value === '';

            html += '<div class="detail-field">';
            html += '<div class="detail-label">' + escapeHtml(field.label) + '</div>';

            if (isEmpty) {
                html += '<div class="detail-value empty">Not specified</div>';
            } else {
                var displayValue = field.render ? field.render(value) : value;

                // Handle URLs - check if this is the Link field
                var isLinkField = field.keys.some(function(k) { 
                    return k.toLowerCase() === 'link' || k.toLowerCase() === 'link_url' || k.toLowerCase() === 'link url';
                });
                if (isLinkField && value) {
                    // Validate URL format
                    var urlValue = String(value).trim();
                    if (urlValue && (urlValue.startsWith('http://') || urlValue.startsWith('https://'))) {
                        html += '<div class="detail-value"><a href="' + escapeHtml(urlValue) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(urlValue) + '</a></div>';
                    } else {
                        html += '<div class="detail-value">' + escapeHtml(String(displayValue)) + '</div>';
                    }
                } else {
                    html += '<div class="detail-value">' + escapeHtml(String(displayValue)) + '</div>';
                }
            }

            html += '</div>';
        });

        $('#item-detail-content').html(html);
        $('#item-detail-modal').show();
    }

    /**
     * Get value from object case-insensitively
     */
    function getValueCaseInsensitive(obj, key) {
        var keyLower = key.toLowerCase();

        for (var k in obj) {
            if (obj.hasOwnProperty(k) && k.toLowerCase() === keyLower) {
                return obj[k];
            }
        }

        return null;
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

    /**
     * Format field name for display
     */
    function formatFieldName(fieldName) {
        return fieldName
            .split('_')
            .map(function(word) {
                return word.charAt(0).toUpperCase() + word.slice(1);
            })
            .join(' ');
    }
});
