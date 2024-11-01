<?php

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins'))))
    return;

/**
 * Plugin Name: Payment Gateway for BenefitPay and WooCommerce
 * Description: Provides an Offline Payment Gateway using BenefitPay Merchant QR code.
 * Version:     1.1
 * Author:      Ahmed Alkooheji
 * Author URI:  https://ahmedalkooheji.com
 * License:     GPL2
 */


add_action('plugins_loaded', 'bpwc_offline_gateway_init', 11);

function bpwc_offline_gateway_init() {

    class BPWC_Gateway_BenefitPay extends WC_Payment_Gateway {

        /**
         * Init and hook in the integration.
         */
        function __construct() {
            global $woocommerce;
			$pluginpath          =   WC()->plugin_url();
			$pluginpath          =   explode('plugins', $pluginpath);
            $this->id = "benefitpay";
			$this->icon               = apply_filters('woocommerce_benefitpay_icon', $pluginpath[0] . 'plugins/woo-benefitpay-payment-gateway/icons/benefitpay.png');
            $this->has_fields = false;
            $this->method_title = __("BenefitPay", 'woocommerce-benefitpay');
            $this->method_description = "Provides an Offline Payment Gateway using BenefitPay Merchant QR code. Display your BenefitPay Merchant QR code on your website and get payment direcly into your BenefitPay Merchant account.";

            //Initialize form methods
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables.
            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            $this->instructions = $this->settings['instructions'];
            $this->benefitpay_qr_url = $this->settings['benefitpay_qr_url'];

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
                add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
            } else {
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
                add_action('woocommerce_thankyou', array(&$this, 'thankyou_page'));
            }
            // Customer Emails
            add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
        }

        // Build the administration fields for this specific Gateway
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce-benefitpay'),
                    'type' => 'checkbox',
                    'label' => __('Enable BenefitPay', 'woocommerce-benefitpay'),
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => __('Title', 'woocommerce-benefitpay'),
                    'type' => 'text',
                    'description' => __('This controls the title for the payment method the customer sees during checkout.', 'woocommerce-benefitpay'),
                    'default' => __('Pay with BenefitPay', 'woocommerce-benefitpay'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce-benefitpay'),
                    'type' => 'textarea',
                    'description' => __('Payment method description that the customer will see on your checkout.', 'woocommerce-benefitpay'),
                    'default' => __('Make your payment directly using BenefitPay QR code. Please add total amount in Enter Amount field in BenefitPay app.', 'woocommerce-benefitpay'),
                    'desc_tip' => true,
                ),
                'instructions' => array(
                    'title' => __('Instructions', 'woocommerce-benefitpay'),
                    'type' => 'textarea',
                    'description' => __('Instructions that will be added to the thank you page and emails.', 'woocommerce-benefitpay'),
                    'default' => "Make your payment directly into our BenefitPay account by scanning the QR code. Please enter total amount in 'Enter Amount' field in BenefitPay app. Your order won't be processed until the funds have cleared in our BenefitPay account",
                    'desc_tip' => true,
                ),
                'benefitpay_qr_url' => array(
                    'title' => __('BenefitPay QR Image URL*', 'woocommerce-benefitpay'),
                    'type' => 'textarea',
                    'description' => __('Upload BenefitPay QR to your media library and Provide the URL here.', 'woocommerce-benefitpay'),
                    'default' => "BenefitPay QR code image Url, to be displayed on Thank you page!!",
                    'desc_tip' => true,
                ),
            );
        }

        public function validate_benefitpay_qr_url_field($key, $value) {
            if (isset($value)) {
                if (filter_var($value, FILTER_VALIDATE_URL) === FALSE) {
                    WC_Admin_Settings::add_error(esc_html__('Please enter a valid BenefitPay QR URL. This image will be displayed on Thank you page to recieve payments.', 'woocommerce-benefitpay'));
                }
            }

            return $value;
        }

        public function process_payment($order_id) {

            $order = wc_get_order($order_id);

            // Mark as on-hold (we're awaiting the payment)
            $order->update_status('on-hold', __('Awaiting offline payment', 'woocommerce-benefitpay'));

            // Reduce stock levels
            $order->reduce_order_stock();

            // Remove cart
            WC()->cart->empty_cart();

            // Return thankyou redirect
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        }

        /**
         * Output for the order received page.
         */
        public function thankyou_page() {
            if ($this->instructions) {
                echo wpautop(wptexturize($this->instructions)). PHP_EOL;
            }
            if ($this->benefitpay_qr_url) {
                echo "<br/>Please scan this QR code using BenefitPay app in your mobile and make payment.<b> Please enter total amount in 'Enter Amount' field in BenefitPay app.</b>";
                echo "<div class='qr_image_class'><img src='" . $this->benefitpay_qr_url . "' alt='benefitpay_qr_code' /></div>";
                echo "<style>.qr_image_class{width:100%;display:block;padding:10px;} .qr_image_class > img{display:block;margin:0 auto;}</style>";
            }
        }

        /**
         * Add content to the WC emails.
         *
         * @access public
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @param bool $plain_text
         */
        public function email_instructions($order, $sent_to_admin, $plain_text = false) {
            if ($this->instructions && !$sent_to_admin && 'benefitpay' === $order->payment_method && $order->has_status('on-hold')) {
                if ($this->instructions) {
                echo wpautop(wptexturize($this->instructions));
            }
            if ($this->benefitpay_qr_url) {
                echo "<br/>Please scan this QR code using BenefitPay app in your mobile and make payment.<b> Please enter total amount in 'Enter Amount' field in BenefitPay app.</b>". PHP_EOL;
                echo "<div class='qr_image_class'><img src='" . $this->benefitpay_qr_url . "' alt='benefitpay_qr_code' /></div>". PHP_EOL;
                echo "<style>.qr_image_class{width:100%;display:block;padding:10px;} .qr_image_class > img{display:block;margin:0 auto;}</style>";
            }
            }
        }

    }

    // Now that we have successfully included our class,
    // Lets add it too WooCommerce
    add_filter('woocommerce_payment_gateways', 'add_benefitpay');

    function add_benefitpay($methods) {
        $methods[] = 'BPWC_Gateway_BenefitPay';
        return $methods;
    }

}
