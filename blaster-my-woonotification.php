<?php

/**
 * Plugin Name:       Blaster.my WooNotification
 * Plugin URI:        https://app.blaster.my/
 * Description:       Send WhatsApp notification upon order completion. Only for app.blaster.my subscriber.
 * Version:           1.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Nothing Dev
 * Author URI:        https://blaster.my/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://blaster.my/
 * Text Domain:       blaster-my-woonotification
 * Domain Path:       /languages
 */

/*
Blaster.my WooNotification is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

Blaster.my WooNotification is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Blaster.my WooNotification. If not, see {URI to Plugin License}.
*/

// Define hooks to trigger WhatsApp notifications when an order is completed or processing
add_action('woocommerce_order_status_completed', 'blaster_send_whatsapp_notification_on_order_status', 10, 1);
add_action('woocommerce_order_status_processing', 'blaster_send_whatsapp_notification_on_order_status', 10, 1);

// Function to send WhatsApp notification when an order is completed or processing
function blaster_send_whatsapp_notification_on_order_status($order_id)
{
    // Get the WhatsApp API key from the plugin settings
    $api_key = get_option('blaster_whatsapp_notification_api_key', '');
    $url = 'https://app.blaster.my/api';

    // Check if the API key is provided
    if (empty($api_key)) {
        return;
    }

    try {
        // Get the order details based on the order ID
        $order = wc_get_order($order_id);
        $user_phone_number = $order->get_billing_phone();

        $first_name = $order->get_billing_first_name();
        $last_name = $order->get_billing_last_name();

        $status = $order->get_status();
        $status = strtolower($status);

        // Check if the order status is 'completed' or 'processing'
        if ($status !== 'processing' && $status !== 'completed') {
            return;
        }

        // Customize the WhatsApp message based on the order status
        if ($status === 'completed') {
            $custom_message = get_option('blaster_whatsapp_notification_completed_message', '');
            $default_message_template = "Hello {customer_first_name} {customer_last_name}!\n\nYour order with ID {order_id} has been completed.\n\n{order_details}\n\n{shipping_address}\n\nThank you for your purchase!";
        } elseif ($status === 'processing') {
            $custom_message = get_option('blaster_whatsapp_notification_processing_message', '');
            $default_message_template = "Hello {customer_first_name} {customer_last_name}!\n\nYour order with ID {order_id} is now being processed.\n\n{order_details}\n\n{shipping_address}\n\nThank you for your purchase!";
        }

        // Get order details and shipping address
        $order_details = blaster_get_order_details($order);
        $shipping_address = blaster_get_shipping_address($order);

        // Create the final WhatsApp message based on the selected template or custom message
        $default_message = blaster_replace_template_tags($default_message_template, $order);
        $message = empty($custom_message) ? $default_message : $custom_message;

        // Replace template tags in the message with actual order data
        $message = blaster_replace_template_tags($message, $order);

        // Send the WhatsApp message using the API
        $response = blaster_send_whatsapp_message($url, $api_key, $user_phone_number, $message, $first_name, $last_name);

        // Log the WhatsApp notification status (Sent or Failed) for the order
        if ($response['status'] == 400) {
            blaster_log_whatsapp_notification($order_id, 'Failed');
        } else {
            blaster_log_whatsapp_notification($order_id, 'Sent');
        }
    } catch (\Exception $e) {
        // Log a failure if an exception occurs during the process
        blaster_log_whatsapp_notification($order_id, 'Failed');
    }
}

// Function to get order details as a formatted string
function blaster_get_order_details($order)
{
    $items = $order->get_items();
    $order_details = "";
    foreach ($items as $item) {
        $product_name = $item->get_name();
        $product_quantity = $item->get_quantity();
        $product_total = wc_price($item->get_total());
        $order_details .= "{$product_name} x {$product_quantity} = {$product_total}\n";
    }
    $order_total = wc_price($order->get_total());
    $order_details .= "Total: {$order_total}";

    return wp_strip_all_tags(html_entity_decode($order_details));
}

// Function to get the shipping address as a formatted string
function blaster_get_shipping_address($order)
{
    $shipping_address = $order->get_formatted_shipping_address();
    $shipping_address = str_replace('<br/>', "\n", $shipping_address);
    $shipping_address = wp_strip_all_tags($shipping_address);

    return $shipping_address;
}

