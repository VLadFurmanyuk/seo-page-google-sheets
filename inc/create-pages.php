<?php
if (!defined('ABSPATH')) {
    exit;
}


/**
 * Process a single row of data
 *
 * @param array $row The row data
 * @param bool $update_existing Whether to update existing pages
 * @return array Processing result
 */
function stp_process_row($row, $update_existing = false) {
    error_log('Starting processing row: ' . print_r($row, true));
  
    if (count($row) < 1) {
        return array(
            'status' => 'skipped',
            'message' => __('Insufficient data (need at least title column)', 'sheets-to-pages'),
        );
    }
  
    // Use sanitize_text_field for title as it should not contain HTML
    $title = sanitize_text_field($row[5]);
    
    // Check if title is empty
    if (empty($title)) {
        return array(
            'status' => 'skipped',
            'message' => __('Empty title', 'sheets-to-pages'),
        );
    }
  
    // Extract SEO data from row
    $seo_title = isset($row[1]) ? sanitize_text_field($row[1]) : '';
    $seo_keywords = isset($row[2]) ? sanitize_text_field($row[2]) : '';
    $seo_description = isset($row[3]) ? sanitize_text_field($row[3]) : '';
    
    error_log('Шукаємо назву: ' . $title);
  
    global $wpdb;
    
    $title_encoded = htmlentities($title, ENT_QUOTES, 'UTF-8');
    
    error_log('Шукаємо також закодовану назву: ' . $title_encoded);
    
    $sql = $wpdb->prepare(
        "SELECT ID FROM $wpdb->posts 
        WHERE (post_title = %s OR post_title = %s) 
        AND post_type = %s 
        AND post_status = 'publish'
        LIMIT 1",
        $title,
        $title_encoded,
        'seo_page'
    );
    
    error_log('SQL request: ' . $sql);
    
    $existing_page_id = $wpdb->get_var($sql);
    
    error_log('ID page: ' . ($existing_page_id ? $existing_page_id : 'не знайдено'));
  
    // Get block configuration
    $reusable_blocks = get_option('sheets_to_pages_block_config', array());
    
    // Sort blocks by order
    usort($reusable_blocks, function($a, $b) {
        return $a['order'] - $b['order'];
    });
  
    // Filter out disabled blocks
    $reusable_blocks = array_filter($reusable_blocks, function($block) {
        return isset($block['enabled']) && $block['enabled'];
    });
    
    // Create content for the page
    $content = '';
    
    $block_count = 0;
  
    // Process each reusable block
    foreach ($reusable_blocks as $block_config) {
        $block_count++;
        error_log('==== PROCESSING BLOCK #' . $block_count . ' ====');
        error_log('Block config: ' . print_r($block_config, true));
        error_log('Total content length before processing this block: ' . strlen($content) . ' bytes');
  
        if (!isset($block_config['block_id']) || empty($block_config['block_id'])) {
            error_log('Skipping block - missing block_id');
            continue;
        }
        
        $reusable_block_id = $block_config['block_id'];
        error_log('Reusable block ID: ' . $reusable_block_id);
        
        $reusable_block = get_post($reusable_block_id);
        
        if (!$reusable_block) {
            error_log('Error: Block not found with ID: ' . $reusable_block_id);
            continue;
        }
        
        if ($reusable_block->post_type !== 'wp_block') {
            error_log('Error: Post is not a reusable block. Type: ' . $reusable_block->post_type);
            continue;
        }
        
        error_log('Found reusable block: "' . $reusable_block->post_title . '"');
  
        // Get a fresh copy of the block content each time
        $block_content = $reusable_block->post_content;
        error_log('Original block content length: ' . strlen($block_content) . ' bytes');
        // error_log('Original block content preview: ' . substr($block_content, 0, 100) . '...');
  
        // Process fields if any are defined
        if (!empty($block_config['fields'])) {
            error_log('Processing ' . count($block_config['fields']) . ' fields for this block');
            
            foreach ($block_config['fields'] as $field_index => $field) {
                error_log('Field #' . $field_index . ': ' . print_r($field, true));
  
                if (!isset($field['field_id']) || !isset($field['column_index']) || 
                    !isset($row[$field['column_index']])) {
                    error_log('Skipping field - missing required data');
                    continue;
                }
  
                $field_id = $field['field_id'];
                $value = $row[$field['column_index']];
                
                // Determine if this is a repeater field
                $is_repeater = isset($field['is_repeater']) && $field['is_repeater'];
                error_log(sprintf(
                    'Updating field: %s, value: %s, is_repeater: %s', 
                    $field_id, 
                    is_string($value) ? $value : print_r($value, true), 
                    $is_repeater ? 'true' : 'false'
                ));
  
                // Handle image fields
                if (isset($field['is_image']) && $field['is_image'] && 
                    filter_var($value, FILTER_VALIDATE_URL)) {
                    error_log('Processing image URL: ' . $value);
                    
                    $attachment_id = stp_upload_image_from_url($value);
                    if (!is_wp_error($attachment_id)) {
                        $value = $attachment_id;
                        error_log('Converted image URL to attachment ID: ' . $attachment_id);
                    } else {
                        error_log('Failed to process image: ' . $attachment_id->get_error_message());
                        continue;
                    }
                } else if (is_string($value)) {
                    $value = wp_kses_post($value);
                    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    error_log('Processed text value: ' . $value);
                }
                
                // Store the content before field update for comparison
                $block_content_before = $block_content;
                
                // Update block content with proper handling of repeater fields
                $block_content = update_block_field($block_content, $field_id, $value, $is_repeater);
                
                // Check if the content was actually updated
                if ($block_content !== $block_content_before) {
                    error_log('Field "' . $field_id . '" was updated successfully');
                    error_log('Content length change: ' . (strlen($block_content) - strlen($block_content_before)) . ' bytes');
                } else {
                    error_log('No changes in block content for field "' . $field_id . '"');
                }
            }
        } else {
            error_log('No fields to process for this block');
        }
        
        error_log('Block content before wrapper removal length: ' . strlen($block_content) . ' bytes');
        
        // Remove reusable block wrapper
        $original_length = strlen($block_content);
        $block_content = preg_replace(
            '/<!-- wp:block {"ref":' . $reusable_block_id . '} -->(.*)<!-- \/wp:block -->/s',
            '$1',
            $block_content
        );
        $new_length = strlen($block_content);
        
        error_log('Wrapper removal - bytes removed: ' . ($original_length - $new_length));
        
        // Apply wp_slash and trim before adding to content
        $processed_block = wp_slash(trim($block_content));
        error_log('Final processed block length: ' . strlen($processed_block) . ' bytes');
        
        // Store the current content length
        $content_before_length = strlen($content);
        
        // Add to the final content with separator
        $content .= $processed_block . "\n\n";
        
        // Log the new content length
        $content_after_length = strlen($content);
        error_log('Content length increased by: ' . ($content_after_length - $content_before_length) . ' bytes');
        error_log('==== FINISHED PROCESSING BLOCK #' . $block_count . ' ====');
        error_log('Total content length is now: ' . strlen($content) . ' bytes');
    }
  
    error_log('Final page content length: ' . strlen($content) . ' bytes');
    // error_log('Final page content preview: ' . substr($content, 0, 150) . '...');
  
    // Actual processing
    if ($existing_page_id && $update_existing) {
        // Update existing page
        $page_data = array(
            'ID' => $existing_page_id,
            'post_content' => $content,
        );
        
        $page_id = wp_update_post($page_data);
        stp_update_seo_post_meta($page_id, $seo_title, $seo_keywords, $seo_description);
        stp_update_post_taxonomy($page_id, $row);
  
        if (is_wp_error($page_id)) {
            return array(
                'status' => 'error',
                'message' => $page_id->get_error_message(),
            );
        }
        
        return array(
            'status' => 'updated',
            'message' => __('Page updated successfully with reusable blocks converted', 'sheets-to-pages'),
            'page_id' => $page_id,
        );
    } elseif ($existing_page_id) {
        // Skip existing page
        return array(
            'status' => 'skipped',
            'message' => __('Page already exists', 'sheets-to-pages'),
            'page_id' => $existing_page_id,
        );
    } else {
        // Create new page
        $page_data = array(
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => 'publish',
            'post_type' => 'seo_page',
        );
        
        $page_id = wp_insert_post($page_data);
        stp_update_seo_post_meta($page_id, $seo_title, $seo_keywords, $seo_description);
        stp_update_post_taxonomy($page_id, $row);
        
        if (is_wp_error($page_id)) {
            return array(
                'status' => 'error',
                'message' => $page_id->get_error_message(),
            );
        }
        
        return array(
            'status' => 'created',
            'message' => __('Page created successfully with reusable blocks converted', 'sheets-to-pages'),
            'page_id' => $page_id,
        );
    }
  }

