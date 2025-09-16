jQuery(document).ready(function($) {
  // Only run on the import page
  if (!$('#stp-import-progress-container').length) {
      return;
  }

  const progressBar = $('#stp-progress-bar');
  const progressText = $('#stp-progress-text');
  const resultsContainer = $('#stp-import-results');
  let checkInterval;
  let isImportRunning = false;

  // Start progress tracking when the import starts
  $('#stp-import-form').on('submit', function() {
      startProgressTracking();
      return true; // Allow the form to submit
  });

  function startProgressTracking() {
      isImportRunning = true;
      resultsContainer.html('<div class="notice notice-info"><p>' + 
          stp_ajax_vars.import_started_message + '</p></div>');
      
      progressBar.css('width', '0%');
      progressText.text('0%');
      $('#stp-import-progress-container').show();

      // Check progress every 3 seconds
      checkInterval = setInterval(checkImportProgress, 3000);
      
      // Also check immediately
      checkImportProgress();
  }

  function checkImportProgress() {
      $.ajax({
          url: stp_ajax_vars.ajax_url,
          type: 'POST',
          data: {
              action: 'stp_check_import_progress',
              nonce: stp_ajax_vars.nonce
          },
          success: function(response) {
              if (response.success) {
                  updateProgressUI(response.data);
                  
                  // If import is complete, stop checking
                  if (response.data.is_complete) {
                      stopProgressTracking();
                      displayImportResults(response.data.results);
                  }
              } else {
                  console.error('Error checking import progress:', response);
              }
          },
          error: function(xhr, status, error) {
              console.error('AJAX error:', error);
          }
      });
  }

  function updateProgressUI(progressData) {
      const percentage = progressData.percentage;
      progressBar.css('width', percentage + '%');
      progressText.text(percentage + '%');
      
      $('#stp-progress-details').html(
          'Processing batch ' + progressData.processed_batches + 
          ' of ' + progressData.total_batches
      );
  }

  function stopProgressTracking() {
      clearInterval(checkInterval);
      isImportRunning = false;
  }

  function displayImportResults(results) {
      // Count result types
      const counts = {
          created: 0,
          updated: 0,
          skipped: 0,
          error: 0
      };
      
      results.forEach(result => {
          if (counts.hasOwnProperty(result.status)) {
              counts[result.status]++;
          }
      });
      
      // Create summary table
      let html = '<div class="import-summary">';
      html += '<h3>' + stp_ajax_vars.import_complete_message + '</h3>';
      html += '<p>' + stp_ajax_vars.total_processed_message + ' ' + results.length + '</p>';
      html += '<table class="wp-list-table widefat fixed striped">';
      html += '<thead><tr>';
      html += '<th>' + stp_ajax_vars.title_label + '</th>';
      html += '<th>' + stp_ajax_vars.status_label + '</th>';
      html += '<th>' + stp_ajax_vars.message_label + '</th>';
      html += '<th>' + stp_ajax_vars.actions_label + '</th>';
      html += '</tr></thead><tbody>';
      
      results.forEach(result => {
          const statusClass = 'status-' + result.status;
          html += '<tr class="' + statusClass + '">';
          html += '<td>' + (result.title || 'N/A') + '</td>';
          html += '<td>' + result.status + '</td>';
          html += '<td>' + result.message + '</td>';
          html += '<td>';
          
          if (result.page_id) {
              html += '<a href="' + stp_ajax_vars.admin_url + 'post.php?post=' + 
                  result.page_id + '&action=edit" target="_blank" class="button button-small">' + 
                  stp_ajax_vars.edit_label + '</a> ';
              html += '<a href="' + stp_ajax_vars.site_url + '?p=' + 
                  result.page_id + '" target="_blank" class="button button-small">' + 
                  stp_ajax_vars.view_label + '</a>';
          }
          
          html += '</td></tr>';
      });
      
      html += '</tbody></table></div>';
      
      resultsContainer.html(html);
  }

  // Handle window beforeunload event if import is in progress
  $(window).on('beforeunload', function() {
      if (isImportRunning) {
          return stp_ajax_vars.leave_warning;
      }
  });
});


jQuery(document).ready(function($) {
  // Handle adding new block
  $('.add-block').on('click', function() {
    var blockCount = $('.block-config-row').length;
    var template = $('.block-config-row:last').clone();

    // Update IDs and names
    template.find('select, input').each(function() {
      var name = $(this).attr('name');
      if (name) {
        name = name.replace(/\[\d+\]/, '[' + blockCount + ']');
        $(this).attr('name', name);
      }
    });

    // Clear values
    template.find('select[name^="block_id"]').val('');
    template.find('input[name^="block_order"]').val(blockCount + 1);
    template.find('.field-container').html('');

    // Append to container
    $('#block-container').append(template);

    // Initialize new selects
    template.find('select').trigger('change');
  });

  // Handle adding new field
  $(document).on('click', '.add-field', function() {
    var blockIndex = $(this).data('block-index');
    var fieldContainer = $(this).closest('.block-config-row').find('.field-container');
    var fieldCount = fieldContainer.find('.field-row').length;

    var template = `
      <div class="field-row">
        <input type="text" name="field_id[${blockIndex}][${fieldCount}]" placeholder="Field ID" class="regular-text">
        <input type="number" name="column_index[${blockIndex}][${fieldCount}]" placeholder="Column Index" class="small-text">
        <label class="checkbox-label">
          <input type="checkbox" name="is_image[${blockIndex}][${fieldCount}]" value="1">
          <?php echo esc_js(__('Is Image', 'sheets-to-pages')); ?>
        </label>
        <label class="checkbox-label">
          <input type="checkbox" name="is_repeater[${blockIndex}][${fieldCount}]" value="1">
          <?php echo esc_js(__('Is Repeater', 'sheets-to-pages')); ?>
        </label>
        <button type="button" class="button remove-field"><?php echo esc_js(__('Remove', 'sheets-to-pages')); ?></button>
      </div>
    `;

    fieldContainer.append(template);
  });

  // Handle removing field
  $(document).on('click', '.remove-field', function() {
    $(this).closest('.field-row').remove();
  });

  // Handle removing block
  $(document).on('click', '.remove-block', function() {
    $(this).closest('.block-config-row').remove();

    // Update orders
    $('.block-config-row').each(function(index) {
      $(this).find('input[name^="block_order"]').val(index + 1);
    });
  });

  // Handle block selection change
  $(document).on('change', 'select[name^="block_id"]', function() {
    var blockId = $(this).val();
    var blockRow = $(this).closest('.block-config-row');

    if (blockId) {
      blockRow.find('.field-controls').show();
    } else {
      blockRow.find('.field-controls').hide();
    }
  });
});