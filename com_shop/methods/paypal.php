<?php
/**
 * @package com_shop
 * @version 1.0
 */
/*
  Plugin Name: paypal standart
  Plugin URI: http://orillacart.com
  Description: paypal standart form based
  Author: orillacart.com Team
  Version: 1.0
  Author URI: http://orillacart.com/
 */

if (!function_exists('paypal_init')) {
    add_action('plugins_loaded', 'paypal_init', 12);

    function paypal_init() {

        if (class_exists('payment_method')) {

            class paypal extends form_method {

                public function print_options() {
                    ?>

                    <fieldset class="panelform">
                        <ul class="adminformlist">
                            <li>
                                <label for="paypal_debug_mode">
                                    Debug mode:                  
                                </label>
                                <select name="params[paypal_debug_mode]" id="paypal_debug_mode">
                                    <option value="0" <?php echo ($this->params->get('paypal_debug_mode', 0) == 0 ? "selected='selected'" : ""); ?>>No</option>
                                    <option value="1" <?php echo ($this->params->get('paypal_debug_mode', 0) == 1 ? "selected='selected'" : ""); ?>>Yes</option>
                                </select>
                            </li>


                            <li>
                                <label for="paypal_merchant_email">
                                    merchant email:           
                                </label>
                                <input type="text" name="params[paypal_merchant_email]" id="paypal_merchant_email" value="<?php echo $this->params->get('paypal_merchant_email', ''); ?>" />
                            </li>
                            <li>
                                <label for="paypal_fields">
                                    Fields info:   
                                </label>
                                <textarea type="text" name="params[paypal_fields]" id="paypal_fields"><?php echo $this->params->get('paypal_fields', ''); ?></textarea>
                            </li>
                        </ul>
                    </fieldset>
                    <?php
                }

                public function fields() {
                    return $this->params->get('paypal_fields', '');
                }

                public function do_payment($order_id, $order) {


                    $order_r = new Registry($order);

                    if ($this->params->get('paypal_debug_mode', 0) == '1') {
                        $paypalurl = "https://www.sandbox.paypal.com/cgi-bin/webscr";
                    } else {
                        $paypalurl = "https://www.paypal.com/cgi-bin/webscr";
                    }


                    if (in_array($order_r->get("billing_country", ""), array('US', 'CA'))) {
                        $order_r->set("billing_phone", str_replace(array('( ', '-', ' ', ' )', '.'), '', $order_r->get("billing_phone", "")));
                        $phone_args = array(
                            'night_phone_a' => substr($order_r->get("billing_phone"), 0, 3),
                            'night_phone_b' => substr($order_r->get("billing_phone"), 3, 3),
                            'night_phone_c' => substr($order_r->get("billing_phone"), 6, 4),
                            'day_phone_a' => substr($order_r->get("billing_phone"), 0, 3),
                            'day_phone_b' => substr($order_r->get("billing_phone"), 3, 3),
                            'day_phone_c' => substr($order_r->get("billing_phone"), 6, 4)
                        );
                    } else {
                        $phone_args = array(
                            'night_phone_b' => $order_r->get("billing_phone"),
                            'day_phone_b' => $order_r->get("billing_phone")
                        );
                    }



                    $post_variables = array_merge(
                            array(
                        'cmd' => '_cart',
                        'business' => $this->params->get("paypal_merchant_email", ''),
                        'no_note' => 1,
                        'currency_code' => Factory::getApplication('shop')->getParams()->get('currency'),
                        'charset' => 'UTF-8',
                        'rm' => is_ssl() ? 2 : 1,
                        'upload' => 1,
                        'return' => Route::get('component=shop&con=cart&task=order_details&order_id=' . $order_id),
                        'cancel_return' => Route::get('component=shop&con=cart&task=order_details&order_id=' . $order_id),
                        'page_style' => "",
                        // Order key + ID
                        'invoice' => $order_id,
                        'custom' => $order_id,
                        // IPN
                        'notify_url' => Route::get('component=shop&con=cart&task=gateway_notify&gateway=paypal&order_id=' . $order_id),
                        // Billing Address info
                        'first_name' => $order_r->get("billing_first_name", ""),
                        'last_name' => $order_r->get("billing_last_name", ""),
                        //'company'				=> $order->billing_company,
                        'address1' => $order_r->get('billing_address', ''),
                        'address2' => $order_r->get('billing_address', ''),
                        'city' => $order_r->get("billing_city", ""),
                        'state' => $order_r->get("billing_state", ""),
                        'zip' => $order_r->get("billing_zipcode", ""),
                        'country' => $order_r->get("billing_country", ""),
                        'email' => $order_r->get("billing_email", "")
                            ), $phone_args
                    );


                    if (Factory::getApplication('shop')->getParams()->get('shipping')) {

                        $post_variables["address1"] = $order_r->get('shipping_address', '');
                        $post_variables["address2"] = $order_r->get('shipping_address', '');
                        $post_variables["country"] = $order_r->get('shipping_country', '');
                        $post_variables["first_name"] = $order_r->get('shipping_first_name', '');
                        $post_variables["last_name"] = $order_r->get('shipping_last_name', '');
                        $post_variables["state"] = $order_r->get('shipping_state', '');
                        $post_variables["zip"] = $order_r->get('shipping_zipcode', '');
                        $post_variables["no_shipping"] = 0;
                    } else {
                        $post_variables['no_shipping'] = 1;
                    }


                    $db = Factory::getDBO();


                    $shipping = $order_r->get("order_shipping", 0);

                    $db->setQuery("SELECT * FROM #_shop_order_item WHERE order_id = {$order_id} ");

                    $items = $db->loadObjectList();

                    $post_variables['tax_cart'] = round($order_r->get("order_tax", 0), 2);

                    $total_items = count($items);
					$i = 0;
                    for (; $i < $total_items; $i++) {
                        $item = $items[$i];


                        $post_variables["item_name_" . ($i + 1)] = strip_tags($item->order_item_name);
                        $post_variables["quantity_" . ($i + 1)] = $item->product_quantity;
                        $post_variables["amount_" . ($i + 1)] = number_format($item->product_item_price , 2, '.', '');
                    }


                    if ($shipping > 0 && Factory::getApplication('shop')->getParams()->get('shipping')) {

                        $post_variables['item_name_' . ($i + 1)] = __('Shipping via', 'com_shop') . ' ' . ucwords($order_r->get("shipping_name", ""));
                        $post_variables['quantity_' . ($i + 1)] = '1';
                        $post_variables['amount_' . ($i + 1)] = number_format(round($shipping, 2), 2, '.', '');
                    }



                    echo "<form action='$paypalurl' method='post' name='paypalfrm' id='paypalfrm'>";

                    foreach ($post_variables as $name => $value) {
                        echo "<input type='hidden' name='$name' value='$value' />";
                    }



                    echo "<noscript><input type='submit' value='click to pay' name='pay' /></noscript>";
                    echo "</form>";
                    ?>
                    <div style='margin:0 auto; font-weight:bold; width:100%; text-align:center;'>Please wait, you will be redirected to paypal shortly!</div>
                    <script type='text/javascript'>document.paypalfrm.submit();</script>

                    <?php
                }

                public function handle_notify() {




                    $res = new stdClass();
                    $res->msg = '';
                    $res->order_status = '';
                    $res->order_id = (int) $_POST['custom'];
                    $res->tid = $_POST['txn_id'];
                    $res->tid = $_POST['txn_id'];

                    if (!$res->order_id)
                        wp_die("No order id");


                    $order = Table::getInstance("order", "shop")->load($res->order_id);

                    if ($this->check_ipn_request_is_valid($order)) {

                        header('HTTP/1.1 200 OK');

                        switch (strtolower($_POST['payment_status'])) {
                            case 'completed' :
                                // Payment completed
                                $res->order_status = "completed";
                                break;
                            case 'denied' :
                            case 'expired' :
                            case 'failed' :
                            case 'voided' :
                                $res->order_status = "failed";
                                break;
                            default:
                                // No action
                                break;
                        }
                    }

                    return $res;
                }

                public function on_after_gateway_notify($res, $status) {
                    //stop the execution, ipn is handled and no need to show the receipt
                    wp_die("Notify handled");
                }

                public function check_ipn_request_is_valid($order) {


                    // Get recieved values from post data


                    $received_values = stripslashes_deep($_POST);
                    $received_values += array('cmd' => '_notify-validate');
                    unset($received_values['component'], $received_values['con'], $received_values['task']);
                    // Send back post vars to paypal
                    $params = array(
                        'body' => $received_values,
                        'sslverify' => false,
                        'timeout' => 60,
                        'httpversion' => '1.1',
                    );



                    // Get url
                    if ($this->params->get('paypal_debug_mode', 0) == '1') {
                        $paypalurl = "https://www.sandbox.paypal.com/cgi-bin/webscr";
                    } else {
                        $paypalurl = "https://www.paypal.com/cgi-bin/webscr";
                    }

                    // Post back to get a response
                    $response = wp_remote_post($paypalurl, $params);

                    // Clean
                    unset($_POST['cmd']);

                    // check to see if the request was valid
                    if (!is_wp_error($response) && $response['response']['code'] >= 200 && $response['response']['code'] < 300 && (strcmp($response['body'], "VERIFIED") == 0)) {

                        if (trim($_POST['receiver_email']) != trim($this->params->get("paypal_merchant_email", ''))) {


                            return false;
                        }
                        if ((float) $_POST['mc_gross'] != (float) $order->get("order_total")) {

                            return false;
                        }

                        $db = Factory::getDBO();

                        $db->setQuery("SELECT count(a.post_id)
										FROM #_postmeta AS a
										INNER JOIN #_postmeta AS b ON a.post_id = b.post_id
										WHERE a.meta_key = '_payment_method' AND a.meta_value = 'paypal' AND b.meta_key = '_tid' AND b.meta_value = '" . $db->secure($_POST['txn_id']) . "' AND a.post_id != " . (int) $order->ID);


                        if ((int) $db->loadResult() > 0) {
                            return false;
                        }


                        //All manupulation checks are passed and that should be genuine paying custumer

                        return true;
                    }

                    return false;
                }

                static public function register_method($methods) {
                    $methods[] = new self();

                    return $methods;
                }

            }

            add_filter('register_payment_class', 'paypal::register_method');
        }
    }

}
