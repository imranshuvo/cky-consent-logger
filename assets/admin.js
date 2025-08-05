// assets/admin.js
jQuery(document).ready(function($) {
    // Modal functionality
    const modal = $('#ccl-details-modal');
    const modalContent = $('#ccl-details-content');
    const closeBtn = $('.ccl-close');
    
    // Open modal when clicking view details
    $('.ccl-view-details').on('click', function(e) {
        e.preventDefault();
        const consentId = $(this).data('consent-id');
        
        // Show loading
        modalContent.html('<p>Loading...</p>');
        modal.show();
        
        // Fetch details via AJAX
        $.ajax({
            url: ccl_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ccl_get_consent_details',
                consent_id: consentId,
                nonce: ccl_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    modalContent.html(response.data.content);
                } else {
                    modalContent.html('<p>Error: ' + response.data.message + '</p>');
                }
            },
            error: function() {
                modalContent.html('<p>Error loading consent details.</p>');
            }
        });
    });
    
    // Close modal
    closeBtn.on('click', function() {
        modal.hide();
    });
    
    // Close modal when clicking outside
    $(window).on('click', function(event) {
        if (event.target === modal[0]) {
            modal.hide();
        }
    });
    
    // Handle keyboard navigation
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && modal.is(':visible')) {
            modal.hide();
        }
    });
    
    // Search form enhancements
    $('.ccl-search-box input[type="text"]').on('keypress', function(e) {
        if (e.which === 13) { // Enter key
            $(this).closest('form').submit();
        }
    });
    
    // Auto-submit on per_page change (already handled in PHP with onchange)
    
    // Tooltip for truncated consent IDs
    $('.ccl-consent-id').on('click', function() {
        const fullId = $(this).attr('title');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(fullId).then(function() {
                // Show temporary feedback
                const $this = $(this);
                const originalText = $this.text();
                $this.text('Copied!').css('color', '#00a32a');
                setTimeout(function() {
                    $this.text(originalText).css('color', '');
                }, 1000);
            }.bind(this));
        } else {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = fullId;
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            try {
                document.execCommand('copy');
                const $this = $(this);
                const originalText = $this.text();
                $this.text('Copied!').css('color', '#00a32a');
                setTimeout(function() {
                    $this.text(originalText).css('color', '');
                }, 1000);
            } catch (err) {
                console.error('Failed to copy: ', err);
            }
            document.body.removeChild(textArea);
        }
    });
    
    // Add click cursor to consent IDs
    $('.ccl-consent-id').css('cursor', 'pointer').attr('title', function() {
        return $(this).attr('title') + ' (Click to copy)';
    });
    
    // Handle PDF download errors
    $('.ccl-download-pdf').on('click', function(e) {
        const $btn = $(this);
        $btn.text('Generating...').prop('disabled', true);
        
        // Re-enable after delay (PDF generation should be quick)
        setTimeout(function() {
            $btn.text('Download PDF').prop('disabled', false);
        }, 3000);
    });
    
    // Add loading state to search
    $('.ccl-search-form').on('submit', function() {
        const $btn = $(this).find('button[type="submit"]');
        const originalText = $btn.text();
        $btn.text('Searching...').prop('disabled', true);
        
        // This will be reset when page loads
    });
    
    // Highlight search terms in results
    const urlParams = new URLSearchParams(window.location.search);
    const searchTerm = urlParams.get('search');
    if (searchTerm && searchTerm.trim()) {
        highlightSearchTerms(searchTerm.trim());
    }
    
    function highlightSearchTerms(term) {
        const regex = new RegExp(`(${escapeRegExp(term)})`, 'gi');
        $('.ccl-logs-table tbody td').each(function() {
            const $cell = $(this);
            if ($cell.find('a, button').length === 0) { // Skip cells with links/buttons
                const html = $cell.html();
                const highlighted = html.replace(regex, '<mark>$1</mark>');
                $cell.html(highlighted);
            }
        });
    }
    
    function escapeRegExp(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
});