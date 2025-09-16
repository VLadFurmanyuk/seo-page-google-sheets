<?php
if (!defined('ABSPATH')) {
    exit;
}


/**
 * Register plugin settings
 */
add_action('admin_init', 'stp_register_settings');

function stp_register_settings() {
    // Main settings group
    register_setting(
        'sheets_to_pages_main_settings', // Option group
        'sheets_to_pages_spreadsheet_id',
        array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        )
    );
    
    register_setting(
        'sheets_to_pages_main_settings',
        'sheets_to_pages_sheet_range',
        array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        )
    );
    
    register_setting(
        'sheets_to_pages_main_settings',
        'sheets_to_pages_update_existing',
        array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
        )
    );
    
    // Separate group for block config
    register_setting(
        'sheets_to_pages_blocks_settings', // Different option group
        'sheets_to_pages_block_config',
        array(
            'type' => 'array',
            'sanitize_callback' => 'stp_sanitize_block_config'
        )
    );
}

/**
 * Register admin menu pages
 */
function stp_register_admin_menu() {
    add_menu_page(
        'Import from Google Sheets',
        'Sheets Import',
        'manage_options',
        'sheets-to-pages',
        'stp_render_admin_page',
        'dashicons-google',
        30
    );
    
    add_submenu_page(
        'sheets-to-pages',
        'Settings',
        'Settings',
        'manage_options',
        'sheets-to-pages-settings',
        'stp_render_settings_page'
    );
    
    add_submenu_page(
        'sheets-to-pages',
        'Block Configuration',
        'Block Configuration',
        'manage_options',
        'sheets-to-pages-blocks',
        'stp_render_blocks_page'
    );
}
add_action('admin_menu', 'stp_register_admin_menu');



/**
 * Render the main admin page
 */
function stp_render_admin_page() {
    $spreadsheet_id = get_option('sheets_to_pages_spreadsheet_id', '');
    $sheet_range = get_option('sheets_to_pages_sheet_range', 'Sheet1!A:C');
    $update_existing = get_option('sheets_to_pages_update_existing', false);
    
    // Check if settings are configured
    $is_configured = !empty($spreadsheet_id);
    
    // Process import if form submitted
    if (isset($_POST['import']) && check_admin_referer('sheets_to_pages_import', 'sheets_to_pages_nonce')) {
        try {
            $results = stp_process_import(false);
            echo '<div class="notice notice-success"><p>' . esc_html__('Import completed successfully.', 'sheets-to-pages') . '</p></div>';
        } catch (Exception $e) {
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }
    }
    
    
    ?>
<div class="wrap">
  <h1><?php echo esc_html__('Import from Google Sheets', 'sheets-to-pages'); ?></h1>

  <?php if (!$is_configured): ?>
  <div class="notice notice-warning">
    <p><?php echo esc_html__('Please configure the plugin settings first.', 'sheets-to-pages'); ?>
      <a
        href="<?php echo admin_url('admin.php?page=sheets-to-pages-settings'); ?>"><?php echo esc_html__('Go to Settings', 'sheets-to-pages'); ?></a>
    </p>
  </div>
  <?php else: ?>
  <div class="card">
    <h2><?php echo esc_html__('Import Settings', 'sheets-to-pages'); ?></h2>
    <p><?php echo esc_html__('Current configuration:', 'sheets-to-pages'); ?></p>
    <ul>
      <li><strong><?php echo esc_html__('Spreadsheet ID:', 'sheets-to-pages'); ?></strong>
        <?php echo esc_html($spreadsheet_id); ?></li>
      <li><strong><?php echo esc_html__('Sheet Range:', 'sheets-to-pages'); ?></strong>
        <?php echo esc_html($sheet_range); ?></li>
      <li><strong><?php echo esc_html__('Update Existing Pages:', 'sheets-to-pages'); ?></strong>
        <?php echo $update_existing ? esc_html__('Yes', 'sheets-to-pages') : esc_html__('No', 'sheets-to-pages'); ?>
      </li>
    </ul>

    <form method="post">
      <?php wp_nonce_field('sheets_to_pages_import', 'sheets_to_pages_nonce'); ?>

      <div class="import-actions">
        <input type="submit" name="import" value="<?php echo esc_attr__('Start Import', 'sheets-to-pages'); ?>"
          class="button button-primary"
          onclick="return confirm('<?php echo esc_js(__('Are you sure you want to start the import? This will create new pages on your site.', 'sheets-to-pages')); ?>');">
      </div>
    </form>
  </div>

  <?php
            // Display results if available
            if (isset($results)) {
                // stp_display_results($results);
            }
            ?>
  <?php endif; ?>
</div>
<?php
}