// Function to validate the WhatsApp API key by sending a request to the API
function blaster_is_valid_api_key($url, $api_key)
{
    $response = wp_remote_post(
        $url . '/validate/key',
        array(
            'body' => array(
                'apikey' => $api_key,
            ),
        )
    );

    if (is_wp_error($response)) {
        error_log('API Error: ' . $response->get_error_message());
        return false;
    } else {
        $response_data = json_decode(wp_remote_retrieve_body($response), true);

        // Add debug logs
        error_log('Response data: ' . print_r($response_data, true));

        if (isset($response_data['status']) && $response_data['status'] === 'success') {
            if (isset($response_data['email']) && !empty($response_data['email'])) {
                // Save the connected email address to options.
                update_option('blaster_api_connected_email', $response_data['email']);
            }

            return true;
        } else {
            return false;
        }
    }
}

// Function to sanitize the WhatsApp API key before saving it to the options
function blaster_whatsapp_notification_sanitize_api_key($input)
{
    $new_api_key = sanitize_text_field($input);
    $url = 'https://app.blaster.my/api';

    // Validate the new API key
    if (!blaster_is_valid_api_key($url, $new_api_key)) {
        // Add a settings error if the API key is invalid
        add_settings_error(
            'blaster_whatsapp_notification_api_key',
            'blaster_invalid_api_key',
            'Invalid API Key: The API key you entered is not valid.',
            'error'
        );

        // Return the previously saved valid key instead of the invalid one
        return get_option('blaster_whatsapp_notification_api_key');
    }

    // Send the domain to the API
    $domain = home_url(); // Replace with the appropriate way to get your domain name
    blaster_send_domain_to_api($url, $new_api_key, $domain);

    // If the key is valid, set a transient indicating a successful update
    set_transient('blaster_api_key_saved', true, 5);

    // Return the valid key
    return $new_api_key;
}

// Function to display the WhatsApp API key input field in the settings page
function blaster_whatsapp_notification_api_key_callback()
{
    // If a valid key was saved, print a success message and delete the transient
    if (get_transient('blaster_api_key_saved')) {
        echo '<div class="notice notice-success"><p>Your API Key is valid. Thank you :)</p></div>';
        delete_transient('blaster_api_key_saved');
    }

    // If an invalid key was entered, print an error message and delete the transient
    if (get_transient('blaster_invalid_api_key')) {
        echo '<div class="notice notice-error"><p>Invalid API Key: The API key you entered is not valid.</p></div>';
        delete_transient('blaster_invalid_api_key');
    }

    $api_key = get_option('blaster_whatsapp_notification_api_key', '');
    echo '<input type="text" name="blaster_whatsapp_notification_api_key" value="' . esc_attr($api_key) . '" class="regular-text" />';
}

// Function to send the domain name to the API when saving the API key
function blaster_send_domain_to_api($url, $api_key, $domain)
{
    $response = wp_remote_post(
        $url . '/store/domain', // Replace with the actual path to your endpoint
        array(
            'body' => array(
                'apikey' => $api_key,
                'domain' => $domain
            ),
        )
    );

    if (is_wp_error($response)) {
        error_log('API Error: ' . $response->get_error_message());
        return false;
    } else {
        $response_data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($response_data['status']) && $response_data['status'] === 'success') {
            return true;
        } else {
            return false;
        }
    }
}

// Function to send the WhatsApp message using the API
function blaster_send_whatsapp_message($url, $api_key, $user_phone_number, $message, $first_name, $last_name)
{
    $response = wp_remote_post(
        $url . '/message/sendText',
        array(
            'body' => array(
                'apikey' => get_option('blaster_whatsapp_notification_api_key'),
                'number' => $user_phone_number,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'domain' => get_site_url(),
                'options' => array(
                    'delay' => 1000,
                ),
                'textMessage' => array(
                    'text' => $message,
                ),
            ),
        )
    );

    if (is_wp_error($response)) {
        return array('status' => 400, 'error' => $response->get_error_message());
    } else {
        return json_decode(wp_remote_retrieve_body($response), true);
    }
}

// Function to log the WhatsApp notification status for an order
function blaster_log_whatsapp_notification($order_id, $status)
{
    $log_message = sprintf('WhatsApp Notification for Order ID %s: %s', $order_id, $status);

    if (class_exists('WC_Logger')) {
        $logger = wc_get_logger();
        $logger->log('info', $log_message, array('source' => 'woocommerce'));
    }
}