function update_block_field($block_content, $field_id, $value, $is_repeater = false) {
    if (empty($field_id)) {
        return $block_content;
    }

    // Log input parameters
    error_log("Updating field: " . $field_id . ", Value: " . (is_string($value) ? $value : json_encode($value)));
    
    // Parse repeater field pattern (field_group_0_subfield)
    $repeater_pattern = '/^([a-zA-Z0-9_]+)_(\d+)_([a-zA-Z0-9_]+)$/';
    $is_repeater_field = preg_match($repeater_pattern, $field_id, $matches);

    // If it matches repeater pattern, extract components
    if ($is_repeater_field) {
        $group_name = $matches[1];
        $index = (int)$matches[2];
        $subfield = $matches[3];
        $is_image = ($subfield === 'image');
    } else {
        $is_image = (strpos($field_id, 'image') !== false);
    }

    // APPROACH 1: Full JSON Block Parsing - most reliable method
    if (preg_match_all('/({"name":"[^"]+","data":{[^}]*}})/', $block_content, $json_matches)) {
        foreach ($json_matches[1] as $full_json) {
            $block_data = json_decode($full_json, true);
            
            if (json_last_error() === JSON_ERROR_NONE && isset($block_data['data'])) {
                $original_json = $full_json; // Save original for comparison
                $data = &$block_data['data'];
                $modified = false;
                
                // Handle repeater fields
                if ($is_repeater_field) {
                    if (isset($data[$group_name]) && is_array($data[$group_name]) && isset($data[$group_name][$index])) {
                        // Only update if the specific index and subfield exists
                        if (array_key_exists($subfield, $data[$group_name][$index])) {
                            $data[$group_name][$index][$subfield] = $value;
                            $modified = true;
                            
                            // Update image URL if applicable
                            if ($is_image && is_numeric($value)) {
                                $attachment_url = wp_get_attachment_url($value);
                                if ($attachment_url) {
                                    $data[$group_name][$index][$subfield . '_url'] = $attachment_url;
                                }
                            }
                        }
                    }
                } else {
                    // Regular field - only update if the exact key exists at the top level
                    if (array_key_exists($field_id, $data)) {
                        $data[$field_id] = $value;
                        $modified = true;
                        
                        // Update image URL if applicable
                        if ($is_image && is_numeric($value)) {
                            $attachment_url = wp_get_attachment_url($value);
                            if ($attachment_url) {
                                $data[$field_id . '_url'] = $attachment_url;
                            }
                        }
                    }
                }
                
                if ($modified) {
                    // Re-encode to JSON with specific options to match WordPress format
                    $updated_json = wp_json_encode($block_data, 
                        JSON_UNESCAPED_UNICODE | 
                        JSON_UNESCAPED_SLASHES | 
                        JSON_HEX_QUOT | 
                        JSON_HEX_TAG | 
                        JSON_HEX_AMP | 
                        JSON_HEX_APOS
                    );
                    
                    // Replace only this specific JSON block
                    $block_content = str_replace($original_json, $updated_json, $block_content);
                    error_log("Field updated successfully via JSON parsing");
                }
            }
        }
        
        return $block_content; // Return after processing all JSON blocks
    }
    
    // APPROACH 2: Direct attribute JSON path for fields
    // This approach targets specific JSON paths rather than simple string replacement
    if ($is_repeater_field) {
        $path_pattern = '"' . preg_quote($group_name, '/') . '":\s*\[\s*(?:[^]]*,\s*){' . $index . '}\s*{\s*(?:[^}]*,\s*)*"' . 
                       preg_quote($subfield, '/') . '"\s*:\s*(["\[].*?["\]])';
    } else {
        $path_pattern = '"' . preg_quote($field_id, '/') . '"\s*:\s*(["\[].*?["\]])';
    }
    
    if (preg_match('/' . $path_pattern . '/', $block_content, $matches)) {
        $current_value = $matches[1];
        $value_is_string = substr($current_value, 0, 1) === '"';
        
        if ($value_is_string) {
            // Handle string replacement
            $escaped_value = wp_json_encode($value);
            $new_content = preg_replace('/' . preg_quote($current_value, '/') . '/', $escaped_value, $block_content, 1);
            
            if ($new_content !== $block_content) {
                error_log("Field updated successfully via direct JSON path (string)");
                
                // For image fields, also update the URL
                if ($is_image && is_numeric($value)) {
                    $attachment_url = wp_get_attachment_url($value);
                    if ($attachment_url) {
                        if ($is_repeater_field) {
                            $url_path = '"' . $group_name . '":\s*\[\s*(?:[^]]*,\s*){' . $index . '}\s*{\s*(?:[^}]*,\s*)*"' . 
                                       $subfield . '_url"\s*:\s*(".*?")';
                        } else {
                            $url_path = '"' . $field_id . '_url"\s*:\s*(".*?")';
                        }
                        
                        if (preg_match('/' . $url_path . '/', $new_content, $url_matches)) {
                            $escaped_url = wp_json_encode(esc_url_raw($attachment_url));
                            $new_content = preg_replace('/' . preg_quote($url_matches[1], '/') . '/', $escaped_url, $new_content, 1);
                        }
                    }
                }
                
                return $new_content;
            }
        } else if (substr($current_value, 0, 1) === '[') {
            // This is an array/object value, don't attempt basic replacement
            error_log("Skipping direct replacement for array/object value");
        }
    }
    
    // If all approaches failed, return the original content
    error_log("No field update was performed for: " . $field_id);
    return $block_content;
}