/**
 * Render the settings page
 */
function stp_render_settings_page() {
    ?>
<div class="wrap">
  <h1><?php echo esc_html__('Sheets to Pages Settings', 'sheets-to-pages'); ?></h1>

  <form method="post" action="options.php">
    <?php settings_fields('sheets_to_pages_main_settings'); // Changed option group name
        do_settings_sections('sheets_to_pages_main_settings'); ?>
    <table class="form-table">
      <tr>
        <th scope="row"><?php echo esc_html__('Google Sheets Spreadsheet ID', 'sheets-to-pages'); ?></th>
        <td>
          <input type="text" name="sheets_to_pages_spreadsheet_id"
            value="<?php echo esc_attr(get_option('sheets_to_pages_spreadsheet_id', '')); ?>" class="regular-text" />
          <p class="description">
            <?php echo esc_html__('The ID of your Google Sheets spreadsheet (from the URL).', 'sheets-to-pages'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><?php echo esc_html__('Sheet Range', 'sheets-to-pages'); ?></th>
        <td>
          <input type="text" name="sheets_to_pages_sheet_range"
            value="<?php echo esc_attr(get_option('sheets_to_pages_sheet_range', 'Sheet1!A:C')); ?>"
            class="regular-text" />
          <p class="description"><?php echo esc_html__('The range to import (e.g., Sheet1!A:C).', 'sheets-to-pages'); ?>
          </p>
        </td>
      </tr>
      <tr>
        <th scope="row"><?php echo esc_html__('Update Existing Pages', 'sheets-to-pages'); ?></th>
        <td>
          <label>
            <input type="checkbox" name="sheets_to_pages_update_existing" value="1"
              <?php checked(get_option('sheets_to_pages_update_existing', false)); ?> />
            <?php echo esc_html__('Update content of existing pages instead of skipping them', 'sheets-to-pages'); ?>
          </label>
        </td>
      </tr>
    </table>

    <h2><?php echo esc_html__('Service Account', 'sheets-to-pages'); ?></h2>
    <p>
      <?php echo esc_html__('This plugin requires a Google Service Account key file named "key.json" in the "googlesheets" directory.', 'sheets-to-pages'); ?>
    </p>

    <?php
            // Check if key file exists
            $key_file = ARTI_SEO_PAGE_PLUGIN_PATH . 'googlesheets/key.json';
            if (file_exists($key_file)) {
                echo '<div class="notice notice-success inline"><p>' . esc_html__('Service account key file found.', 'sheets-to-pages') . '</p></div>';
            } else {
                echo '<div class="notice notice-error inline"><p>' . esc_html__('Service account key file not found. Please place your key.json file in the googlesheets directory.', 'sheets-to-pages') . '</p></div>';
            }
            ?>

    <?php submit_button(); ?>
  </form>
</div>
<?php
}

/**
 * Render the blocks configuration page
 */
function stp_render_blocks_page() {
    // Save block configuration if submitted
    if (isset($_POST['save_block_config']) && check_admin_referer('sheets_to_pages_blocks', 'sheets_to_pages_blocks_nonce')) {
        $block_config = array();
        
        // Get post counts to determine how many blocks were configured
        $block_ids = isset($_POST['block_id']) ? $_POST['block_id'] : array();
        $block_enabled = isset($_POST['block_enabled']) ? $_POST['block_enabled'] : array();
        $block_order = isset($_POST['block_order']) ? $_POST['block_order'] : array();
        $field_ids = isset($_POST['field_id']) ? $_POST['field_id'] : array();
        $column_indices = isset($_POST['column_index']) ? $_POST['column_index'] : array();
        $is_image = isset($_POST['is_image']) ? $_POST['is_image'] : array();
        $is_repeater = isset($_POST['is_repeater']) ? $_POST['is_repeater'] : array();
        
        // Combine the data
        foreach ($block_ids as $index => $block_id) {
            // Skip if no block ID is provided
            if (empty($block_id)) {
                continue;
            }
            
            $is_enabled = isset($block_enabled[$index]) && $block_enabled[$index] == '1';
            
            // Skip blocks that are not enabled
            if (!$is_enabled) {
                continue;
            }
            
            $fields = array();
            if (isset($field_ids[$index]) && is_array($field_ids[$index])) {
                foreach ($field_ids[$index] as $field_index => $field_id) {
                    // Skip empty field IDs
                    if (empty($field_id)) {
                        continue;
                    }
                    
                    $column_index = isset($column_indices[$index][$field_index]) ? (int)$column_indices[$index][$field_index] : '';
                    $is_image_field = isset($is_image[$index][$field_index]) && $is_image[$index][$field_index] == '1';
                    $is_repeater_field = isset($is_repeater[$index][$field_index]) && $is_repeater[$index][$field_index] == '1';
                    
                    $fields[] = array(
                        'field_id' => sanitize_text_field($field_id),
                        'column_index' => $column_index,
                        'is_image' => $is_image_field,
                        'is_repeater' => $is_repeater_field,
                    );
                }
            }
            
            $block_config[] = array(
                'block_id' => (int)$block_id,
                'enabled' => $is_enabled,
                'order' => isset($block_order[$index]) ? (int)$block_order[$index] : 0,
                'fields' => $fields,
            );
        }
        
        // Sort blocks by order
        usort($block_config, function($a, $b) {
            return $a['order'] - $b['order'];
        });
        
        // Save to options
        update_option('sheets_to_pages_block_config', $block_config);

        
        echo '<div class="notice notice-success"><p>' . esc_html__('Block configuration saved successfully.', 'sheets-to-pages') . '</p></div>';
    }
    
    // Get current configuration
    $block_config = get_option('sheets_to_pages_block_config', array());
    
    // Get all reusable blocks
    $reusable_blocks = get_posts(array(
        'post_type' => 'wp_block',
        'posts_per_page' => 50,
        'orderby' => 'title',
        'order' => 'ASC',
    ));
    
    ?>
<div class="wrap">
  <h1><?php echo esc_html__('Block Configuration', 'sheets-to-pages'); ?></h1>
  <p>
    <?php echo esc_html__('Configure which reusable blocks to include on imported pages and map Google Sheets columns to fields.', 'sheets-to-pages'); ?>
  </p>

  <form method="post" id="block-config-form">
    <?php settings_fields('sheets_to_pages_blocks_settings'); // Changed option group name
        do_settings_sections('sheets_to_pages_blocks_settings'); ?>
    <?php 
            wp_nonce_field('sheets_to_pages_blocks', 'sheets_to_pages_blocks_nonce'); ?>

    <div id="block-container">
      <?php
        // Display existing configurations
        if (!empty($block_config)) {
            foreach ($block_config as $index => $block) {
                $reusable_block = get_post($block['block_id']);
                if (!$reusable_block) {
                    continue;
                }
                stp_render_block_config_row($index, $block, $reusable_block);
            }
        }
        

        $block_count = is_array($block_config) ? count($block_config) : 0;

        // Display empty row for adding a new block
        stp_render_block_config_row($block_count, array(
            'block_id' => '',
            'enabled' => true,
            'order' => $block_count + 1,
            'fields' => array(),
        ), null, $reusable_blocks);
        ?>
    </div>

    <p>
      <button type="button" class="button add-block"><?php echo esc_html__('Add Block', 'sheets-to-pages'); ?></button>
      <input type="submit" name="save_block_config"
        value="<?php echo esc_attr__('Save Configuration', 'sheets-to-pages'); ?>" class="button button-primary">
    </p>
  </form>

  <script>
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
  </script>

  <style>
  .block-config-row {
    background: #f9f9f9;
    border: 1px solid #ddd;
    padding: 15px;
    margin-bottom: 15px;
    position: relative;
  }

  .block-header {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 10px;
  }

  .block-title {
    font-weight: bold;
    flex-grow: 1;
  }

  .field-container {
    margin: 10px 0;
  }

  .field-row {
    margin-bottom: 8px;
    display: flex;
    gap: 10px;
    align-items: center;
  }

  .checkbox-label {
    display: inline-flex;
    align-items: center;
    margin-right: 10px;
  }

  .field-controls {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #eee;
  }

  .remove-block {
    position: absolute;
    top: 15px;
    right: 15px;
  }
  </style>
</div>
<?php
}

/**
 * Render a block configuration row
 *
 * @param int $index Row index
 * @param array $block Block configuration
 * @param WP_Post|null $reusable_block Reusable block object
 * @param array|null $all_blocks List of all reusable blocks (optional)
 */
function stp_render_block_config_row($index, $block, $reusable_block = null, $all_blocks = null) {
    // If no reusable block is provided, try to get it from the ID
    if (!$reusable_block && !empty($block['block_id'])) {
        $reusable_block = get_post($block['block_id']);
    }
    
    // If we don't have the full list of blocks, get it
    if ($all_blocks === null) {
        $all_blocks = get_posts(array(
            'post_type' => 'wp_block',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ));
    }
    
    ?>
<div class="block-config-row">
  <div class="block-header">
    <div class="block-title">
      <?php echo esc_html__('Block Configuration', 'sheets-to-pages'); ?> #<?php echo $index + 1; ?>
    </div>
    <div>
      <label>
        <input type="checkbox" name="block_enabled[<?php echo $index; ?>]" value="1"
          <?php checked(isset($block['enabled']) ? $block['enabled'] : true); ?> />
        <?php echo esc_html__('Enable', 'sheets-to-pages'); ?>
      </label>
    </div>
    <div>
      <label>
        <?php echo esc_html__('Order:', 'sheets-to-pages'); ?>
        <input type="number" name="block_order[<?php echo $index; ?>]"
          value="<?php echo isset($block['order']) ? (int)$block['order'] : $index + 1; ?>" class="small-text"
          min="1" />
      </label>
    </div>
  </div>

  <label>
    <?php echo esc_html__('Select Block:', 'sheets-to-pages'); ?>
    <select name="block_id[<?php echo $index; ?>]" class="regular-text">
      <option value=""><?php echo esc_html__('-- Select Block --', 'sheets-to-pages'); ?></option>
      <?php foreach ($all_blocks as $b): ?>
      <option value="<?php echo esc_attr($b->ID); ?>"
        <?php selected(isset($block['block_id']) && $block['block_id'] == $b->ID); ?>>
        <?php echo esc_html($b->post_title); ?> (ID: <?php echo esc_html($b->ID); ?>)
      </option>
      <?php endforeach; ?>
    </select>
  </label>

  <div class="field-controls" <?php echo empty($block['block_id']) ? 'style="display:none;"' : ''; ?>>
    <h4><?php echo esc_html__('Fields Configuration', 'sheets-to-pages'); ?></h4>
    <div class="field-container">
      <?php
                if (!empty($block['fields'])) {
                    foreach ($block['fields'] as $field_index => $field) {
                        ?>
      <div class="field-row">
        <input type="text" name="field_id[<?php echo $index; ?>][<?php echo $field_index; ?>]"
          value="<?php echo esc_attr($field['field_id']); ?>" placeholder="Field ID" class="regular-text">
        <input type="number" name="column_index[<?php echo $index; ?>][<?php echo $field_index; ?>]"
          value="<?php echo esc_attr($field['column_index']); ?>" placeholder="Column Index" class="small-text">
        <label class="checkbox-label">
          <input type="checkbox" name="is_image[<?php echo $index; ?>][<?php echo $field_index; ?>]" value="1"
            <?php checked(isset($field['is_image']) && $field['is_image']); ?>>
          <?php echo esc_html__('Is Image', 'sheets-to-pages'); ?>
        </label>
        <label class="checkbox-label">
          <input type="checkbox" name="is_repeater[<?php echo $index; ?>][<?php echo $field_index; ?>]" value="1"
            <?php checked(isset($field['is_repeater']) && $field['is_repeater']); ?>>
          <?php echo esc_html__('Is Repeater', 'sheets-to-pages'); ?>
        </label>
        <button type="button"
          class="remove-field button"><?php echo esc_html__('Remove', 'sheets-to-pages'); ?></button>
      </div>
      <?php
                    }
                }
                ?>
    </div>
    <button type="button" class="button add-field" data-block-index="<?php echo $index; ?>">
      <?php echo esc_html__('Add Field', 'sheets-to-pages'); ?>
    </button>
  </div>

  <button type="button" class="button remove-block"><?php echo esc_html__('x', 'sheets-to-pages'); ?></button>
</div>
<?php
}


function stp_sanitize_block_config($value) {
    if (!is_array($value)) {
        return array();
    }

    // If empty, preserve existing config
    if (empty($value)) {
        return get_option('sheets_to_pages_block_config', array());
    }

    return $value;
}