// Function to add a submenu page for plugin settings
add_action('admin_menu', 'blaster_whatsapp_notification_add_submenu');

function blaster_whatsapp_notification_add_submenu()
{
    add_submenu_page(
        'options-general.php',
        'Blaster.my Settings',
        'Blaster.my',
        'manage_options',
        'blaster_whatsapp_notification',
        'blaster_whatsapp_notification_page'
    );
}

// Function to add a settings link in the plugins list
add_filter('plugin_action_links', 'blaster_add_settings_link', 10, 2);

function blaster_add_settings_link($links, $file)
{
    if (plugin_basename(__FILE__) === $file) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=blaster_whatsapp_notification') . '">Settings</a>';
        array_push($links, $settings_link);
    }
    return $links;
}

// Function to enqueue the Blaster JavaScript and CSS files
function blaster_enqueue_assets()
{
    // Enqueue the blaster.js file, dependent on jQuery, with a version number of 1.0.0, to be placed in the footer
    wp_enqueue_script('blaster-js', plugins_url('/assets/js/blaster.js', __FILE__), array('jquery'), '1.0.1', true);

    // Enqueue the blaster.css file, with a version number of 1.0.0
    wp_enqueue_style('blaster-css', plugins_url('/assets/css/blaster.css', __FILE__), array(), '1.0.1');
}

// Hook the blaster_enqueue_assets function to the admin_enqueue_scripts action, to load the assets on the WordPress admin pages
add_action('admin_enqueue_scripts', 'blaster_enqueue_assets');

// Function to render the settings page for the plugin
function blaster_whatsapp_notification_page()
{
    // Get the connected email address from the options
    $connected_email = get_option('blaster_api_connected_email', '');
?>
    <div class="wrap">
        <h1><?php echo esc_html__('Blaster.my WhatsApp Notification Settings', 'blaster-my-woonotification'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('blaster_whatsapp_notification');
            do_settings_sections('blaster_whatsapp_notification');
            submit_button();
            ?>
        </form>
        <form method="post" action="">
            <input type="hidden" name="blaster_whatsapp_notification_reset_messages" value="1" />
            <?php
            submit_button(__('Reset Messages to Default', 'blaster-my-woonotification'), 'secondary', 'reset_messages', false);
            ?>
        </form>
        <?php if ($connected_email) : ?>
            <div class="notice notice-info">
                <p><?php printf('Account Connected: %s', $connected_email); ?></p>
            </div>
        <?php endif; ?>
    </div>
<?php
}

// Function to add the settings section and fields
add_action('admin_init', 'blaster_whatsapp_notification_add_settings_section');

function blaster_whatsapp_notification_add_settings_section()
{
    // Handle message reset
    if (isset($_POST['blaster_whatsapp_notification_reset_messages']) && $_POST['blaster_whatsapp_notification_reset_messages'] === '1') {
        // Reset the custom message to its default state
        $default_completed_message = "Hello {customer_first_name} {customer_last_name}!\n\nYour order with ID {order_id} has been completed.\n\n{order_details}\n\n{shipping_address}\n\nThank you for your purchase!";
        $default_processing_message = "Hello {customer_first_name} {customer_last_name}!\n\nYour order with ID {order_id} is now being processed.\n\n{order_details}\n\n{shipping_address}\n\nThank you for your purchase!";

        update_option('blaster_whatsapp_notification_completed_message', $default_completed_message);
        update_option('blaster_whatsapp_notification_processing_message', $default_processing_message);
    } else {
        // Save settings
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $new_api_key = sanitize_text_field($_POST['blaster_whatsapp_notification_api_key']);
            if (!empty($new_api_key) && blaster_is_valid_api_key('https://app.blaster.my/api', $new_api_key)) {
                // If the API key is valid, update the option
                update_option('blaster_whatsapp_notification_api_key', $new_api_key);
            }
        }
    }

    add_settings_section(
        'blaster_whatsapp_notification_settings_section',
        __('WhatsApp Notification Settings', 'blaster-my-woonotification'),
        'blaster_whatsapp_notification_section_callback',
        'blaster_whatsapp_notification'
    );

    add_settings_field(
        'blaster_whatsapp_notification_api_key',
        __('API Key', 'blaster-my-woonotification'),
        'blaster_whatsapp_notification_api_key_callback',
        'blaster_whatsapp_notification',
        'blaster_whatsapp_notification_settings_section'
    );

    add_settings_field(
        'blaster_whatsapp_notification_completed_message',
        __('Completed Order Notification Message', 'blaster-my-woonotification'),
        'blaster_whatsapp_notification_completed_message_callback',
        'blaster_whatsapp_notification',
        'blaster_whatsapp_notification_settings_section'
    );

    add_settings_field(
        'blaster_whatsapp_notification_processing_message',
        __('Processing Order Notification Message', 'blaster-my-woonotification'),
        'blaster_whatsapp_notification_processing_message_callback',
        'blaster_whatsapp_notification',
        'blaster_whatsapp_notification_settings_section'
    );

    register_setting(
        'blaster_whatsapp_notification',
        'blaster_whatsapp_notification_api_key',
        'blaster_whatsapp_notification_sanitize_api_key'
    );

    register_setting(
        'blaster_whatsapp_notification',
        'blaster_whatsapp_notification_completed_message'
    );

    register_setting(
        'blaster_whatsapp_notification',
        'blaster_whatsapp_notification_processing_message'
    );
}

