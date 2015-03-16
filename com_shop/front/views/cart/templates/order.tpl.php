<div id="order_review" class="clearfix">
    <div class="table-responsive">
        <table class="table shop_table">
            <thead>
                <tr>
                    <th colspan="2" ><?php _e('Product', 'com_shop'); ?></th>
                    <th><?php _e('Qty', 'com_shop'); ?></th>
                    <th><?php _e('Totals', 'com_shop'); ?></th>
                </tr>
            </thead>
            <tfoot>
                <tr>
                    <td class="a-right" colspan="3"><?php _e('Subtotal:', 'com_shop'); ?></td>
                    <td><?php echo $this->cart->get_formatted_price(); ?><small><?php _e('(ex. tax)', 'com_shop'); ?></small></td>
                </tr>


                <?php if (!empty($this->taxes)) { ?>

                    <?php foreach ((array) $this->taxes as $tax) { ?>

                        <tr>
                            <td class="a-right" colspan="3"><?php _e($tax->get("name", "Tax"), "com_shop"); ?>:</td>
                            <td><?php echo Factory::getComponent('shop')->getHelper('price')->format($tax->get("value", 0)); ?></td>
                        </tr>    

                    <?php } ?>


                    <tr>
                        <td class="a-right" colspan="3"><?php _e("Total Tax:", "com_shop"); ?></td>
                        <td><?php echo Factory::getComponent('shop')->getHelper('price')->format($this->cart->get_order_vat()); ?></td>
                    </tr>

                <?php } ?>
                <?php if ($this->cart->need_shipping()) { ?>
                    <tr>
                        <td class="a-right" colspan="3"><label for="shipping_method" class="control-label"><?php _e('Shipping:', 'com_shop'); ?></label></td>
                        <td>
                            <?php if (count($this->shipping_methods)) { ?>
                                <select class="form-control input-sm required" id="shipping_method" name="shipping_method">
                                    <option value=""></option>
                                    <?php foreach ((array) $this->shipping_methods as $method) { ?>
                                        <option <?php echo $method->id == $this->cart->selected_shipping_id() ? "selected='selected'" : ""; ?> value="<?php echo $method->id; ?>"><?php echo $method->name; ?></option>
                                    <?php } ?>
                                </select>
                            <?php } else { ?>
                                <?php _e('Enter all required data', 'com_shop'); ?> <noscript><?php _e('and click update totals,', 'com_shop'); ?></noscript> <?php _e('to get shipping options!', 'com_shop'); ?>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                <tr>
                    <td class="a-right" colspan="3"><strong><?php _e('Grand Total:', 'com_shop'); ?></strong></td>
                    <td><strong><?php echo $this->cart->get_formatted_price('grand_total'); ?></strong></td>
                </tr>
            </tfoot>
            <tbody>
                <?php
                while ($p = $this->cart->get_item()) {
                    ?>
                    <tr>
                        <td>
                            <?php if (!empty($p->thumb)): ?>
                                <a class="product-image" href="<?php echo get_permalink($p->id); ?>">
                                    <img  style="max-width:<?php echo Factory::getComponent('shop')->getParams()->get('thumbX'); ?>px" src="<?php echo $p->thumb; ?>" />
                                </a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <h2 class="product-name">
                                <a href="<?php echo get_permalink($p->id); ?>"><?php echo $p->name; ?></a>
                            </h2>
                            <?php foreach ((array) $p->props as $prop) { ?>
                                <dl class="item-options">
                                    <dt><?php echo $prop->name; ?><span class="price"><?php echo $prop->price; ?></span></dt>

                                </dl>
                            <?php } ?>
                        </td>
                        <td class="a-center"><?php echo $p->qty; ?></td>
                        <td >
                            <span class="price"><?php echo $p->raw_price_formatted; ?></span>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <div id="payment">
        <?php
        if ($this->cart->need_payment())
            if (count($this->payment_methods)) :
                ?>

                <div class="form-group">
                    <?php foreach ((array) $this->payment_methods as $method) :
                        ?>
                        <div>
                            <input class="form-control" type="radio" <?php echo $method->get_id() == $this->cart->selected_payment_id() ? "checked='checked'" : ""; ?>  value="<?php echo $method->get_id(); ?>" name="payment_method" class="input-radio" id="payment_method_<?php echo $method->get_id(); ?>">
                            <label class="control-label" for="payment_method_<?php echo $method->get_id(); ?>"><?php echo $method->get_name(); ?> </label>
                            <div style="display: block;" class="payment_box payment_method_<?php echo $method->get_id(); ?>">
                                <?php echo strings::htmlentities($method->fields()); ?>
                            </div>
                        </div>

                        <?php
                    endforeach;
                    ?>
                </div>
            <?php else: ?>
                <p><?php _e('There is no payment method available for your location.', 'com_shop'); ?></p>
        <?php endif; ?>


        <div class="processOrderContainer">
            <noscript><?php _e('Since your browser does not support JavaScript, or it is disabled, please ensure you click the <em>Update Totals</em> button before placing your order. You may be charged more than the amount stated above if you fail to do so.', 'com_shop'); ?> <input type="submit" class="button-alt" name="update_totals" value="<?php _e('Update totals', 'com_shop'); ?>" /></noscript>
            <button class="btn btn-success">
                <span class="icon-checkmark"></span>
                <?php _e('Place Order', 'com_shop'); ?>
            </button>
        </div>
    </div>
</div>