<?php
/**
 * @package com_shop
 * @version 1.0
 */
/*
  Plugin Name: cheque
  Plugin URI: http://orillacart.com
  Description: cheque payment option
  Author: orillacart.com Team
  Version: 1.0
  Author URI: http://orillacart.com/
 */

if (!function_exists('cheque_init')) {
    add_action('plugins_loaded', 'cheque_init', 12);

    function cheque_init() {

        if (class_exists('payment_method')) {

            class cheque extends pod_method {

                public function print_options() {
                    ?>

                    <fieldset class="panelform">
                        <ul class="adminformlist">
                            <li>
                                <label for="cheque_fields">
                                    Fields info:   
                                </label>
                                <textarea type="text" name="params[cheque_fields]" id="cheque_fields"><?php echo $this->params->get('cheque_fields', ''); ?></textarea>
                            </li>
							<li>
                                <label for="cheque_onreceipt">
                                   Print on receipt: 
                                </label>
                                <textarea type="text" name="params[cheque_onreceipt]" id="cheque_onreceipt"><?php echo $this->params->get('cheque_onreceipt', ''); ?></textarea>
                            </li>
							
							
							
                        </ul>
                    </fieldset>
                    <?php
                }

                public function fields() {
                    return $this->params->get('cheque_fields', '');
                }
				
				public function on_receipt(){
                    return $this->params->get('cheque_onreceipt', '');
                }

                public function do_payment($order_id, $order) {

                }
				
				static public function register_method($methods) {
                    $methods[] = new self();

                    return $methods;
                }

            }

            add_filter('register_payment_class', 'cheque::register_method');
        }
    }

}