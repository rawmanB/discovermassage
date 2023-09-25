<?php
/*
Plugin Name: Woocommerce Deposits Stripe Payment Automation
Plugin URI: https://www.cogdigital.com.au
Description: Woocommerce deposits automatic partial payment after set date.
Version: 1.0.0
Author: COGDigital
Author URI:   https://www.cogdigital.com.au
Copyright: © 2022, COGDigital.
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    if (!class_exists('Wc_Deposits_Stripe_Automation')) {
        class Wc_Deposits_Stripe_Automation
        {

            public function __construct()
            {
                //disable payment after X days
                define('WC_DEPOSITS_2885951_TEMPLATE_PATH', untrailingslashit(plugin_dir_path(__FILE__)) . '/templates/');
                define('WC_DEPOSITS_2885951_PLUGIN_PATH', plugin_dir_path(__FILE__));
                define('WC_DEPOSITS_2885951_PLUGIN_URL', untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))));
                define('WC_DEPOSITS_2885951_MAIN_FILE', __FILE__);

                //last deposit date hook
                add_filter('wc_deposits_product_enable_deposit', array($this, 'deposit_date_controller'), 10, 2);
                add_action('woocommerce_update_options_wc-deposits', array($this, 'update_options_wc_deposits'),21);

                //part  payment automation
                define('WC_DEPOSITS_STRIPE_AUTOMATION_TEMPLATE_PATH', untrailingslashit(plugin_dir_path(__FILE__)) . '/templates/');
                define('WC_DEPOSITS_STRIPE_AUTOMATION_PLUGIN_PATH', plugin_dir_path(__FILE__));
                define('WC_DEPOSITS_STRIPE_AUTOMATION_PLUGIN_URL', untrailingslashit(plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__))));
                define('WC_DEPOSITS_STRIPE_AUTOMATION_MAIN_FILE', __FILE__);

                // Enable automatic payment
                add_action('wc_deposits_settings_tabs_general_tab', array($this, 'general_tab'), 21);
                add_action('woocommerce_update_options_wc-deposits', array($this, 'save_data_te')); // save the updated checkbox

                // Hook into that action that'll fire every 12 hours
                add_action('isa_add_twice_daily', array($this, 'payment_plan_partial_payment_stripe'));

            }

            public function save_data_te()
            {
                $val = isset($_POST['wc_deposits_stripe_automation_enable']) ? $_POST['wc_deposits_stripe_automation_enable'] : false;
                update_option('wc_deposits_stripe_automation_enable', $val);

            }

            public function general_tab()
            {
                $value = get_option('wc_deposits_stripe_automation_enable');

                $arr = ($value == 1) ? ['checked' => 'checked'] : [];
                $days = ( get_option('wc_deposits_2885951_number_of_days') != '') ?  get_option('wc_deposits_2885951_number_of_days') : '30';

                $args = array(
                    'taxonomy' => 'product_cat',
                    'hide_empty' => false,
                );

                // Adding field to woocommerce deposits backend
                $settings = array(
                    '2885951_title' => array(
                        'name' => __('Customization disable part payment', 'woocommerce-deposits'),
                        'type' => 'title',
                        'desc' => __('Settings added by customization disable part payment', 'woocommerce-deposits'),
                        'id' => 'wc_deposits_messages_title'
                    ),
                    'wc_deposits_2885951_number_of_days' => array(
                        'name' => __('Disable deposit x days before course expire date', 'woocommerce-deposits'),
                        'type' => 'number',
                        'desc' => __('Disable deposit x days before course expire date', 'woocommerce-deposits'),
                        'id' => 'wc_deposits_2885951_number_of_days',
                        'value' => $days,
                        'default' => '30'
                    ),
                    '2885951_end' => array(
                        'type' => 'sectionend',
                        'id' => 'wc_deposits_2885951_end'
                    ),
                    'wc_deposit_stripe_automation_title' => array(
                        'name' => __('WooCommerce Deposits Stripe Automation Payment', 'woocommerce-deposits'),
                        'type' => 'title',
                        'desc' => __('Stripe Automation Enabled by Plugin', 'woocommerce-deposits'),
                        'id' => 'wc_deposits_messages_title',
                    ),
                    'wc_deposits_stripe_automation_enable' => array(
                        'name' => __('Enable Woocommerce deposit stripe automation', 'woocommerce-deposits'),
                        'type' => 'checkbox',
                        'desc' => __('Stripe Automation Enabled by Plugin', 'woocommerce-deposits'),
                        'id' => 'wc_deposits_stripe_automation_enable',
                        'value' => $value,
                        'custom_attributes' => $arr,
                    ),
                    'wc_deposit_stripe_automation_end' => array(
                        'type' => 'sectionend',
                        'id' => 'wc_deposits_stripe_automation_end',
                    ),
                    
                );

                woocommerce_admin_fields($settings);
            }

            public function update_options_wc_deposits()
            {
                $days = isset($_POST['wc_deposits_2885951_number_of_days']) ? $_POST['wc_deposits_2885951_number_of_days'] : '30';
                update_option('wc_deposits_2885951_number_of_days', $days);
            }


            function deposit_date_controller($enable, $product_id)
            {
                $product = wc_get_product($product_id);
                $expire_date = $product->get_meta('course_expire_date');
                if (empty($expire_date)) return $enable;
                $min_days = get_option('wc_deposits_2885951_number_of_days', '30');

                $date = new DateTime($expire_date);
                $current_date = new DateTime();
                $diff = $current_date->diff($date);
                if ($diff->days < $min_days) {
                        $enable = false;
                }


                return $enable;
            }

            // Function to check the wc_deposits next job schedule
            public function payment_plan_partial_payment_stripe_check_schedular()
            {
                $queue = WC()->queue()->get_next('wc_deposits_job_scheduler');
                // Incase needed to add schedule for manual process

                return $queue;

            }

            public function payment_plan_partial_payment_stripe()
            {

                $target_ids = [];
                $date = date("d-m-Y", current_time('timestamp'));

                // date for product expiring in next 14 days
                $target_due_date = strtotime("$date + 14 day");
                $target_due_date = date('Ymd', $target_due_date);
                $today_date = date('Ymd', $date);

                //get id of products which starts in 14 days from today ie due date for payment is today

                global $wpdb;

                $args = "SELECT  DISTINCT wp_posts.ID FROM wp_posts  INNER JOIN wp_postmeta ON ( wp_posts.ID = wp_postmeta.post_id )  INNER JOIN wp_postmeta AS mt1 ON ( wp_posts.ID = mt1.post_id )  INNER JOIN wp_postmeta AS mt2 ON ( wp_posts.ID = mt2.post_id ) WHERE 1=1
                AND wp_posts.post_type = 'wcdp_payment' AND ((wp_posts.post_status = 'wc-pending'))
                AND (
                ( wp_postmeta.meta_key = '_wc_deposits_payment_type') AND (wp_postmeta.meta_value = 'partial_payment' ))
                AND
                ( mt1.post_id IN (SELECT wpoi.order_id from wp_woocommerce_order_items as wpoi
                JOIN wp_woocommerce_order_itemmeta as wpom ON (wpoi.order_item_id = wpom.order_item_id)
                WHERE  ( wpom.meta_key = '_product_id' AND wpom.meta_value IN (SELECT  DISTINCT wp_posts.id FROM wp_posts  INNER JOIN wp_postmeta ON ( wp_posts.ID = wp_postmeta.post_id )  WHERE 1=1
                    AND
                    ( wp_postmeta.meta_key = 'course_expire_date' AND wp_postmeta.meta_value <= '" . $target_due_date . "'  AND wp_postmeta.meta_value >= '" . $today_date . "' 
                    ) AND wp_posts.post_type = 'product'  GROUP BY wp_posts.ID ORDER BY wp_posts.post_date))))  ";

                $target_id = (array) $wpdb->get_results($args);

                foreach ($target_id as $t_id) {
                    array_push($target_ids, $t_id->ID);
                }

                //Check if array is empty and replace with "1" so the query runs with "post__in" condition.
                //Empty array causes the query to be not run with the "post__in" parameter
                $target_ids = (!empty($target_ids)) ? $target_ids : '1';
                // Prepare arguments/query parameters to pull posts from wp_post and values from wp_postmeta
                // Get all pending payments for the day
                $args = array(
                    'post_type' => 'wcdp_payment',
                    'post_status' => array('wc-pending'),
                    'posts_per_page' => -1,
                    'post__in' => $target_ids,

                );

                // This query pull all the records from database after passing the above arguments
                $partial_payments = new WP_Query($args);

                // Setup Woocommerce stripe client
                $settings = stripe_wc()->api_settings;
                $mode = $settings->settings['mode'];
                $test_secret_key = $settings->settings['secret_key_test'];
                $live_secret_key = $settings->settings['secret_key_live'];
                $secret_key = ($mode == "test") ? $test_secret_key : $live_secret_key;

                $stripe = new \Stripe\StripeClient($secret_key);

                // Loop through the pulled posts from above WP_Query
                while ($partial_payments->have_posts()):
                    $partial_payments->the_post();

                    // Get order ID from post ID
                    $order_id = $partial_payments->post->ID;

                    // Get order details using above order_id
                    $order = wc_get_order($order_id);
                    //Check if a order has partial payment/deposits
                    $order_has_deposit = get_post_meta($order->parent_id, '_wc_deposits_order_has_deposit', true);

                    // Check the payment schedule date
                    $payment_schedule = get_post_meta($order->parent_id, '_wc_deposits_payment_schedule', true);

                    if (($order_has_deposit === 'yes') && !empty($payment_schedule)) {
                        // Get wordpress customer ID from order to extract stripe customer_id for the customer
                        $order_customer_id = $order->get_customer_id();

                        // Saved card customers will have Customer ID in the database provided by Stripe
                        // Get stripe customer ID
                        $stripe_customer_id_array = ($mode == "test") ? get_user_meta($order_customer_id, 'wp_wc_stripe_customer_test', null) : get_user_meta($order_customer_id, 'wp_wc_stripe_customer_live', null);
                        $stripe_customer_id = $stripe_customer_id_array[0];

                        //Get all the payment methods for the customer
                        $payment_methods = $stripe->paymentMethods->all(
                            ['customer' => $stripe_customer_id, 'type' => 'card']
                        );

                        // Retrieve default paymentMethod ID from the above
                        $payment_methods_id = $payment_methods->data[0]->id;

                        foreach ($payment_schedule as $timestamp => $payment) {

                            // Get payment order details
                            $payment_order = false;
                            if (isset($payment['id']) && !empty($payment['id'])) {
                                $payment_order = wc_get_order($payment['id']);
                            }

                            if (!$payment_order) {
                                continue;
                            }

                            $payment_id = $payment_order ? $payment_order->get_order_number() : '-';
                            $status = $payment_order ? wc_get_order_status_name($payment_order->get_status()) : '-';
                            $amount = $payment_order ? $payment_order->get_total() : $payment['total'];
                            $amount = $amount * 100; // Changing into integer
                            $price_args = array('currency' => $payment_order->get_currency());

                            // Check if the payment is pending.
                            if ($status === 'Pending payment' && $payment_methods !='') {

                                try {

                                    // Send payment Intent request for second partial payment.
                                    $payment_intent = $stripe->paymentIntents->create([
                                        'amount' => $amount,
                                        'currency' => $price_args["currency"],
                                        'customer' => $stripe_customer_id,
                                        'payment_method' => $payment_methods_id,
                                        'off_session' => true,
                                        'confirm' => true,
                                        'description' => "Created by " . $payment_id . " from discovermassage",
                                    ]);

                                    // Update status of the order to completed

                                    $order->update_status('completed', 'Order Amount Paid automatically from saved card', true);
                                    $orders = wc_get_order($order->parent_id);
                                    $orders->update_status('completed', 'Order Amount Paid Automatically from saved card', true);

                                } catch (\Stripe\Exception\CardException$e) {
                                    // Error code will be authentication_required if authentication is needed
                                    echo 'Error code is:' . $e->getError()->code;
                                    $payment_intent_id = $e->getError()->payment_intent->id;
                                    $payment_intent = $stripe->paymentIntents->retrieve($payment_intent_id);
                                    $order->update_status('failed', 'Order Amount Failed to charge from saved card', true);
                                } finally {
                                    // Log the results of the paymentIntents created
                                    $logger = wc_get_logger();
                                    $logger->info(wc_print_r("Payment Details for  ID:" . $order_id, true), array('source' => 'test-logger'));
                                    $logger->info(wc_print_r($payment_intent, true), array('source' => 'test-logger'));
                                }
                            }
                        }
                    }

                endwhile;
            }
        }

        return new Wc_Deposits_Stripe_Automation();

    }

}