/**
* Helper function to download an image from URL and upload to WordPress media library
* 
* @param string $image_url The URL of the image to download
* @param string $title The title to use for the attachment
* @return int|WP_Error Attachment ID on success, WP_Error on failure
*/
function stp_upload_image_from_url($image_url, $title = '') {
  // Require WordPress media handling files
  require_once(ABSPATH . 'wp-admin/includes/media.php');
  require_once(ABSPATH . 'wp-admin/includes/file.php');
  require_once(ABSPATH . 'wp-admin/includes/image.php');
  
  // Download file to temp dir
  $temp_file = download_url($image_url);
  
  if (is_wp_error($temp_file)) {
      return $temp_file;
  }
  
  // Get the filename and extension
  $filename = basename($image_url);
  
  // Create file array for media_handle_sideload
  $file_array = array(
      'name'     => sanitize_file_name($filename),
      'tmp_name' => $temp_file
  );
  
  // Check file type
  $file_type = wp_check_filetype($filename, null);
  if (empty($file_type['ext'])) {
      // If no extension, try to determine from content
      $file_info = getimagesize($temp_file);
      if ($file_info) {
          $mime = $file_info['mime'];
          if ($mime == 'image/jpeg') $file_array['name'] .= '.jpg';
          elseif ($mime == 'image/png') $file_array['name'] .= '.png';
          elseif ($mime == 'image/gif') $file_array['name'] .= '.gif';
          elseif ($mime == 'image/webp') $file_array['name'] .= '.webp';
      }
  }
  
  // Set post data for attachment
  $post_data = array();
  if (!empty($title)) {
      $post_data['post_title'] = $title;
  }
  
  // Do the upload and return attachment ID or error
  $attachment_id = media_handle_sideload($file_array, 0, '', $post_data);
  
  // Clean up temp file if it still exists
  if (file_exists($temp_file)) {
      @unlink($temp_file);
  }
  
  return $attachment_id;
}

