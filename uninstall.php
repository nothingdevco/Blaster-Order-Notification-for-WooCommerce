<?php
// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove the options stored by the plugin
$option_names = array(
    'blaster_whatsapp_notification_api_key',
    'blaster_whatsapp_notification_completed_message',
    'blaster_whatsapp_notification_processing_message',
    'blaster_api_connected_email',
);

foreach ($option_names as $option_name) {
    delete_option($option_name);
}
