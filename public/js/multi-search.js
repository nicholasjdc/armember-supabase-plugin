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

        // Validate: keyword must be provided
        if (!searchValue) {
            showMessage('Please enter search keywords.', 'error');
            return;
        }

        // Show loading message
        showMessage('Searching across ' + selectedDatabases.length + ' database(s)...', 'info');

        // Initialize or reload DataTable
        initializeDataTable(selectedDatabases, searchValue);

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
     * Initialize DataTables with server-side processing
     */
    function initializeDataTable(databases, searchValue) {
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
                            
                            // Check if this record has a PDF URL and add "View image" button if so
                            var pdfUrl = getRecordPdfUrl(row);
                            if (pdfUrl) {
                                buttons += '<button type="button" class="button button-small view-image-btn" aria-label="View PDF document" data-pdf-url="' + escapeHtml(pdfUrl) + '"><span class="dashicons dashicons-media-document"></span> View Image</button>';
                            }
                            
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
            dom: 'Brtip', // Add buttons to the DOM (removed 'f' for filter/search box)
            searching: false, // Disable the search box
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

        // Handle view image button clicks
        $('#multi-search-results-table').off('click', '.view-image-btn');
        $('#multi-search-results-table').on('click', '.view-image-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();

            var pdfUrl = $(this).data('pdf-url');
            if (!pdfUrl) {
                alert('Error: PDF URL not found.');
                return;
            }

            openPdfModal(pdfUrl, $(this));
        });
    }

    /**
     * Print a full record
     * Uses similar techniques to PDF modal for better CORS handling
     */
    function printRecord(record) {
        // Generate HTML for the record
        var html = renderFullRecordDetails(record);

        // Check if this database has a PDF column and if the record has a PDF
        var pdfUrl = getRecordPdfUrl(record);
        
        console.log('Print Record - PDF URL:', pdfUrl);
        
        // Create a new window for printing
        var printWindow = window.open('', '_blank', 'width=800,height=600');
        
        if (!printWindow) {
            alert('Please allow popups to print records. Your browser blocked the print window.');
            return;
        }

        // Build the print document HTML
        var printHtml = buildPrintDocument(html, pdfUrl);
        
        // Write the HTML content
        printWindow.document.write(printHtml);
        printWindow.document.close();

        // Wait for window to load, then handle PDF rendering
        printWindow.onload = function() {
            if (pdfUrl) {
                // Inject script to load PDF in print window's context
                injectPdfLoaderScript(printWindow, pdfUrl);
            } else {
                // No PDF, print immediately
                setTimeout(function() {
                    printWindow.focus();
                    printWindow.print();
                    printWindow.onafterprint = function() {
                        printWindow.close();
                    };
                }, 100);
            }
        };
    }

    /**
     * Build the print document HTML structure
     */
    function buildPrintDocument(recordHtml, pdfUrl) {
        var html = '<!DOCTYPE html>';
        html += '<html><head>';
        html += '<title>Database Record - Print</title>';
        html += '<meta charset="UTF-8">';
        html += '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        
        // Load PDF.js if PDF exists
        if (pdfUrl) {
            html += '<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>';
        }
        
        // Styles
        html += '<style>';
        html += 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 20px; max-width: 800px; margin: 0 auto; background: #fff; }';
        html += 'h4 { margin-top: 0; margin-bottom: 1.5rem; padding-bottom: 0.5rem; border-bottom: 2px solid #212529; }';
        html += '.record-details-grid { display: grid; grid-template-columns: 1fr; gap: 1rem; }';
        html += '.record-detail-item { padding: 0.75rem; border: 1px solid #dee2e6; border-radius: 4px; page-break-inside: avoid; }';
        html += '.record-detail-label { font-weight: 600; color: #495057; margin-bottom: 0.25rem; font-size: 0.9rem; }';
        html += '.record-detail-value { color: #212529; font-size: 1rem; }';
        html += '.record-pdf-section { margin-top: 2rem; page-break-before: always; border-top: 2px solid #212529; padding-top: 1.5rem; }';
        html += '.pdf-pages-container { margin-top: 1rem; }';
        html += '.pdf-page-canvas { width: 100%; height: auto; border: 1px solid #dee2e6; margin-bottom: 1rem; page-break-after: always; display: block; max-width: 100%; }';
        html += '.pdf-loading { text-align: center; padding: 2rem; color: #6c757d; }';
        html += '.pdf-error { text-align: center; padding: 1rem; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; margin: 1rem 0; }';
        html += '.pdf-error p { margin: 0.5rem 0; color: #721c24; font-weight: 600; }';
        html += '.pdf-error a { color: #2271b1; text-decoration: underline; word-break: break-all; }';
        html += '.pdf-iframe-fallback { width: 100%; height: 80vh; border: 1px solid #dee2e6; margin-top: 1rem; }';
        html += '.pdf-iframe-print-notice { background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 1rem; margin: 1rem 0; color: #856404; }';
        html += '.pdf-iframe-print-notice p { margin: 0.5rem 0; }';
        html += '.pdf-iframe-print-notice a { color: #2271b1; text-decoration: underline; font-weight: 600; }';
        html += 'object[type="application/pdf"] { width: 100%; height: 80vh; border: 1px solid #dee2e6; }';
        html += '@media print { ';
        html += 'body { padding: 10px; } ';
        html += '.pdf-page-canvas { page-break-after: always; max-width: 100%; } ';
        html += '.pdf-loading, .pdf-error, .pdf-iframe-print-notice { page-break-inside: avoid; } ';
        html += '.pdf-iframe-print-notice { background: #fff; border: 1px solid #ccc; } ';
        html += 'iframe, object[type="application/pdf"] { page-break-inside: avoid; height: auto !important; max-height: 100vh; } ';
        html += '} ';
        html += '</style>';
        html += '</head><body>';
        html += recordHtml;
        
        // Add PDF section if PDF exists
        if (pdfUrl) {
            html += '<div class="record-pdf-section">';
            html += '<h4>Associated Document</h4>';
            html += '<div class="pdf-loading" id="pdf-loading">Loading PDF pages...</div>';
            html += '<div class="pdf-pages-container" id="pdf-pages-container"></div>';
            html += '<div class="pdf-error" id="pdf-error" style="display: none;"></div>';
            html += '<iframe class="pdf-iframe-fallback" id="pdf-iframe-fallback" style="display: none;"></iframe>';
            html += '</div>';
        }
        
        html += '</body></html>';
        return html;
    }

    /**
     * Inject script into print window to load PDF (runs in print window's context)
     */
    function injectPdfLoaderScript(printWindow, pdfUrl) {
        var script = printWindow.document.createElement('script');
        script.textContent = '(' + function(pdfUrl) {
            // This code runs in the print window's context
            var container = document.getElementById('pdf-pages-container');
            var loadingDiv = document.getElementById('pdf-loading');
            var errorDiv = document.getElementById('pdf-error');
            var iframeFallback = document.getElementById('pdf-iframe-fallback');
            
            function escapeHtml(text) {
                if (typeof text !== 'string') text = String(text);
                var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
                return text.replace(/[&<>"']/g, function(m) { return map[m]; });
            }
            
            function showError(message) {
                if (loadingDiv) loadingDiv.style.display = 'none';
                if (container) container.style.display = 'none';
                if (iframeFallback) iframeFallback.style.display = 'none';
                if (errorDiv) {
                    errorDiv.innerHTML = '<p>' + escapeHtml(message) + '</p><p><a href="' + escapeHtml(pdfUrl) + '" target="_blank">Download PDF</a></p>';
                    errorDiv.style.display = 'block';
                }
                setTimeout(function() {
                    window.focus();
                    window.print();
                    window.onafterprint = function() { window.close(); };
                }, 500);
            }
            
            function tryIframeFallback() {
                if (loadingDiv) loadingDiv.style.display = 'none';
                if (container) container.style.display = 'none';
                if (iframeFallback) {
                    // Add print notice before iframe
                    var printNotice = document.createElement('div');
                    printNotice.className = 'pdf-iframe-print-notice';
                    printNotice.innerHTML = '<p><strong>Note:</strong> Due to browser limitations, only the first page of the PDF may print when embedded. For complete PDF printing, please <a href="' + escapeHtml(pdfUrl) + '" target="_blank" onclick="window.open(this.href); return false;">open the PDF in a new tab</a> and print it separately.</p>';
                    iframeFallback.parentNode.insertBefore(printNotice, iframeFallback);
                    
                    // Set iframe source with print-friendly attributes
                    iframeFallback.src = pdfUrl;
                    iframeFallback.style.display = 'block';
                    
                    // Try using object tag as alternative (better for printing in some browsers)
                    var objectTag = document.createElement('object');
                    objectTag.data = pdfUrl;
                    objectTag.type = 'application/pdf';
                    objectTag.style.width = '100%';
                    objectTag.style.height = '80vh';
                    objectTag.style.border = '1px solid #dee2e6';
                    objectTag.style.display = 'none';
                    objectTag.id = 'pdf-object-fallback';
                    
                    // Add fallback content for object tag
                    var objectFallback = document.createElement('p');
                    objectFallback.innerHTML = 'PDF cannot be displayed. <a href="' + escapeHtml(pdfUrl) + '" target="_blank">Download PDF</a>';
                    objectTag.appendChild(objectFallback);
                    
                    iframeFallback.parentNode.appendChild(objectTag);
                    
                    // Try object tag first (better for printing), fallback to iframe
                    var objectLoaded = false;
                    objectTag.onload = function() {
                        objectLoaded = true;
                        iframeFallback.style.display = 'none';
                        objectTag.style.display = 'block';
                        setTimeout(function() {
                            window.focus();
                            window.print();
                            window.onafterprint = function() { window.close(); };
                        }, 2000);
                    };
                    
                    // If object doesn't load, use iframe
                    setTimeout(function() {
                        if (!objectLoaded) {
                            objectTag.style.display = 'none';
                            iframeFallback.style.display = 'block';
                        }
                    }, 1000);
                    
                    iframeFallback.onload = function() {
                        if (!objectLoaded) {
                            setTimeout(function() {
                                window.focus();
                                window.print();
                                window.onafterprint = function() { window.close(); };
                            }, 2000);
                        }
                    };
                    
                    iframeFallback.onerror = function() {
                        objectTag.style.display = 'none';
                        iframeFallback.style.display = 'none';
                        showError('Unable to display PDF due to cross-origin restrictions. Please use the download link.');
                    };
                    
                    // Fallback timeout
                    setTimeout(function() {
                        if (!objectLoaded && iframeFallback.style.display !== 'none') {
                            window.focus();
                            window.print();
                            window.onafterprint = function() { window.close(); };
                        }
                    }, 4000);
                } else {
                    showError('Unable to display PDF. Please use the download link.');
                }
            }
            
            function renderAllPages(pdf) {
                var totalPages = pdf.numPages;
                var pagesRendered = 0;
                var pages = [];
                var allPagesAppended = false;
                
                function appendAllPagesAndPrint() {
                    if (allPagesAppended) return; // Prevent multiple calls
                    allPagesAppended = true;
                    
                    // Append all pages to container
                    for (var i = 0; i < pages.length; i++) {
                        if (pages[i]) {
                            container.appendChild(pages[i]);
                        }
                    }
                    
                    if (loadingDiv) loadingDiv.style.display = 'none';
                    
                    // Wait for browser to paint all canvases before printing
                    // Use requestAnimationFrame to ensure all pages are rendered
                    requestAnimationFrame(function() {
                        requestAnimationFrame(function() {
                            // Additional small delay to ensure all pages are fully painted
                            setTimeout(function() {
                                window.focus();
                                window.print();
                                window.onafterprint = function() { window.close(); };
                            }, 300);
                        });
                    });
                }
                
                for (var pageNum = 1; pageNum <= totalPages; pageNum++) {
                    (function(pageNumber) {
                        pdf.getPage(pageNumber).then(function(page) {
                            var viewport = page.getViewport({ scale: 1.5 });
                            var canvas = document.createElement('canvas');
                            var context = canvas.getContext('2d');
                            canvas.height = viewport.height;
                            canvas.width = viewport.width;
                            canvas.className = 'pdf-page-canvas';
                            
                            page.render({
                                canvasContext: context,
                                viewport: viewport
                            }).promise.then(function() {
                                // Store canvas in correct position
                                pages[pageNumber - 1] = canvas;
                                pagesRendered++;
                                
                                // When all pages are rendered, append and print
                                if (pagesRendered === totalPages) {
                                    appendAllPagesAndPrint();
                                }
                            }).catch(function(error) {
                                console.error('Error rendering page', pageNumber, error);
                                pagesRendered++;
                                // Still try to print what we have
                                if (pagesRendered === totalPages) {
                                    appendAllPagesAndPrint();
                                }
                            });
                        }).catch(function(error) {
                            console.error('Error getting page', pageNumber, error);
                            pagesRendered++;
                            // Still try to print what we have
                            if (pagesRendered === totalPages) {
                                appendAllPagesAndPrint();
                            }
                        });
                    })(pageNum);
                }
            }
            
            // Wait for PDF.js to load
            var maxWaitTime = 10000;
            var startTime = Date.now();
            var checkInterval = setInterval(function() {
                var elapsed = Date.now() - startTime;
                
                if (typeof pdfjsLib !== 'undefined') {
                    clearInterval(checkInterval);
                    
                    // Set PDF.js worker
                    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
                    
                    // Load PDF
                    pdfjsLib.getDocument({
                        url: pdfUrl,
                        withCredentials: false
                    }).promise.then(function(pdf) {
                        console.log('PDF loaded for printing, pages:', pdf.numPages);
                        renderAllPages(pdf);
                    }).catch(function(error) {
                        console.error('Error loading PDF for print:', error);
                        
                        var errorMessage = error && error.message ? String(error.message) : '';
                        var errorName = error && error.name ? String(error.name) : '';
                        var errorDetails = error && error.details ? String(error.details) : '';
                        
                        var isCorsError = (
                            errorMessage.indexOf('CORS') !== -1 ||
                            errorMessage.indexOf('NetworkError') !== -1 ||
                            errorMessage.indexOf('fetch') !== -1 ||
                            errorMessage.indexOf('Cross-Origin') !== -1 ||
                            errorName === 'UnknownErrorException' ||
                            errorDetails.indexOf('NetworkError') !== -1 ||
                            errorDetails.indexOf('CORS') !== -1
                        );
                        
                        if (isCorsError) {
                            console.log('CORS error in print window, trying iframe fallback');
                            tryIframeFallback();
                        } else {
                            var errorMsg = errorMessage || 'Unknown error occurred while loading the PDF.';
                            showError('Failed to load PDF: ' + errorMsg);
                        }
                    });
                } else if (elapsed > maxWaitTime) {
                    clearInterval(checkInterval);
                    console.error('PDF.js failed to load in print window');
                    showError('PDF viewer library failed to load. Please try again.');
                }
            }, 100);
        } + ')(' + JSON.stringify(pdfUrl) + ');';
        
        printWindow.document.body.appendChild(script);
    }

    /**
     * Render all PDF pages for printing
     */
    function renderAllPdfPagesForPrint(printWindow, pdf, container) {
        var totalPages = pdf.numPages;
        var pagesRendered = 0;
        var pages = [];
        
        // Render all pages
        for (var pageNum = 1; pageNum <= totalPages; pageNum++) {
            (function(pageNumber) {
                pdf.getPage(pageNumber).then(function(page) {
                    var viewport = page.getViewport({ scale: 1.5 });
                    
                    // Create canvas
                    var canvas = printWindow.document.createElement('canvas');
                    var context = canvas.getContext('2d');
                    canvas.height = viewport.height;
                    canvas.width = viewport.width;
                    canvas.className = 'pdf-page-canvas';
                    
                    // Render page
                    var renderContext = {
                        canvasContext: context,
                        viewport: viewport
                    };
                    
                    page.render(renderContext).promise.then(function() {
                        pages[pageNumber - 1] = canvas;
                        pagesRendered++;
                        
                        // When all pages are rendered, append them and print
                        if (pagesRendered === totalPages) {
                            // Append pages in order
                            for (var i = 0; i < pages.length; i++) {
                                if (pages[i]) {
                                    container.appendChild(pages[i]);
                                }
                            }
                            
                            // Print after a short delay to ensure rendering is complete
                            setTimeout(function() {
                                printWindow.focus();
                                printWindow.print();
                                printWindow.onafterprint = function() {
                                    printWindow.close();
                                };
                            }, 500);
                        }
                    }).catch(function(error) {
                        console.error('Error rendering page', pageNumber, error);
                        pagesRendered++;
                        if (pagesRendered === totalPages) {
                            // Still try to print what we have
                            setTimeout(function() {
                                printWindow.focus();
                                printWindow.print();
                                printWindow.onafterprint = function() {
                                    printWindow.close();
                                };
                            }, 500);
                        }
                    });
                }).catch(function(error) {
                    console.error('Error getting page', pageNumber, error);
                    pagesRendered++;
                    if (pagesRendered === totalPages) {
                        setTimeout(function() {
                            printWindow.focus();
                            printWindow.print();
                            printWindow.onafterprint = function() {
                                printWindow.close();
                            };
                        }, 500);
                    }
                });
            })(pageNum);
        }
    }

    /**
     * Try iframe fallback for print window
     */
    function tryIframeFallbackForPrint(printWindow, pdfUrl) {
        var doc = printWindow.document;
        var loadingDiv = doc.getElementById('pdf-loading');
        var errorDiv = doc.getElementById('pdf-error');
        var iframeFallback = doc.getElementById('pdf-iframe-fallback');
        var container = doc.getElementById('pdf-pages-container');
        
        // Hide loading
        if (loadingDiv) loadingDiv.style.display = 'none';
        
        // Hide container
        if (container) container.style.display = 'none';
        
        // Set iframe source
        if (iframeFallback) {
            iframeFallback.src = pdfUrl;
            iframeFallback.style.display = 'block';
            
            // Try to print after iframe loads
            iframeFallback.onload = function() {
                setTimeout(function() {
                    printWindow.focus();
                    printWindow.print();
                    printWindow.onafterprint = function() {
                        printWindow.close();
                    };
                }, 1000);
            };
            
            // If iframe fails, show error
            iframeFallback.onerror = function() {
                iframeFallback.style.display = 'none';
                showPdfErrorInPrint(printWindow, pdfUrl, 
                    'Unable to display PDF due to cross-origin restrictions. The PDF server does not allow embedding. Please use the download link below to view the PDF separately.'
                );
            };
            
            // Fallback timeout - if iframe doesn't load in 5 seconds, show error
            setTimeout(function() {
                if (iframeFallback.style.display === 'block' && !iframeFallback.contentDocument) {
                    try {
                        // Try to access iframe content (will fail if CORS blocks it)
                        var iframeDoc = iframeFallback.contentDocument || iframeFallback.contentWindow.document;
                        // If we can't access it, it might still be loading or blocked
                        // Give it a bit more time, then try to print anyway
                        setTimeout(function() {
                            printWindow.focus();
                            printWindow.print();
                            printWindow.onafterprint = function() {
                                printWindow.close();
                            };
                        }, 2000);
                    } catch (e) {
                        // Can't access iframe - might still display in some browsers
                        // Try printing anyway
                        setTimeout(function() {
                            printWindow.focus();
                            printWindow.print();
                            printWindow.onafterprint = function() {
                                printWindow.close();
                            };
                        }, 1000);
                    }
                }
            }, 5000);
        } else {
            showPdfErrorInPrint(printWindow, pdfUrl, 'Unable to display PDF. Please use the download link below.');
        }
    }

    /**
     * Show PDF error in print window
     */
    function showPdfErrorInPrint(printWindow, pdfUrl, message) {
        var doc = printWindow.document;
        var loadingDiv = doc.getElementById('pdf-loading');
        var errorDiv = doc.getElementById('pdf-error');
        var container = doc.getElementById('pdf-pages-container');
        var iframeFallback = doc.getElementById('pdf-iframe-fallback');
        
        // Hide other elements
        if (loadingDiv) loadingDiv.style.display = 'none';
        if (container) container.style.display = 'none';
        if (iframeFallback) iframeFallback.style.display = 'none';
        
        // Show error
        if (errorDiv) {
            errorDiv.innerHTML = '<p>' + escapeHtml(message) + '</p>';
            if (pdfUrl) {
                errorDiv.innerHTML += '<p><a href="' + escapeHtml(pdfUrl) + '" target="_blank">Download PDF</a></p>';
            }
            errorDiv.style.display = 'block';
        }
        
        // Still allow printing (record data will print, PDF will show as error message)
        setTimeout(function() {
            printWindow.focus();
            printWindow.print();
            printWindow.onafterprint = function() {
                printWindow.close();
            };
        }, 500);
    }

    /**
     * Show PDF fallback (link) if PDF.js fails or isn't available
     */
    function showPdfFallback(printWindow, pdfUrl) {
        var pdfContainer = printWindow.document.getElementById('pdf-pages-container');
        var loadingDiv = printWindow.document.querySelector('.pdf-loading');
        if (pdfContainer) {
            pdfContainer.innerHTML = '<div style="text-align: center; padding: 1rem; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;"><p style="margin: 0 0 0.5rem 0; font-weight: 600;">PDF Document:</p><a href="' + escapeHtml(pdfUrl) + '" target="_blank" style="color: #2271b1; text-decoration: underline; font-size: 1.1rem; word-break: break-all;">' + escapeHtml(pdfUrl) + '</a></div>';
        }
        if (loadingDiv) {
            loadingDiv.style.display = 'none';
        }
        setTimeout(function() {
            printWindow.focus();
            printWindow.print();
            printWindow.onafterprint = function() {
                printWindow.close();
            };
        }, 500);
    }

    /**
     * Render PDF pages as canvas elements for printing
     * This is a workaround to embed PDF content directly in print
     */
    function renderPdfToPrint(printWindow, pdfUrl, pdfjsLib, callback) {
        // Set PDF.js worker
        if (pdfjsLib && pdfjsLib.GlobalWorkerOptions) {
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        }

        var container = printWindow.document.getElementById('pdf-pages-container');
        var loadingDiv = printWindow.document.querySelector('.pdf-loading');
        
        if (!container) {
            if (callback) callback();
            return;
        }

        // Hide loading, show container
        if (loadingDiv) {
            loadingDiv.style.display = 'none';
        }

        // Load the PDF
        pdfjsLib.getDocument(pdfUrl).promise.then(function(pdf) {
            var totalPages = pdf.numPages;
            var pagesRendered = 0;

            // Render each page
            for (var pageNum = 1; pageNum <= totalPages; pageNum++) {
                pdf.getPage(pageNum).then(function(page) {
                    var viewport = page.getViewport({ scale: 1.5 }); // Scale for better quality
                    
                    // Create canvas
                    var canvas = printWindow.document.createElement('canvas');
                    canvas.className = 'pdf-page-canvas';
                    var context = canvas.getContext('2d');
                    canvas.height = viewport.height;
                    canvas.width = viewport.width;

                    // Render page to canvas
                    var renderContext = {
                        canvasContext: context,
                        viewport: viewport
                    };

                    page.render(renderContext).promise.then(function() {
                        container.appendChild(canvas);
                        pagesRendered++;

                        // When all pages are rendered, call callback
                        if (pagesRendered === totalPages && callback) {
                            callback();
                        }
                    }).catch(function(error) {
                        console.error('Error rendering PDF page:', error);
                        pagesRendered++;
                        if (pagesRendered === totalPages && callback) {
                            callback();
                        }
                    });
                }).catch(function(error) {
                    console.error('Error getting PDF page:', error);
                    pagesRendered++;
                    if (pagesRendered === totalPages && callback) {
                        callback();
                    }
                });
            }
        }).catch(function(error) {
            console.error('Error loading PDF:', error);
            // Fallback to link if PDF fails to load (could be CORS issue)
            showPdfFallback(printWindow, pdfUrl);
            if (callback) callback();
        });
    }

    /**
     * Get PDF URL from record if the database has a PDF column
     */
    function getRecordPdfUrl(record) {
        if (record && typeof record._pdf_url === 'string' && record._pdf_url.trim() !== '') {
            return record._pdf_url.trim();
        }

        console.log('getRecordPdfUrl called with record:', record);
        
        // Check if we have PDF column mappings
        if (!supabaseMultiSearch || !supabaseMultiSearch.tablePdfColumns) {
            console.log('No PDF column mappings available');
            return null;
        }

        // Get the source database name
        var sourceDatabase = record._source_database;
        if (!sourceDatabase) {
            console.log('No source database found in record');
            return null;
        }

        console.log('Source database:', sourceDatabase);
        console.log('Available PDF columns:', supabaseMultiSearch.tablePdfColumns);

        // Check if this database has a PDF column designated
        var pdfColumn = supabaseMultiSearch.tablePdfColumns[sourceDatabase];
        if (!pdfColumn) {
            console.log('No PDF column designated for database:', sourceDatabase);
            return null;
        }

        console.log('PDF column name:', pdfColumn);
        console.log('Record keys:', Object.keys(record));

        // Get the PDF URL from the record
        // Try exact match first, then case variations
        var pdfUrl = null;
        
        // Try exact match
        if (record.hasOwnProperty(pdfColumn)) {
            pdfUrl = record[pdfColumn];
            console.log('Found PDF URL (exact match):', pdfUrl);
        } else {
            // Try case-insensitive search
            for (var key in record) {
                if (record.hasOwnProperty(key) && key.toLowerCase() === pdfColumn.toLowerCase()) {
                    pdfUrl = record[key];
                    console.log('Found PDF URL (case-insensitive match, key:', key, '):', pdfUrl);
                    break;
                }
            }
        }

        // If we found a PDF URL, return it
        if (pdfUrl && typeof pdfUrl === 'string' && pdfUrl.trim() !== '') {
            var trimmedUrl = pdfUrl.trim();
            console.log('Returning PDF URL:', trimmedUrl);
            return trimmedUrl;
        }

        console.log('No valid PDF URL found');
        return null;
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

    /**
     * Open PDF in accessible modal
     */
    function openPdfModal(pdfUrl, triggerButton) {
        // Store the trigger button for focus return
        var $triggerButton = triggerButton;
        
        // Create modal if it doesn't exist
        var $modal = $('#pdf-viewer-modal');
        if ($modal.length === 0) {
            $modal = createPdfModal();
            $('body').append($modal);
        }

        // Store trigger button in modal data for later use
        $modal.data('trigger-button', $triggerButton);

        // Set PDF URL
        $modal.data('pdf-url', pdfUrl);
        
        // Show modal
        $modal.removeClass('pdf-modal-hidden').addClass('pdf-modal-visible');
        $('body').addClass('pdf-modal-open');

        // Set ARIA attributes
        $modal.attr('aria-hidden', 'false');

        // Focus on close button
        var $closeBtn = $modal.find('.pdf-modal-close');
        $closeBtn.focus();

        // Load PDF
        loadPdfInModal($modal, pdfUrl);

        // Trap focus within modal
        trapModalFocus($modal);
    }

    /**
     * Create PDF modal structure
     */
    function createPdfModal() {
        var modalHtml = '<div id="pdf-viewer-modal" class="pdf-modal pdf-modal-hidden" role="dialog" aria-modal="true" aria-labelledby="pdf-modal-title" aria-describedby="pdf-modal-description">' +
            '<div class="pdf-modal-overlay" aria-hidden="true"></div>' +
            '<div class="pdf-modal-container">' +
                '<div class="pdf-modal-header">' +
                    '<h2 id="pdf-modal-title" class="pdf-modal-title">PDF Document</h2>' +
                    '<button type="button" class="pdf-modal-close" aria-label="Close PDF viewer">' +
                        '<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>' +
                        '<span class="screen-reader-text">Close</span>' +
                    '</button>' +
                '</div>' +
                '<div class="pdf-modal-body" id="pdf-modal-description">' +
                    '<div class="pdf-loading-indicator">' +
                        '<span class="dashicons dashicons-update spin" aria-hidden="true"></span>' +
                        '<p>Loading PDF document...</p>' +
                    '</div>' +
                    '<div class="pdf-error-message" style="display: none;">' +
                        '<p class="pdf-error-text"></p>' +
                        '<div class="pdf-error-actions">' +
                            '<a href="#" class="pdf-download-link" target="_blank" rel="noopener">Download PDF</a>' +
                            '<a href="#" class="pdf-open-link" target="_blank" rel="noopener">Open in New Tab</a>' +
                        '</div>' +
                    '</div>' +
                    '<div class="pdf-iframe-fallback" style="display: none;">' +
                        '<div class="pdf-iframe-header">' +
                            '<p class="pdf-iframe-notice">PDF displayed in fallback mode (CORS restrictions may prevent full viewer features)</p>' +
                            '<a href="#" class="pdf-download-link" target="_blank" rel="noopener">Download PDF</a>' +
                        '</div>' +
                        '<iframe class="pdf-iframe" src="" frameborder="0" style="width: 100%; height: 80vh; border: 1px solid #ddd;"></iframe>' +
                    '</div>' +
                    '<div class="pdf-viewer-container" style="display: none;">' +
                        '<div class="pdf-controls">' +
                            '<button type="button" class="pdf-control-btn pdf-prev-page" aria-label="Previous page" disabled>' +
                                '<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>' +
                            '</button>' +
                            '<span class="pdf-page-info">Page <span class="pdf-current-page">1</span> of <span class="pdf-total-pages">1</span></span>' +
                            '<button type="button" class="pdf-control-btn pdf-next-page" aria-label="Next page" disabled>' +
                                '<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>' +
                            '</button>' +
                            '<a href="#" class="pdf-download-link" target="_blank" rel="noopener" aria-label="Download PDF">' +
                                '<span class="dashicons dashicons-download" aria-hidden="true"></span>' +
                                'Download' +
                            '</a>' +
                        '</div>' +
                        '<div class="pdf-canvas-container"></div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
        '</div>';

        var $modal = $(modalHtml);

        // Close button handler
        $modal.find('.pdf-modal-close, .pdf-modal-overlay').on('click', function(e) {
            e.preventDefault();
            closePdfModal($modal);
        });

        // Keyboard handlers
        $modal.on('keydown', function(e) {
            // Escape key closes modal
            if (e.key === 'Escape' || e.keyCode === 27) {
                e.preventDefault();
                closePdfModal($modal);
            }
        });

        // Prevent clicks inside modal from closing it
        $modal.find('.pdf-modal-container').on('click', function(e) {
            e.stopPropagation();
        });

        return $modal;
    }

    /**
     * Load PDF in modal using PDF.js
     */
    function loadPdfInModal($modal, pdfUrl) {
        var $loading = $modal.find('.pdf-loading-indicator');
        var $error = $modal.find('.pdf-error-message');
        var $viewer = $modal.find('.pdf-viewer-container');
        var $canvasContainer = $modal.find('.pdf-canvas-container');
        var $downloadLinks = $modal.find('.pdf-download-link');
        
        // Set download link href
        $downloadLinks.attr('href', pdfUrl);

        // Show loading, hide others
        $loading.show();
        $error.hide();
        $viewer.hide();
        $canvasContainer.empty();

        // Check if PDF.js is available
        if (typeof pdfjsLib === 'undefined') {
            console.error('PDF.js not loaded');
            showPdfError($modal, 'PDF viewer library not loaded. Please refresh the page and try again.');
            return;
        }

        // Set PDF.js worker
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        // Load PDF
        pdfjsLib.getDocument({
            url: pdfUrl,
            withCredentials: false
        }).promise.then(function(pdf) {
            console.log('PDF loaded, pages:', pdf.numPages);
            
            // Hide loading
            $loading.hide();
            $viewer.show();

            // Update page count
            $modal.find('.pdf-total-pages').text(pdf.numPages);
            $modal.data('pdf', pdf);
            $modal.data('current-page', 1);
            $modal.data('total-pages', pdf.numPages);

            // Enable/disable navigation buttons
            updatePdfNavigation($modal);

            // Render first page
            renderPdfPage($modal, 1);

        }).catch(function(error) {
            console.error('Error loading PDF:', error);
            
            // Check if this is a CORS/network error
            var errorMessage = error && error.message ? String(error.message) : '';
            var errorName = error && error.name ? String(error.name) : '';
            var errorDetails = error && error.details ? String(error.details) : '';
            
            var isCorsError = (
                errorMessage.indexOf('CORS') !== -1 ||
                errorMessage.indexOf('NetworkError') !== -1 ||
                errorMessage.indexOf('fetch') !== -1 ||
                errorMessage.indexOf('Cross-Origin') !== -1 ||
                errorName === 'UnknownErrorException' ||
                errorDetails.indexOf('NetworkError') !== -1 ||
                errorDetails.indexOf('CORS') !== -1
            );
            
            if (isCorsError) {
                // Try iframe fallback for CORS issues
                console.log('CORS error detected, trying iframe fallback');
                tryIframeFallback($modal, pdfUrl);
            } else {
                var errorMsg = errorMessage || 'Unknown error occurred while loading the PDF.';
                showPdfError($modal, 'Failed to load PDF: ' + errorMsg, pdfUrl);
            }
        });
    }

    /**
     * Render a specific PDF page
     */
    function renderPdfPage($modal, pageNum) {
        var pdf = $modal.data('pdf');
        if (!pdf) {
            return;
        }

        var $canvasContainer = $modal.find('.pdf-canvas-container');
        var $loading = $modal.find('.pdf-loading-indicator');
        
        // Show loading while rendering
        $loading.show();

        pdf.getPage(pageNum).then(function(page) {
            var viewport = page.getViewport({ scale: 1.5 });
            
            // Create canvas
            var canvas = document.createElement('canvas');
            var context = canvas.getContext('2d');
            canvas.height = viewport.height;
            canvas.width = viewport.width;
            canvas.className = 'pdf-page-canvas';
            canvas.setAttribute('role', 'img');
            canvas.setAttribute('aria-label', 'PDF page ' + pageNum);

            // Clear container and add canvas
            $canvasContainer.empty().append(canvas);

            // Render page
            var renderContext = {
                canvasContext: context,
                viewport: viewport
            };

            page.render(renderContext).promise.then(function() {
                $loading.hide();
                $modal.data('current-page', pageNum);
                $modal.find('.pdf-current-page').text(pageNum);
                updatePdfNavigation($modal);
                
                // Focus on canvas for screen readers
                canvas.focus();
            }).catch(function(error) {
                console.error('Error rendering PDF page:', error);
                $loading.hide();
                showPdfError($modal, 'Failed to render PDF page: ' + (error.message || 'Unknown error'));
            });
        }).catch(function(error) {
            console.error('Error getting PDF page:', error);
            $loading.hide();
            showPdfError($modal, 'Failed to load PDF page: ' + (error.message || 'Unknown error'));
        });
    }

    /**
     * Update PDF navigation buttons state
     */
    function updatePdfNavigation($modal) {
        var currentPage = $modal.data('current-page') || 1;
        var totalPages = $modal.data('total-pages') || 1;
        
        var $prevBtn = $modal.find('.pdf-prev-page');
        var $nextBtn = $modal.find('.pdf-next-page');

        $prevBtn.prop('disabled', currentPage <= 1);
        $nextBtn.prop('disabled', currentPage >= totalPages);

        // Add handlers for navigation
        $prevBtn.off('click').on('click', function(e) {
            e.preventDefault();
            if (currentPage > 1) {
                renderPdfPage($modal, currentPage - 1);
            }
        });

        $nextBtn.off('click').on('click', function(e) {
            e.preventDefault();
            if (currentPage < totalPages) {
                renderPdfPage($modal, currentPage + 1);
            }
        });
    }

    /**
     * Show PDF error message
     */
    function showPdfError($modal, message, pdfUrl) {
        var $loading = $modal.find('.pdf-loading-indicator');
        var $error = $modal.find('.pdf-error-message');
        var $viewer = $modal.find('.pdf-viewer-container');
        var $iframeFallback = $modal.find('.pdf-iframe-fallback');
        
        $loading.hide();
        $viewer.hide();
        $iframeFallback.hide();
        
        $error.find('.pdf-error-text').text(message);
        
        // Set download and open links
        if (pdfUrl) {
            $error.find('.pdf-download-link').attr('href', pdfUrl);
            $error.find('.pdf-open-link').attr('href', pdfUrl);
        }
        
        $error.show();
    }

    /**
     * Try iframe fallback for CORS-restricted PDFs
     */
    function tryIframeFallback($modal, pdfUrl) {
        var $loading = $modal.find('.pdf-loading-indicator');
        var $error = $modal.find('.pdf-error-message');
        var $viewer = $modal.find('.pdf-viewer-container');
        var $iframeFallback = $modal.find('.pdf-iframe-fallback');
        var $iframe = $modal.find('.pdf-iframe');
        var $downloadLink = $iframeFallback.find('.pdf-download-link');
        
        // Set download link
        $downloadLink.attr('href', pdfUrl);
        
        // Hide loading and other views
        $loading.hide();
        $viewer.hide();
        $error.hide();
        
        // Set iframe source
        $iframe.attr('src', pdfUrl);
        
        // Show iframe fallback
        $iframeFallback.show();
        
        // Handle iframe load errors
        $iframe.on('error', function() {
            console.error('Iframe also failed to load PDF');
            $iframeFallback.hide();
            showPdfError($modal, 
                'Unable to display PDF due to cross-origin restrictions. The PDF server does not allow embedding. Please use the download link below to view the PDF.',
                pdfUrl
            );
        });
        
        // Check if iframe loaded successfully after a delay
        setTimeout(function() {
            try {
                // Try to access iframe content (will fail if CORS blocks it)
                var iframeDoc = $iframe[0].contentDocument || $iframe[0].contentWindow.document;
                // If we can access it, it loaded successfully
                console.log('Iframe fallback loaded successfully');
            } catch (e) {
                // Can't access iframe content - might still be loading or CORS blocked
                // But the iframe might still display the PDF in some browsers
                console.log('Cannot access iframe content (expected with CORS), but PDF may still display');
            }
        }, 2000);
    }

    /**
     * Close PDF modal
     */
    function closePdfModal($modal) {
        $modal.removeClass('pdf-modal-visible').addClass('pdf-modal-hidden');
        $('body').removeClass('pdf-modal-open');
        $modal.attr('aria-hidden', 'true');
        
        // Clear PDF data
        $modal.data('pdf', null);
        $modal.data('current-page', null);
        $modal.data('total-pages', null);
        
        // Return focus to trigger button
        var $triggerButton = $modal.data('trigger-button');
        if ($triggerButton && $triggerButton.length) {
            $triggerButton.focus();
        }
        $modal.data('trigger-button', null);

        // Remove focus trap
        $(document).off('keydown.pdfModalFocusTrap');
    }

    /**
     * Trap focus within modal for accessibility
     */
    function trapModalFocus($modal) {
        var focusableElements = $modal.find(
            'a[href], button:not([disabled]), textarea:not([disabled]), ' +
            'input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
        ).filter(':visible');

        var firstElement = focusableElements.first();
        var lastElement = focusableElements.last();

        // Handle Tab key
        $(document).off('keydown.pdfModalFocusTrap').on('keydown.pdfModalFocusTrap', function(e) {
            if (!$modal.hasClass('pdf-modal-visible')) {
                return;
            }

            if (e.key === 'Tab' || e.keyCode === 9) {
                if (e.shiftKey) {
                    // Shift + Tab
                    if (document.activeElement === firstElement[0]) {
                        e.preventDefault();
                        lastElement.focus();
                    }
                } else {
                    // Tab
                    if (document.activeElement === lastElement[0]) {
                        e.preventDefault();
                        firstElement.focus();
                    }
                }
            }
        });
    }
});
