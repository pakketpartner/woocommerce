<?php
/**
 * Plugin Name: Pakketpartner
 * Plugin URI: https://pakketpartner.nl
 * Description: Maak eenvoudig verzendlabels aan voor DPD en bied klanten de mogelijkheid om een afhaallocatie te kiezen
 * Version: 0.0.1
 * Author: Pakketpartner.nl
 * Author URI: https://pakketpartner.nl/
 * Requires at least: 3.8
 * Tested up to: 4.5.3
 * License: GPLv2 or later
 */

if (in_array('woocommerce-pakketpartner/woocommerce-pakketpartner.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('woocommerce_shipping_init', 'pakketpartner_init');
    function pakketpartner_init()
    {
        require_once('includes/pp-shipping-method.php');
    }

    add_filter('woocommerce_get_settings_shipping', 'add_pakketpartner_settings', 10, 2);
    function add_pakketpartner_settings($settings, $section)
    {
        $settings[] = [
            'title' => 'Pakketparter opties',
            'type' => 'title',
            'id' => 'paketpartner_options',
        ];

        $settings['api-key-setting'] = [
            'title' => 'Pakketpartner API key',
            'desc' => 'Deze api key kun je aanvragen bij Pakketpartner',
            'id' => 'pakketpartner_api_key',
            'type' => 'text',
            'autoload' => '',
        ];

        if (get_option('pakketpartner_api_key')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'http://pakketpartner.app/api/v1/carrier_services');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            curl_setopt($ch, CURLOPT_USERPWD, get_option('pakketpartner_api_key') . ":");
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_HEADER, false);

            $result = json_decode(curl_exec($ch));

            if (!$result) {
                $settings['api-key-setting']['desc'] = '(Ongeldige API key)';
            } else {
                $settings['api-key-setting']['desc'] = '(Geldige API key)';
            }
        }

        $settings[] = [
            'type' => 'sectionend',
            'id' => 'pakketpartner_options'
        ];

        return $settings;
    }

    add_filter('woocommerce_shipping_methods', 'pp_add_pakketpartner');
    function pp_add_pakketpartner($methods)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://pakketpartner.app/api/v1/carrier_services');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_USERPWD, get_option('pakketpartner_api_key') . ":");
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HEADER, false);

        $result = json_decode(curl_exec($ch));

        if (!$result) {
            return;
        }

        $supportsPickup = false;
        foreach ($result->data as $carrierService) {
            if ($carrierService->supports_pickup) {
                $supportsPickup = true;
            } else {
                $methods[$carrierService->id] = new PP_Shipping_Method(0, ['id' => $carrierService->id, 'title' => $carrierService->carrier_service_name]);
            }
        }

        if ($supportsPickup) {
            $methods['pp_pickup_carrier_service'] = new PP_Shipping_Method(0, ['id' => 'pp_pickup_carrier_service', 'title' => 'Afhaallocatie']);
        }

        return $methods;
    }

    add_action('woocommerce_after_checkout_form', 'pp_load_pickup_logic', 10);
    function pp_load_pickup_logic()
    {
        ?>
        <script>
            jQuery(function ($) {
                var script = document.createElement('script');
                script.type = 'text/javascript';
                script.src = 'https://pakketpartner.app/woocommerce_dropdown.js?version=<?=WC()->version?>&token=[token]';
                $('body').prepend(script);
            });
        </script>
        <?php
    }

    add_action('woocommerce_after_order_notes', 'pp_add_custom_checkout_fields');
    function pp_add_custom_checkout_fields($checkout)
    {
        echo '<div style="display:none">';
        woocommerce_form_field('pp_pickup_location_hash', [
            'type' => 'text',
        ], $checkout->get_value('pp_pickup_location_hash'));

        woocommerce_form_field('pp_pickup_location_name', [
            'type' => 'text',
        ], $checkout->get_value('pp_pickup_location_name'));

        woocommerce_form_field('pp_account_carrier_service_hash', [
            'type' => 'text',
        ], $checkout->get_value('pp_account_carrier_service_hash'));
        echo '</div>';
    }

    add_action('woocommerce_checkout_update_order_meta', 'pp_update_order_meta');
    function pp_update_order_meta($order_id)
    {
        if (!empty($_POST['pp_pickup_location_hash'])) {
            update_post_meta($order_id, 'pp_pickup_location_hash', sanitize_text_field($_POST['pp_pickup_location_hash']));
        }

        if (!empty($_POST['pp_pickup_location_name'])) {
            update_post_meta($order_id, 'pp_pickup_location_name', sanitize_text_field($_POST['pp_pickup_location_name']));
        }

        if (!empty($_POST['pp_account_carrier_service_hash'])) {
            update_post_meta($order_id, 'pp_account_carrier_service_hash', sanitize_text_field($_POST['pp_account_carrier_service_hash']));
        }
    }

    /** ************** **/
    /** ADMINISTRATOR **/

    add_action('woocommerce_admin_order_data_after_shipping_address', 'pp_show_shipment_details_in_admin', 10, 1);
    function pp_show_shipment_details_in_admin($order)
    {
        echo '<p><strong>Afhaallocatie:</strong> <br/> ' . get_post_meta($order->id, 'pp_pickup_location_name', true) . '</p>';
        echo '<p><strong>Verzendlabel:</strong> <br/> <a href="' . get_post_meta($order->id, 'pp_pdf_label_url', true) . '">Download</a></p>';
        echo '<p><strong>Trackingcode:</strong> <br/> ' . get_post_meta($order->id, 'pp_tracking_code', true) . '</p>';
        echo '<p><strong>Tracking url:</strong> <br/><a target="_blank" href="' . get_post_meta($order->id, 'pp_tracking_url', true) . '">' . get_post_meta($order->id, 'pp_tracking_url', true) . '</a></p>';
    }

    add_filter('woocommerce_admin_order_actions', 'create_shipment_ajax_requests', PHP_INT_MAX, 2);
    function create_shipment_ajax_requests($actions, $order)
    {
        ?>
        <script>
            jQuery(function ($) {
                if (typeof loaded == 'undefined') {
                    $('.request-label').unbind('click').click(function (e) {
                        url = $(this).attr('href');
                        $(this).addClass('loading');
                        e.preventDefault();

                        $.ajax({
                            type: 'POST',
                            dataType: 'json',
                            url: url,
                            success: function (response) {
                                if (!response.success) {
                                    // TODO: Show a nice message instead of a default alertbox
                                    alert(response.data);
                                    location.reload();
                                } else {
                                    location.reload();
                                }
                            }
                        });

                        return false;
                    });
                }
            });
        </script>
        <?php

        return $actions;
    }

    add_filter('woocommerce_admin_order_actions', 'add_pakketpartner_request_and_print_button', PHP_INT_MAX, 2);
    function add_pakketpartner_request_and_print_button($actions, $the_order)
    {
        $actions['request_label'] = [
            'url' => dirname(plugin_dir_url(__FILE__)) . '/woocommerce-pakketpartner/includes/pp-request-label.php?order_id=' . $the_order->id,
            'name' => 'Verzendlabel aanvragen',
            'action' => 'view request-label',
        ];

        if (get_post_meta($the_order->id, 'pp_pdf_label_url', true)) {
            $actions['print_label'] = [
                'url' => get_post_meta($the_order->id, 'pp_pdf_label_url', true),
                'target' => '_blank',
                'name' => 'Verzendlabel downloaden',
                'action' => 'view print-label',
            ];
        }

        return $actions;
    }

    add_action('admin_head', 'pakketpartner_request_and_print_button_css');
    function pakketpartner_request_and_print_button_css()
    {
        $requestLabelIconUrl = dirname(plugin_dir_url(__FILE__)) . '/woocommerce-pakketpartner/assets/icons/label-icon.png';
        $printLabelIconUrl = dirname(plugin_dir_url(__FILE__)) . '/woocommerce-pakketpartner/assets/icons/print-icon.png';
        $loadingIconUrl = dirname(plugin_dir_url(__FILE__)) . '/woocommerce-pakketpartner/assets/icons/spinner.gif';

        echo "<style>.view.request-label::after { content: url($requestLabelIconUrl); }</style>";
        echo "<style>.view.request-label.loading::after { content: url($loadingIconUrl); }</style>";
        echo "<style>.view.print-label::after { content: url($printLabelIconUrl); }</style>";
    }
}