<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
* Display import results
*
* @param array $results The import results
*/
function stp_display_results($results) {
echo '<div class="import-summary">';
  echo '<h3>' . esc_html__('Import Summary', 'sheets-to-pages') . '</h3>';
  echo '<p>' . sprintf(
    esc_html__('Total rows: %d, Created: %d, Updated: %d, Skipped: %d, Errors: %d', 'sheets-to-pages'),
    $results['total'],
    $results['created'],
    $results['updated'],
    $results['skipped'],
    $results['errors']
    ) . '</p>';

  echo '<h3>' . esc_html__('Details', 'sheets-to-pages') . '</h3>';
  echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead>
      <tr>';
        echo '<th>' . esc_html__('Row', 'sheets-to-pages') . '</th>';
        echo '<th>' . esc_html__('Title', 'sheets-to-pages') . '</th>';
        echo '<th>' . esc_html__('Status', 'sheets-to-pages') . '</th>';
        echo '<th>' . esc_html__('Message', 'sheets-to-pages') . '</th>';
        echo '<th>' . esc_html__('Actions', 'sheets-to-pages') . '</th>';
        echo '</tr>
    </thead>
    <tbody>';

      foreach ($results['details'] as $detail) {
      $status_class = 'status-' . $detail['status'];
      echo '<tr class="' . esc_attr($status_class) . '">';
        echo '<td>' . esc_html($detail['row']) . '</td>';
        echo '<td>' . esc_html($detail['title']) . '</td>';
        echo '<td>' . esc_html($detail['status']) . '</td>';
        echo '<td>' . esc_html($detail['message']) . '</td>';
        echo '<td>';

          if (isset($detail['page_id']) && $detail['page_id']) {
          echo '<a href="' . esc_url(get_edit_post_link($detail['page_id'])) . '" target="_blank">' . esc_html__('Edit',
            'sheets-to-pages') . '</a> | ';
          echo '<a href="' . esc_url(get_permalink($detail['page_id'])) . '" target="_blank">' . esc_html__('View',
            'sheets-to-pages') . '</a>';
          }

          echo '</td>
      </tr>';
      }

      echo '</tbody>
  </table>
</div>';
}