function stp_update_seo_post_meta($page_id, $seo_title, $seo_keywords, $seo_description) {
  if (!empty($seo_title)) {
    update_post_meta($page_id, '_yoast_wpseo_title', $seo_title);
  }
  if (!empty($seo_keywords)) {
      update_post_meta($page_id, '_yoast_wpseo_focuskw', $seo_keywords);
  }
  if (!empty($seo_description)) {
      update_post_meta($page_id, '_yoast_wpseo_metadesc', $seo_description);
  }
}

/**
 * Update taxonomy terms for a post based on row data
 *
 * @param int $post_id The ID of the post to update
 * @param array $row The row data containing taxonomy information
 * @return void
 */
function stp_update_post_taxonomy($post_id, $row) {
    // Check if we have taxonomy data in $row[4]
    if (!isset($row[4]) || empty($row[4])) {
        error_log('No role data found in row[4]');
        return;
    }

    $role_name = sanitize_text_field($row[4]);
    error_log('Processing role: ' . $role_name);

    // Check if taxonomy exists
    if (!taxonomy_exists('roles')) {
        error_log('Taxonomy "roles" does not exist');
        return;
    }

    // Check if the term already exists
    $term = get_term_by('name', $role_name, 'roles');
    
    if (!$term) {
        // Term doesn't exist, create it
        $result = wp_insert_term($role_name, 'roles');
        
        if (is_wp_error($result)) {
            error_log('Error creating term: ' . $result->get_error_message());
            return;
        }
        
        $term_id = $result['term_id'];
        error_log('Created new term with ID: ' . $term_id);
    } else {
        $term_id = $term->term_id;
        error_log('Found existing term with ID: ' . $term_id);
    }
    
    // Assign the term to the post
    $result = wp_set_object_terms($post_id, $term_id, 'roles', false);
    
    if (is_wp_error($result)) {
        error_log('Error assigning term to post: ' . $result->get_error_message());
    } else {
        error_log('Successfully assigned role term to post');
    }
}