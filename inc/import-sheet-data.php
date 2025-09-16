<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Start the import process and schedule batched processing
 *
 * @return array Initial setup results
 */
function stp_process_import() {
    // Get settings
    $spreadsheet_id = get_option('sheets_to_pages_spreadsheet_id', '');
    $sheet_range = get_option('sheets_to_pages_sheet_range', 'Sheet1!A:C');
    $update_existing = get_option('sheets_to_pages_update_existing', false);
    
    if (empty($spreadsheet_id)) {
        throw new Exception(__('Spreadsheet ID is not configured.', 'sheets-to-pages'));
    }
    
    // Path to service account key file
    $service_account_key_path = ARTI_SEO_PAGE_PLUGIN_PATH . 'googlesheets/key.json';
    if (!file_exists($service_account_key_path)) {
        throw new Exception(__('Service account key file not found.', 'sheets-to-pages'));
    }
    
    try {
        // Set up Google client
        $client = new Google_Client();
        $client->setAuthConfig($service_account_key_path);
        $client->addScope(Google_Service_Sheets::SPREADSHEETS_READONLY);
        
        // Fetch data from Google Sheets
        $service = new Google_Service_Sheets($client);
        $response = $service->spreadsheets_values->get($spreadsheet_id, $sheet_range);
        $values = $response->getValues();
        
        if (empty($values)) {
            throw new Exception(__('No data found in Google Sheets.', 'sheets-to-pages'));
        }
        
        // Get header row and data rows
        $headers = $values[0];
        $data_rows = array_slice($values, 1);
        
        // Create a unique import ID for tracking this import job
        $import_id = 'stp_import_' . time();
        
        // Store the total count and initialize results in a transient
        $results = array(
            'total' => count($data_rows),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => array(),
            'completed' => false
        );
        set_transient($import_id, $results, DAY_IN_SECONDS);
        
        // Store the update_existing setting with the import job
        update_option('stp_import_' . $import_id . '_update_existing', $update_existing);
        
        // Split rows into batches of 20
        $batches = array_chunk($data_rows, 20);
        
        // Store all batches data to prevent large data in action args
        update_option('stp_import_' . $import_id . '_batches', $batches, false);
        
        // Check if Action Scheduler is available
        if (!function_exists('as_schedule_single_action')) {
            throw new Exception(__('Action Scheduler is not available. Please make sure it is properly loaded.', 'sheets-to-pages'));
        }
        
        // Schedule the first batch to run immediately
        $scheduled = as_schedule_single_action(
            time(), 
            'stp_process_import_batch', 
            array(
                'import_id' => $import_id,
                'batch_index' => 0
            ),
            'sheets-to-pages'
        );
        
        if (!$scheduled) {
            throw new Exception(__('Failed to schedule the import batch.', 'sheets-to-pages'));
        }
        
        return array(
            'import_id' => $import_id,
            'total_rows' => count($data_rows),
            'total_batches' => count($batches),
            'message' => sprintf(
                __('Import started. Processing %d rows in %d batches.', 'sheets-to-pages'),
                count($data_rows),
                count($batches)
            )
        );
        
    } catch (Exception $e) {
        error_log('Sheets to Pages Import Error: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Process a batch of import rows
 *
 * @param string $import_id Unique ID for this import job
 * @param int $batch_index Current batch index
 */
function stp_process_import_batch($import_id, $batch_index) {
    try {
        // Get current results
        $results = get_transient($import_id);
        if (false === $results) {
            error_log("Import job $import_id not found or expired");
            return;
        }
        
        // Get all batches from options
        $batches = get_option('stp_import_' . $import_id . '_batches', array());
        if (empty($batches) || !isset($batches[$batch_index])) {
            error_log("Batch data not found for import $import_id, batch $batch_index");
            return;
        }
        
        // Get the current batch
        $current_batch = $batches[$batch_index];
        
        // Get the update_existing setting
        $update_existing = get_option('stp_import_' . $import_id . '_update_existing', false);
        
        // Process each row in the batch
        foreach ($current_batch as $row_index => $row) {
            try {
                // Make sure row is fully populated
                $row = array_pad($row, 3, ''); // Ensure we have at least 3 columns
                
                $row_result = stp_process_row($row, $update_existing);
                
                if ($row_result['status'] === 'created') {
                    $results['created']++;
                } elseif ($row_result['status'] === 'updated') {
                    $results['updated']++;
                } elseif ($row_result['status'] === 'skipped') {
                    $results['skipped']++;
                }
                
                // Calculate the actual row number in the spreadsheet (accounting for header and 0-indexing)
                $actual_row_num = ($batch_index * 20) + $row_index + 2;
                
                $results['details'][] = array(
                    'row' => $actual_row_num,
                    'title' => isset($row[0]) ? sanitize_text_field($row[0]) : 'Unknown',
                    'status' => $row_result['status'],
                    'message' => $row_result['message'],
                    'page_id' => isset($row_result['page_id']) ? $row_result['page_id'] : null,
                );
            } catch (Exception $e) {
                $results['errors']++;
                
                // Calculate the actual row number
                $actual_row_num = ($batch_index * 20) + $row_index + 2;
                
                $results['details'][] = array(
                    'row' => $actual_row_num,
                    'title' => isset($row[0]) ? sanitize_text_field($row[0]) : 'Unknown',
                    'status' => 'error',
                    'message' => $e->getMessage(),
                );
                
                error_log("Error processing row $actual_row_num: " . $e->getMessage());
            }
        }
        
        // Update the transient with latest results
        set_transient($import_id, $results, DAY_IN_SECONDS);
        
        // Check if there are more batches to process
        $next_batch_index = $batch_index + 1;
        if ($next_batch_index < count($batches)) {
            // Schedule the next batch (with a small delay to prevent server overload)
            as_schedule_single_action(
                time() + 10, // 5 second delay between batches
                'stp_process_import_batch',
                array(
                    'import_id' => $import_id,
                    'batch_index' => $next_batch_index
                ),
                'sheets-to-pages'
            );
        } else {
            // This was the last batch, mark the import as completed
            $results['completed'] = true;
            set_transient($import_id, $results, DAY_IN_SECONDS);
            
            // Clean up temporary options
            delete_option('stp_import_' . $import_id . '_batches');
            delete_option('stp_import_' . $import_id . '_update_existing');
            
            // Trigger a completion action that other functions can hook into
            do_action('stp_import_completed', $import_id, $results);
            
            error_log("Import job $import_id completed successfully. Created: {$results['created']}, Updated: {$results['updated']}, Skipped: {$results['skipped']}, Errors: {$results['errors']}");
        }
    } catch (Exception $e) {
        error_log("Fatal error in batch processing for import $import_id, batch $batch_index: " . $e->getMessage());
    }
}

/**
 * Get the current status of an import job
 *
 * @param string $import_id The unique import ID
 * @return array|false Import results or false if not found
 */
function stp_get_import_status($import_id) {
    return get_transient($import_id);
}

/**
 * Register the Action Scheduler hooks
 */
function stp_register_action_scheduler_hooks() {
    add_action('stp_process_import_batch', 'stp_process_import_batch', 10, 3);
}
add_action('init', 'stp_register_action_scheduler_hooks');