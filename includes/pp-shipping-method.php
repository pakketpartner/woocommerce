<?php

if (!defined('ABSPATH')) {
    exit;
}

class PP_Shipping_Method extends WC_Shipping_Method
{
    public function __construct($instanceId = 0, $data = [])
    {
        parent::__construct($instanceId);

        global $wpdb;

        if ($instanceId != 0) {
            $methodId = $wpdb->get_row("SELECT method_id FROM wp_woocommerce_shipping_zone_methods WHERE instance_id = " . $instanceId)->method_id;
            $this->instance_id = $instanceId;
            $this->id = $methodId;
            $this->title = $this->get_option('title');
        } else {
            $this->id = $data['id'];
            $this->title = $data['title'];
        }

        $this->method_title = $this->title;
        $this->method_description = 'Verzendmethode ' . $this->title;
        $this->supports = [
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        ];

        $this->init();

        add_action('woocommerce_shipping_zone_method_added', [$this, 'save_default_options']);
        add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
    }

    public function save_default_options($instanceId)
    {
        $this->instance_id = $instanceId;
        $data = ['id' => $this->id, 'title' => $this->title, 'all' => json_encode($this)];
        update_option($this->get_instance_option_key(), apply_filters('woocommerce_shipping_' . $this->id . '_instance_settings_values', $data, $this));
    }

    public function init()
    {
        $this->instance_form_fields = [
            'title' => [
                'title' => 'Titel',
                'type' => 'text',
                'description' => 'Dit is de titel die klanten te zien krijgen in de checkout',
                'default' => $this->title,
                'desc_tip' => true,
            ],
            'cost' => [
                'title' => 'Prijs',
                'type' => 'text',
                'placeholder' => '',
                'default' => '0',
                'desc_tip' => true,
            ],
        ];

        $title = $this->get_option('title');
        if ($title) {
            $this->title = $title;
        }
        $this->cost = $this->get_option('cost');
    }

    public function calculate_shipping($package = [])
    {
        $rate = [
            'id' => $this->get_rate_id(),
            'label' => $this->title,
            'cost' => $this->cost,
            'package' => $package,
        ];

        $this->add_rate($rate);

        do_action('woocommerce_' . $this->id . '_shipping_add_rate', $this, $rate);
    }
}