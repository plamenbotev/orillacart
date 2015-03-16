<?php
/**
 * @package com_shop
 * @version 1.0
 */
/*
  Plugin Name: payondelivery
  Plugin URI: http://orillacart.com
  Description: pay on delivery
  Author: orillacart.com Team
  Version: 1.0
  Author URI: http://orillacart.com/
 */

if (!function_exists('payondelivery_init')) {
    add_action('plugins_loaded', 'payondelivery_init', 12);

    function payondelivery_init() {

        if (class_exists('payment_method')) {

            class payondelivery extends pod_method {

                public function print_options() {
                    ?>

                    <fieldset class="panelform">
                        <ul class="adminformlist">
                            <li>
                                <label for="payondelivery_fields">
                                    Fields info:   
                                </label>
                                <textarea type="text" name="params[payondelivery_fields]" id="payondelivery_fields"><?php echo $this->params->get('payondelivery_fields', ''); ?></textarea>
                            </li>
                            <li>
                                <label for="payondelivery_onreceipt">
                                    Print on receipt:
                                </label>
                                <textarea type="text" name="params[payondelivery_onreceipt]" id="payondelivery_onreceipt"><?php echo $this->params->get('payondelivery_onreceipt', ''); ?></textarea>
                            </li>



                        </ul>
                    </fieldset>
                    <?php
                }

                public function fields() {
                    return $this->params->get('payondelivery_fields', '');
                }

                public function on_receipt($order=null) {
                    return $this->params->get('payondelivery_onreceipt', '');
                }

                public function do_payment($order_id, $order) {
                    
                }

                static public function register(array $methods) {
                    $methods[] = new self();

                    return $methods;
                }

            }

            add_filter('register_payment_class', 'payondelivery::register');
        }
    }

}