// Function to render the section callback (not used)
function blaster_whatsapp_notification_section_callback()
{
}

// Function to render the Completed Order Notification Message field
function blaster_whatsapp_notification_completed_message_callback()
{
    $custom_message = get_option('blaster_whatsapp_notification_completed_message', '');
    $default_message_template = "Hello {customer_first_name} {customer_last_name}!\n\nYour order with ID {order_id} has been completed.\n\n{order_details}\n\n{shipping_address}\n\nThank you for your purchase!";
    $message = esc_textarea($custom_message);
    $message = $message ? $message : blaster_replace_template_tags($default_message_template, null);
?>
    <textarea name="blaster_whatsapp_notification_completed_message" class="large-text auto-expand" style="min-height: 200px;"><?php echo $message; ?></textarea>
<?php
}

// Function to render the Processing Order Notification Message field
function blaster_whatsapp_notification_processing_message_callback()
{
    $custom_message = get_option('blaster_whatsapp_notification_processing_message', '');
    $default_message_template = "Hello {customer_first_name} {customer_last_name}!\n\nYour order with ID {order_id} is now being processed.\n\n{order_details}\n\n{shipping_address}\n\nThank you for your purchase!";
    $message = esc_textarea($custom_message);
    $message = $message ? $message : blaster_replace_template_tags($default_message_template, null);
?>
    <textarea name="blaster_whatsapp_notification_processing_message" class="large-text auto-expand" style="min-height: 200px;"><?php echo $message; ?></textarea>
    <div class="blaster-info-box">
        <p><strong>Available Variables:</strong></p>
        <ul>
            <li>{customer_first_name} - Customer's first name.</li>
            <li>{customer_last_name} - Customer's last name.</li>
            <li>{order_id} - Order ID.</li>
            <li>{order_details} - Details of the ordered items.</li>
            <li>{shipping_address} - Shipping address.</li>
        </ul>
    </div>
<?php
}

// Function to replace template tags with actual order data in the message
function blaster_replace_template_tags($message_template, $order)
{
    if (!$order) {
        return $message_template;
    }

    $customer_first_name = $order->get_billing_first_name();
    $customer_last_name = $order->get_billing_last_name();
    $order_id = $order->get_order_number();
    $order_details = blaster_get_order_details($order);
    $shipping_address = blaster_get_shipping_address($order);

    $tags = array(
        '{customer_first_name}' => $customer_first_name,
        '{customer_last_name}' => $customer_last_name,
        '{order_id}' => $order_id,
        '{order_details}' => $order_details,
        '{shipping_address}' => $shipping_address,
    );

    return str_replace(array_keys($tags), array_values($tags), $message_template);
}

// Function to remove the options when the plugin is deactivated
function blaster_whatsapp_notification_deactivate()
{
    $option_names = array(
        'blaster_whatsapp_notification_api_key',
        'blaster_whatsapp_notification_completed_message',
        'blaster_whatsapp_notification_processing_message',
        'blaster_api_connected_email',
    );

    foreach ($option_names as $option_name) {
        delete_option($option_name);
    }
}
register_deactivation_hook(__FILE__, 'blaster_whatsapp_notification_deactivate');
