<?php
/**
 * @package com_shop
 * @version 1.0
 */
/*
  Plugin Name: banktransfer
  Plugin URI: http://orillacart.com
  Description: bank transfer
  Author: orillacart.com Team
  Version: 1.0
  Author URI: http://orillacart.com/
 */

if (!function_exists('banktransfer_init')) {
    add_action('plugins_loaded', 'banktransfer_init', 12);

    function banktransfer_init() {

        if (class_exists('payment_method')) {

            class banktransfer extends pod_method {

                public function print_options() {
                    ?>

                    <fieldset class="panelform">
                        <ul class="adminformlist">
                            <li>
                                <label for="banktransfer_fields">
                                    Fields info:   
                                </label>
                                <textarea type="text" name="params[banktransfer_fields]" id="banktransfer_fields"><?php echo $this->params->get('banktransfer_fields', ''); ?></textarea>
                            </li>
							<li>
                                <label for="banktransfer_onreceipt">
                                   Print on receipt:   
                                </label>
                                <textarea type="text" name="params[banktransfer_onreceipt]" id="banktransfer_onreceipt"><?php echo $this->params->get('banktransfer_onreceipt', ''); ?></textarea>
                            </li>
							
							
							
                        </ul>
                    </fieldset>
                    <?php
                }

                public function fields() {
                    return $this->params->get('banktransfer_fields', '');
                }
				
				public function on_receipt(){
                    return $this->params->get('banktransfer_onreceipt', '');
                }

                public function do_payment($order_id, $order) {

                }
				
				static public function register_method($methods) {
                    $methods[] = new self();

                    return $methods;
                }

            }

            add_filter('register_payment_class', 'banktransfer::register_method');
        }
    }

}