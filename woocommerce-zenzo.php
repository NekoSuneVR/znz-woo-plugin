<?php
/*
    Plugin Name: Zenzo Payment Gateway for Woocommerce
    Description: Payment Gateway for Zenzo Cryptocurrency
    Version: 1.0.0
    Author: Zenzo
    Author URI: https://zenzo.io
    Plugin URI: https://github.com/zenzo/zenzo-woo-plugin
    Developer: Zenzo
*/

const ZNZ_API_URL = "https://widgets.nekosunevr.co.uk/Payment-Checker/ZNZ.php";
const ZNZ_ORDERS_TABLE_NAME = "zenzo_cryptocurrency_orders";

function znz_create_transactions_table()
{
    global $wpdb;
    $db_table_name = $wpdb->prefix . ZNZ_ORDERS_TABLE_NAME;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $db_table_name (
              id int(11) NOT NULL AUTO_INCREMENT,
              transaction_id varchar(150) DEFAULT NULL,
              payment_address varchar(150),
              order_id varchar(250),
              order_status varchar(250),
              order_time varchar(250),
              order_total varchar(50),
              order_in_crypto varchar(50),
              order_default_currency varchar(50),
              order_crypto_exchange_rate varchar(50),
              confirmation_no int(11) DEFAULT NULL,
              PRIMARY KEY  id (id)
          ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $res = dbDelta($sql);
}


// Check active plugins and see if woocommerce is installed
$active_plugins = apply_filters('active_plugins', get_option('active_plugins'));

if (in_array('woocommerce/woocommerce.php', $active_plugins) || class_exists('WooCommerce')) {

    register_activation_hook(__FILE__, 'znz_create_transactions_table');
    add_filter('woocommerce_payment_gateways', 'znz_add_zenzo_crypto_gateway');

    function znz_add_zenzo_crypto_gateway($gateways)
    {
        $gateways[] = 'WC_Zenzo';
        return $gateways;
    }

    add_action('plugins_loaded', 'znz_init_payment_gateway');

    function znz_init_payment_gateway()
    {
        require 'WC_Zenzo.php';
    }
} else {
    function znz_admin_notice()
    {
        echo "<div style='margin-left: 2px;' class='error'><p><strong>Please install WooCommerce before using Zenzo Cryptocurrency Payment Gateway.</strong></p></div>";
        deactivate_plugins('/woocommerce-zenzo/woocommerce-zenzo.php');
        wp_die();
    };
    add_action('admin_notices', 'znz_admin_notice');
}


// Add plugin scripts
function znz_load_cp_scripts()
{
    if (is_wc_endpoint_url('order-pay')) {
        wp_enqueue_style('cp-styles', plugins_url('css/cp-styles.css', __FILE__));
        wp_enqueue_script('cp-script', plugins_url('js/cp-script4.js', __FILE__));
    }
}

add_action('wp_enqueue_scripts', 'znz_load_cp_scripts', 30);


// Processing of order
function znz_process_order($order_id)
{
    global $wp;
    $wc_znz = new WC_Zenzo;

    $order_id = $wp->query_vars['order-pay'];
    $order = wc_get_order($order_id);
    $order_status = $order->get_status();

    $order_crypto_exchange_rate = $wc_znz->exchange_rate;

    // Redirect to "cancelled" page when the order's payment is not received
    if ($order_status == 'cancelled') {
        $redirect = $order->get_cancel_order_url();
        wp_safe_redirect($redirect);
        exit;
    }

    // Redirect to "order received" page when the order's payment is successfully completed
    if ($order_status == 'completed') {
        $redirect = $order->get_checkout_order_received_url();
        wp_safe_redirect($redirect);
        exit;
    }

    if ($order_crypto_exchange_rate == 0) {
        wc_add_notice('There is an issue with fetching information about the current rates. Please try again.', 'error');
        wc_print_notices();
        exit;
    }


    if ($order_id > 0 && $order instanceof WC_Order) {

        global $wpdb;
        $db_table_name = $wpdb->prefix . ZNZ_ORDERS_TABLE_NAME;
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $db_table_name WHERE order_id = %d", $order_id));

        if ($wpdb->last_error) {
            wc_add_notice('There has been an error processing your order. Please try again.', 'error');
            wc_print_notices();
            exit;
        }


        // Record the order details for the first time
        if ($count == 0) {

            $payment_address = $wc_znz->payment_address;
            $order_total = $order->get_total();
            $order_in_crypto = znz_order_total_in_crypto($order_total, $order_crypto_exchange_rate);
            $order_currency = $order->get_currency();

            $record_new = $wpdb->insert($db_table_name, array('transaction_id' => "", 'payment_address' => $payment_address, 'order_id' => $order_id, 'order_total' => $order_total, 'order_in_crypto' => $order_in_crypto, 'order_default_currency' => $order_currency, 'order_crypto_exchange_rate' => $order_crypto_exchange_rate, 'order_status' => 'pending', 'order_time' => time()));

            if ($wpdb->last_error) {
                wc_add_notice('There has been an error processing your order. Please try again.', 'error');
                wc_print_notices();
                exit;
            }
        }
    }
}

add_action("before_woocommerce_pay", "znz_process_order", 20);



// Verification of payment
function znz_verify_payment()
{
    global $wpdb;
    $db_table_name = $wpdb->prefix . ZNZ_ORDERS_TABLE_NAME;

    $wc_znz = new WC_Zenzo;

    $order_id = intval(sanitize_text_field($_POST['order_id']));
    $order = new WC_Order($order_id);


    $cp_order = znz_get_cp_order_info($order_id);
    $payment_address = $cp_order->payment_address;
    $transaction_id = $cp_order->transaction_id;
    $order_in_crypto = $cp_order->order_in_crypto;
    $confirmation_no = $wc_znz->confirmation_no;
    $order_time = $cp_order->order_time;
    $max_time_limit = $wc_znz->max_time_limit;
    $plugin_version = $wc_znz->plugin_version;

    if (empty($transaction_id)) {
        $transaction_id = "missing";
    }
    $response = wp_remote_get(ZNZ_API_URL . "?address=" . $payment_address . "&tx=" . $transaction_id . "&amount=" . $order_in_crypto . "&conf=" . $confirmation_no . "&otime=" . $order_time . "&mtime=" . $max_time_limit . "&pv=" . $plugin_version);
    $response = wp_remote_retrieve_body($response);
    $response = json_decode($response);


    if (!empty($response)) {

        if ($response->status == "invalid") {
            echo json_encode($response);
            die();
        }

        // Check if transaction is expired
        if ($response->status == "expired") {
            if ($cp_order->order_status != "expired") {
                $update = $wpdb->update($db_table_name, array('order_status' => 'expired'), array('order_id' => $order_id));
                $order->update_status('cancelled');
            }
        }

        // Check if transaction exists
        if ($response->transaction_id != "" && strlen($response->transaction_id) == 64) {
            $transactions = $wpdb->get_results($wpdb->prepare("SELECT id FROM $db_table_name WHERE transaction_id = %s AND order_id <> %d", $response->transaction_id, $order_id));

            if ($wpdb->last_error) {
                wc_add_notice('Unable to process the order. Please try again.', 'error');
                wc_print_notices();
                exit;
            }

            if (count($transactions) > 0) {
                echo json_encode(["status" => "failed"]);
                die();
            }
        }

        if ($response->status == "detected") {
            if (empty($cp_order->transaction_id)) {
                $update = $wpdb->update($db_table_name, array('transaction_id' => $response->transaction_id, 'order_status' => 'detected', 'confirmation_no' => $response->confirmations), array('order_id' => $order_id));
                $order->update_status('processing');
            }
        }

        if ($response->status == "confirmed") {
            if ($cp_order->order_status != "confirmed") {
                $update = $wpdb->update($db_table_name, array('transaction_id' => $response->transaction_id, 'order_status' => 'confirmed', 'confirmation_no' => $response->confirmations), array('order_id' => $order_id));
                $order->update_status('completed');
            }
        }

        if ($wpdb->last_error) {
            wc_add_notice('Unable to record transaction. Please contact the shop owner.', 'error');
            wc_print_notices();
            exit;
        }

        echo json_encode($response);
        die();
    } else {
        echo json_encode(["status" => "failed"]);
        die();
    }
}

add_action("wp_ajax_znz_verify_payment", "znz_verify_payment");
add_action("wp_ajax_nopriv_znz_verify_payment", "znz_verify_payment");



// Get information about the recorded order
function znz_get_cp_order_info($order_id)
{
    global $wpdb;
    $db_table_name = $wpdb->prefix . ZNZ_ORDERS_TABLE_NAME;

    $result = $wpdb->get_results($wpdb->prepare("SELECT * FROM $db_table_name WHERE order_id = %d", $order_id));

    if ($wpdb->last_error) {
        wc_add_notice('Unable to retrieve order details.', 'error');
        wc_print_notices();
        exit;
    }

    return $result[0];
}


// Get information about the remaining time for order
function znz_order_remaining_time($order_id)
{
    global $wpdb;
    $db_table_name = $wpdb->prefix . ZNZ_ORDERS_TABLE_NAME;

    $wc_znz = new WC_Zenzo;
    $max_time_limit = $wc_znz->max_time_limit * 60; // In seconds

    $now = new DateTime();
    $order_time = $wpdb->get_var($wpdb->prepare("SELECT order_time FROM $db_table_name WHERE order_id = %d", $order_id));

    if ($wpdb->last_error) {
        wc_add_notice('There has been an error processing your order. Please try again.', 'error');
        wc_print_notices();
        exit;
    }

    $time = $max_time_limit - ($now->getTimestamp() - $order_time);

    return $time;
}



// Create order total
function znz_order_total_in_crypto($amount, $rate)
{
    $difference = 0.00002;

    // Different decimal points based on rate
    if ($rate > 100) {
        $difference = 0.00000002;
        $total = number_format($amount / $rate, 8, '.', '');
    } else {
        $difference = 0.00002;
        $total = number_format($amount / $rate, 5, '.', '');
    }

    // Create unique amount for payment
    $wc_znz = new WC_Zenzo;
    $max_time_limit = $wc_znz->max_time_limit * 60;

    global $wpdb;
    $db_table_name = $wpdb->prefix . ZNZ_ORDERS_TABLE_NAME;
    $safe_period = $max_time_limit * 3;

    $other_amounts = $wpdb->get_results("SELECT order_in_crypto FROM $db_table_name WHERE order_status <> 'confirmed' AND order_time > (UNIX_TIMESTAMP(NOW()) - $safe_period)");

    if ($wpdb->last_error) {
        wc_add_notice('There has been an error processing your order. Please try again.', 'error');
        wc_print_notices();
        exit;
    }

    foreach ($other_amounts as $amount) {
        if ($total == $amount->order_in_crypto) {
            if ($rate > 100) {
                $total = number_format($total  + $difference, 8, '.', '');
            } else {
                $total = number_format($total  + $difference, 5, '.', '');
            }
        }
    }

    return $total;
}



// Order received text
function znz_order_received_text($text, $order)
{
    if ($order->has_status('completed')) {
        $new = 'Thank you. Your order has been received!';
    } else {
        $new = '';
    }
    return $new;
}

add_filter('woocommerce_thankyou_order_received_text', 'znz_order_received_text', 10, 2);



// Plugin directory path
function znz_plugin_path()
{
    return untrailingslashit(plugin_dir_path(__FILE__));
}

add_filter('woocommerce_locate_template', 'znz_woocommerce_locate_template', 10, 3);



// Woocommerce plugin path in plugin
function znz_woocommerce_locate_template($template, $template_name, $template_path)
{
    global $woocommerce;

    $_template = $template;

    if (!$template_path) {
        $template_path = $woocommerce->template_url;
    }

    $plugin_path  = znz_plugin_path() . '/woocommerce/';

    $template = locate_template(
        array(
            $template_path . $template_name,
            $template_name
        )
    );

    // Get the template from plugin, if it exists
    if (file_exists($plugin_path . $template_name)) {
        $template = $plugin_path . $template_name;
    }

    // Use default template
    if (!$template) {
        $template = $_template;
    }

    // Return template
    return $template;
}



// Add settings link
function znz_add_plugin_page_settings_link($links)
{
    $links[] = '<a href="' .
        admin_url('admin.php?page=wc-settings&tab=checkout&section=zenzo_payment') .
        '">' . __('Settings') . '</a>';
    return $links;
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'znz_add_plugin_page_settings_link');
