<?php
/**
 * Plugin Name:     Post Export
 * Plugin URI:      PLUGIN SITE HERE
 * Description:     Simple plugin to export any post type in a CSV file
 * Author:          Axel Ilali
 * Author URI:      https://axel-ilali.cm
 * Text Domain:     post-export
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Post_Export
 */

// Register the plugin's admin menu
add_action('admin_menu', 'spd_export_menu');

// Create the admin menu page
function spd_export_menu()
{
    add_menu_page(
        'Simple Post Data Export',
        'Post Data Export',
        'manage_options',
        'spd-export',
        'spd_export_options_page'
    );
}

// Render the plugin's options page
function spd_export_options_page()
{
    ?>
    <div class="wrap">
        <h1>Simple Post Data Export</h1>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="spd_export_csv">
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Select Post Type:</th>
                    <td>
                        <?php
                        $post_types = get_post_types(['public' => true], 'objects');
                        $selected_post_type = isset($_POST['post_type']) ? $_POST['post_type'] : 'post';
                        ?>
                        <select name="post_type" id="post_type_select" required>
                            <?php foreach ($post_types as $post_type) : ?>
                                <option value="<?php echo $post_type->name; ?>" <?php selected($selected_post_type, $post_type->name); ?>><?php echo $post_type->label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr valign="top" id="acf_fields_row" style="display: none;">
                    <th scope="row">Select ACF Fields:</th>
                    <td>
                        <div id="acf_fields_list"></div>
                    </td>
                </tr>
            </table>
            <?php submit_button('Export Data'); ?>
        </form>
    </div>
    <script>
        // JavaScript to dynamically load ACF fields based on the selected post type
        document.getElementById('post_type_select').addEventListener('change', function() {
            var postType = this.value;
            var acfFieldsRow = document.getElementById('acf_fields_row');
            var acfFieldsList = document.getElementById('acf_fields_list');

            if (postType !== '') {
                acfFieldsRow.style.display = 'block';

                // Retrieve ACF fields for the selected post type via AJAX
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxurl, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        var acfFields = JSON.parse(xhr.responseText);
                        acfFieldsList.innerHTML = '';

                        // Generate checkboxes for each ACF field
                        for (var i = 0; i < acfFields.length; i++) {
                            var field = acfFields[i];
                            var checkbox = document.createElement('input');
                            checkbox.type = 'checkbox';
                            checkbox.name = 'acf_fields[]';
                            checkbox.value = field.key;
                            checkbox.id = 'acf_field_' + field.key;
                            acfFieldsList.appendChild(checkbox);

                            var label = document.createElement('label');
                            label.setAttribute('for', 'acf_field_' + field.key);
                            label.innerHTML = field.label;
                            acfFieldsList.appendChild(label);

                            acfFieldsList.appendChild(document.createElement('br'));
                        }
                    }
                };
                xhr.send('action=get_acf_fields&post_type=' + postType);
            } else {
                acfFieldsRow.style.display = 'none';
                acfFieldsList.innerHTML = '';
            }
        });
    </script>
    <?php
}

// Handle AJAX request to retrieve ACF fields for the selected post type
add_action('wp_ajax_get_acf_fields', 'spd_get_acf_fields');
function spd_get_acf_fields()
{
    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : '';
    if (empty($post_type)) {
        wp_die();
    }

    $acf_fields = acf_get_field_groups(['post_type' => $post_type]);
    $fields = [];
    if ($acf_fields) {
        $fields = acf_get_fields($acf_fields[0]['ID']);
    }

    wp_send_json($fields);
}

// Handle the form submission
add_action('admin_post_spd_export_csv', 'spd_export_csv');
function spd_export_csv()
{
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }

    if (isset($_POST['post_type']) && !empty($_POST['post_type'])) {
        $post_type = sanitize_text_field($_POST['post_type']);

        $args = array(
            'post_type' => $post_type,
            'posts_per_page' => -1,
        );

        $query = new WP_Query($args);

        if ($query->have_posts()) {
            $filename = 'post_data_' . $post_type . '_' . date('Ymd') . '.csv';

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            $file = fopen('php://output', 'w');

            // CSV header
            $header = ['Title', 'Taxonomies'];
            $acf_fields = acf_get_field_groups(array('post_type' => $post_type));
            if ($acf_fields) {
                $fields = acf_get_fields($acf_fields[0]['ID']);
                foreach ($fields as $field) {
                    if (isset($_POST['acf_fields']) && in_array($field['key'], $_POST['acf_fields'])) {
                        $header[] = $field['label'];
                    }
                }
            }
            fputcsv($file, $header);

            while ($query->have_posts()) {
                $query->the_post();

                // Get post data
                $title = get_the_title();
                $taxonomies = wp_get_post_terms(get_the_ID(), [], ['fields' => 'names']);

                // Get ACF field values
                $acf_values = [];
                if ($acf_fields) {
                    $values = get_fields();
                    foreach ($fields as $field) {
                        if (isset($_POST['acf_fields']) && in_array($field['key'], $_POST['acf_fields'])) {
                            $acf_values[] = isset($values[$field['name']]) ? $values[$field['name']] : '';
                        }
                    }
                }

                // Prepare CSV row
                $row = [
                    $title,
                    implode(', ', $taxonomies),
                ];
                $row = array_merge($row, $acf_values);

                fputcsv($file, $row);
            }

            fclose($file);
            exit;
        }
    }

    wp_redirect(admin_url('admin.php?page=spd-export'));
    exit;
}

