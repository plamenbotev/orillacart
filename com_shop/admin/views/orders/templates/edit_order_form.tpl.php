<div class="com_shop">
    <input type="hidden" name="update_type" value="" />
    <input type="hidden" name="item_id" value="" />
    <input type="hidden" id="customer_id" name="customer_id" value="<?php echo $this->order->customer_id; ?>" />
    <div id="add_product_modal" style="display:none;"></div>

    <table border="0" cellspacing="0" cellpadding="0" class="wp-list-table widefat">
        <tbody>

            <tr>
                <td width="100"><?php _e('Order id:', 'com_shop'); ?></td>
                <td><?php echo $this->order->pk(); ?></td>
            </tr>
            <tr>
                <td><?php _e('Order Date:', 'com_shop'); ?></td>
                <td><?php echo $this->order->cdate; ?></td>
            </tr>
            <tr>
                <td><?php _e('Order Payment Method:', 'com_shop'); ?></td>
                <td><?php echo strings::htmlentities($this->order->payment_name); ?></td>
            </tr>
            <tr>
                <td><?php _e('Shipping Method Name:', 'com_shop'); ?></td>
                <td><?php echo strings::htmlentities($this->order->shipping_name); ?> </td>
            </tr>
            <tr>
                <td align="left"><?php _e('Shipping Mode:', 'com_shop'); ?></td>
                <td><?php echo strings::htmlentities($this->order->shipping_rate_name); ?></td>
            </tr>
            <tr>
                <td align="left"><?php _e('Change shipping rate:', 'com_shop'); ?></td>
                <td>
                    <select name="new_shipping_rate">
                        <?php foreach ((array) $this->shipping_methods as $k => $sm) { ?>
                            <option <?php echo ( trim($sm->id) == trim ( $this->order->ship_method_id) ) ? "selected='selected'" : ""; ?> value="<?php echo $sm->id; ?>">
                                <?php echo strings::htmlentities($sm->name); ?>
                            </option>
                            <?php } ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td><?php _e('Order Status:', 'com_shop'); ?></td>
                    <td>
                        <select name="new_status">
                            <?php foreach ((array) $this->statuses as $s) { ?>
                             <option value="<?php echo $s->term_id; ?>" <?php echo has_term($s->term_id, 'order_status', $this->order->pk())  ? "selected='selected'"     : ""; ?> ><?php echo strings::htmlentities($s->name); ?></option>
                            <?php } ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td>
                        <?php _e("User Login:", "com_shop"); ?>
                    </td>
                    <td>
                        <?php echo (empty($this->user)  ? "-"   : "<a href='" . get_edit_user_link($this->user->ID) . "'>" . esc_attr($this->user->user_nicename) . "</a>"); ?>
                    </td>
                </tr>
                <tr>
                    <td>
                        <?php _e("Assign User:", "com_shop"); ?>
                    </td>
                    <td>
                        <input type="text" id="select_user" value="" />
                        <button class="btn btn-small" onclick="document.getElementById('customer_id').value = '';
                                return false;" >
                            <i class="icon-delete"></i>
                            <?php _e('set as guest', 'com_shop'); ?>
                        </button>

                    </td>
                </tr>
                <tr>
                    <td><?php _e('Comment:', 'com_shop'); ?></td>
                    <td>
                        <table width="100%" border="0" cellspacing="2" cellpadding="2">
                            <tr>
                                <td width="25%">
                                    <textarea cols="10" rows="5" name="excerpt"><?php echo $this->order->order_comments; ?> </textarea></td>

                                <td></td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </tbody>
        </table>

        <div style="float:left; width:50%;">	
            <div style="background-color: #cccccc; padding:3px;">
                <?php _e('Billing Address Information', 'com_shop'); ?>
            </div>
            <div>
                <?php while ($f = $this->billing->get_field()) { ?>
                <div>
                    <label style="width:30%; display:inline-block;" for="<?php echo $f->get_name(); ?>"><?php _e($f->get_label(), 'com_shop'); ?>:</label>

                    <?php if ($f instanceof state && $f->get_name() == "billing_state") { ?>
                    <span id="billing_states_container">
                        <?php echo $f->render(); ?>
                    </span>
                    <?php
                    } else {
                    ?>
                    <?php echo $f->render(); ?>
                    <?php } ?>

                </div>
                <?php } ?>
            </div>
        </div>	

        <div style="width:50%; float:left;">	
            <div style="background-color: #cccccc; padding:3px;">
                <?php _e('Shipping Address Information', 'com_shop'); ?>
            </div>
            <div>
                <?php while ($f = $this->shipping->get_field()) { ?>
                <div style="">
                    <label style="width:30%; display:inline-block;" for="<?php echo $f->get_name(); ?>"><?php _e($f->get_label(), 'com_shop'); ?>:</label>

                    <?php if ($f instanceof state && $f->get_name() == "shipping_state") { ?>
                    <span id="shipping_states_container">
                        <?php echo $f->render(); ?>
                    </span>
                    <?php } else { ?>
                    <?php echo $f->render(); ?>
                    <?php } ?>

                </div>
                <?php } ?>         
        </div>
    </div>
    <div style="clear:both;"></div>
</div>