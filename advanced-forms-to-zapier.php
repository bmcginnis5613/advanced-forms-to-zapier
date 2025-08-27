<?php
/*
Plugin Name: Advanced Forms to Zapier
Description: Sends Advanced Form for ACF submissions to a Zapier Webhook.
Author: FirstTracks Marketing
Author URI: https://firsttracksmarketing.com/
Version: 1.0.0
*/

// TODO LIST
// 1. It is probably best to refactor at some point to use a drop down for the form list incase there are lots of forms.
// Also make it so you can assoicate different webhooks to forms in the event you want the data for a form going
// to a different webhook. Currently we don't need these features, but one way to improve the plugin.

// Hook into Advanced Forms submission
add_action('af/form/submission', function( $form, $fields, $entry_id ) {
    // Get selected forms from settings
    $selected_forms = get_option('af_zapier_selected_forms', []);
    
    // Check if this form is in the selected forms list
    $form_id = $form['post_id'] ?? '';
    if ( empty($selected_forms) || !in_array($form_id, $selected_forms) ) {
        return;
    }
   
    // Get Zapier URL from settings
    $zapier_url = get_option('af_zapier_webhook_url', '');
    if ( empty($zapier_url) ) {
        return; // Do nothing if no webhook is set
    }
   
    // Prepare data array with field labels as keys
    $data = [];
    foreach ( $fields as $field ) {
        $label = isset($field['label']) && $field['label'] ? $field['label'] : $field['key'];
        $value = $field['value'];
        
        // Handle array values (like checkboxes, multi-selects)
        if ( is_array($value) ) {
            // Convert array to comma-separated string
            $data[$label] = implode(', ', array_filter($value));
        } else {
            $data[$label] = $value;
        }
    }
   
    // Include form info
    $data['_form_title'] = $form['title'];
    $data['_form_id']    = $form['post_id'] ?? '';
   
    // Send to Zapier
    wp_remote_post( $zapier_url, [
        'body'      => json_encode($data),
        'headers'   => [
            'Content-Type' => 'application/json',
        ],
        'timeout'   => 15,
    ]);
}, 10, 3);

// Check if Advanced Forms is installed on plugin activation
register_activation_hook(__FILE__, function() {
    if (!post_type_exists('af_form') && !function_exists('af_get_form')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            '<h1>Plugin Activation Error</h1>' .
            '<p><strong>Advanced Forms to Zapier</strong> requires the <strong>Advanced Forms for ACF</strong> plugin to be installed and activated.</p>' .
            '<p>Please install and activate Advanced Forms for ACF first, then try activating this plugin again.</p>' .
            '<p><a href="' . admin_url('plugins.php') . '">&laquo; Go back to Plugins</a></p>'
        );
    }
});

// Add settings page under Advanced Forms menu
add_action('admin_menu', function() {
    // Add as submenu under Advanced Forms
    add_submenu_page(
        'edit.php?post_type=af_form',           // Parent slug for Advanced Forms
        'Advanced Forms to Zapier Settings',    // Page title
        'Advanced Forms to Zapier',             // Menu title
        'manage_options',                       // Capability
        'af-zapier-settings',                   // Menu slug
        'af_zapier_settings_page'               // Callback function
    );
}, 20);

// Add settings link to plugin actions in plugins list
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . admin_url('edit.php?post_type=af_form&page=af-zapier-settings') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// Function to get all Advanced Forms
function af_zapier_get_available_forms() {
    $forms = [];
    
    // Get all published Advanced Forms
    $form_posts = get_posts([
        'post_type' => 'af_form',
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC'
    ]);
    
    foreach ($form_posts as $form_post) {
        $forms[$form_post->ID] = $form_post->post_title;
    }
    
    return $forms;
}

// Settings page output
function af_zapier_settings_page() {
    
    // Handle form submission
    if (isset($_POST['submit']) && check_admin_referer('af_zapier_settings_nonce')) {
        
        // Update webhook URL
        $webhook_url = sanitize_url($_POST['af_zapier_webhook_url'] ?? '');
        update_option('af_zapier_webhook_url', $webhook_url);
        
        // Update selected forms
        $selected_forms = isset($_POST['af_zapier_selected_forms']) ? array_map('intval', $_POST['af_zapier_selected_forms']) : [];
        update_option('af_zapier_selected_forms', $selected_forms);
        
        echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
    }
    
    $webhook_url = get_option('af_zapier_webhook_url', '');
    $selected_forms = get_option('af_zapier_selected_forms', []);
    $available_forms = af_zapier_get_available_forms();
    ?>
    <div class="wrap">
        <h1>Advanced Forms to Zapier Settings</h1>
        
        <?php if (empty($available_forms)): ?>
            <div class="notice notice-warning">
                <p><strong>No Advanced Forms found.</strong> Please create a form first using Advanced Forms for ACF.</p>
            </div>
        <?php endif; ?>
        
        <form method="post" action="">
            <?php wp_nonce_field('af_zapier_settings_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="af_zapier_webhook_url">Zapier Webhook URL</label>
                    </th>
                    <td>
                        <input type="url" 
                               id="af_zapier_webhook_url" 
                               name="af_zapier_webhook_url" 
                               value="<?php echo esc_attr($webhook_url); ?>" 
                               class="regular-text" 
                               placeholder="https://hooks.zapier.com/hooks/catch/..." />
                        <p class="description">Enter your Zapier webhook URL where form submissions will be sent.</p>
                    </td>
                </tr>
                
                <?php if (!empty($available_forms)): ?>
                <tr>
                    <th scope="row">Select Forms to Send to Zapier</th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text">Select forms to send to Zapier</legend>
                            <?php foreach ($available_forms as $form_id => $form_title): ?>
                                <label>
                                    <input type="checkbox" 
                                           name="af_zapier_selected_forms[]" 
                                           value="<?php echo esc_attr($form_id); ?>"
                                           <?php checked(in_array($form_id, $selected_forms)); ?> />
                                    <?php echo esc_html($form_title); ?>
                                    <span class="description">(ID: <?php echo esc_html($form_id); ?>)</span>
                                </label><br />
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="description">
                            Select which Advanced Forms should send their submissions to the Zapier webhook. 
                            Only submissions from selected forms will be sent.
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
            
            <?php submit_button(); ?>
        </form>
        
        <?php if (!empty($selected_forms)): ?>
        <div class="card">
            <h2>Currently Active Forms</h2>
            <ul>
                <?php foreach ($selected_forms as $form_id): ?>
                    <?php if (isset($available_forms[$form_id])): ?>
                        <li><strong><?php echo esc_html($available_forms[$form_id]); ?></strong> (ID: <?php echo esc_html($form_id); ?>)</li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <h2>How it Works</h2>
            <ol>
                <li>Enter your Zapier webhook URL above</li>
                <li>Select which Advanced Forms should send data to Zapier</li>
                <li>Save the settings</li>
                <li>Form submissions from selected forms will automatically be sent to your Zapier webhook</li>
            </ol>
            <p><strong>Note:</strong> If no forms are selected, no data will be sent to Zapier. Checkbox and multi-select fields will be sent as comma-separated values in a single field.</p>
        </div>
    </div>
    
    <style>
        .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            margin-top: 20px;
            padding: 20px;
        }
        
        .card h2 {
            margin-top: 0;
        }
        
        fieldset label {
            display: block;
            margin-bottom: 8px;
        }
        
        fieldset .description {
            color: #646970;
            font-size: 13px;
            margin-left: 5px;
        }
    </style>
    <?php
}