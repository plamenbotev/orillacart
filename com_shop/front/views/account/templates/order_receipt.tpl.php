<h1><?php _e('Order #', 'com_shop'); ?><?php echo (string) $this->order['ID']; ?></h1>
<p class=""><?php _e('Order Date:', 'com_shop'); ?> <?php echo $this->order['cdate']; ?></p>
<hr />
<p><?php _e('Order key: ', 'com_shop'); ?> <?php echo $this->order['post_password']; ?></p>
<hr />

<div class="container-fluid">
    <div class="row">
        <?php if (Factory::getComponent("shop")->getParams()->get("shipping")) { ?>
            <div class="col-xs-12 col-sm-6">
                <h2><?php _e('Shipping Address', 'com_shop'); ?></h2>
                <address>
                    <?php
                    echo Factory::getComponent('shop')->getHelper('order')->format_shipping($this->order['ID']);
                    ?>
                </address>
            </div>
        <?php } ?>
        <div class="col-xs-12 col-sm-6">
            <h2><?php _e('Billing Address', 'com_shop'); ?></h2>
            <address>
                <?php
                echo Factory::getComponent('shop')->getHelper('order')->format_billing($this->order['ID']);
                ?>
            </address>
        </div>
    </div>
    <div class="row">
        <?php if (Factory::getComponent("shop")->getParams()->get("shipping")) { ?>
            <div class="col-xs-12 col-sm-6">
                <h2><?php _e('Shipping Method', 'com_shop'); ?></h2>
                <?php echo strings::htmlentities($this->order['shipping_name']); ?> - <?php echo strings::htmlentities($this->order['shipping_rate_name']); ?>
            </div>
        <?php } ?>
        <div class="col-xs-12 col-sm-6">
            <h2><?php _e('Payment Method', 'com_shop'); ?></h2>
            <div class='payment-additional-info'>
                <p><?php echo strings::htmlentities($this->order['payment_name']); ?></p>
                <?php echo empty($this->on_receipt_content) ? '' : strings::htmlentities($this->on_receipt_content); ?>
            </div>
        </div>
    </div>
</div>
<div class="clr"></div>
<h2><?php _e('Available downloads', 'com_shop'); ?></h2>
<div class="table-responsive">
    <table class="data-table table" id="my-orders-table">
        <col width="1">
        <col width="1">
        <col>
        <col width="1">
        <col width="1">
        <col width="1">
        <thead>
            <tr>
                <th><?php _e('Item', 'com_shop'); ?></th>
                <th><?php _e('File', 'com_shop'); ?></th>
                <th>&nbsp;</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $c2 = 0;
            foreach ((array) $this->files as $file) {
                $c2++;
                ?>
                <tr>
                    <?php
                    if ($c2 == 1 || $c2 > $file->order_item_files_count) {
                        $c2 = 1;
                        ?>
                        <td rowspan="<?php echo (int) $file->order_item_files_count; ?>"><?php echo strings::htmlentities($file->product_name); ?></td>
                    <?php } ?>
                    <td><?php echo get_the_title($file->file_id); ?> </td>
                    <td><a href="<?php echo home_url("?order_key=" . $file->order_key . "&file=" . $file->file_id) . "&item=" . $file->item_id; ?>"><?php _e('download', 'com_shop'); ?></a></td>
                </tr>
                <?php
            }
            ?>
        </tbody>
        <tfoot>
        </tfoot>
    </table>
</div>

<div class="clr"></div>
<h2><?php _e('Items Ordered', 'com_shop'); ?></h2>
<div class="table-responsive">
    <table class="data-table table" id="my-orders-table">
        <col>
        <col >
        <col width="15%">
        <col width="10%">
        <col width="15%">
        <thead>
            <tr>
                <th><?php _e('Product Name', 'com_shop'); ?></th>
                <th><?php _e('SKU'); ?></th>
                <th class="a-right"><?php _e('Price', 'com_shop'); ?></th>
                <th class="a-center"><?php _e('Qty', 'com_shop'); ?></th>
                <th class="a-right"><?php _e('Subtotal', 'com_shop'); ?></th>
            </tr>
        </thead>
        <tfoot>
            <tr class="subtotal">
                <td colspan="4" class="a-right">
                    <?php _e('Subtotal', 'com_shop'); ?>
                </td>
                <td class="last a-right">
                    <span class="price">
                        <?php echo $this->price->format($this->order['order_subtotal'], $this->order['currency_sign']); ?>
                    </span> 
                </td>
            </tr>
            <tr class="shipping">
                <td colspan="4" class="a-right">
                    <?php _e('Shipping &amp; Handling', 'com_shop'); ?>
                </td>
                <td class="last a-right">
                    <span class="price">
                        <?php echo $this->price->format($this->order['order_shipping'], $this->order['currency_sign']); ?>
                    </span>                 
                </td>
            </tr>
            <?php if (!empty($this->taxes)) { ?>
                <?php foreach ((array) $this->taxes as $tax) { ?>
                    <tr class="tax">
                        <td colspan="4" class="a-right">
                            <?php _e(strings::htmlentities($tax->name), 'com_shop'); ?>
                        </td>
                        <td class="last a-right">
                            <span class="price">
                                <?php echo $this->price->format($tax->value, $this->order['currency_sign']); ?>
                            </span>                 
                        </td>
                    </tr>
                <?php } ?>

                <tr class="tax">
                    <td colspan="4" class="a-right">
                        <?php _e('Tax', 'com_shop'); ?>
                    </td>
                    <td class="last a-right">
                        <span class="price">
                            <?php echo $this->price->format($this->order['order_shipping_tax'] + $this->order['order_tax'], $this->order['currency_sign']); ?>
                        </span>                 
                    </td>
                </tr>

            <?php } ?>
            <tr class="grand_total">
                <td colspan="4" class="a-right">
                    <strong><?php _e('Grand Total', 'com_shop'); ?></strong>
                </td>
                <td class="last a-right">
                    <strong><span class="price"><?php echo $this->price->format($this->order['order_total'], $this->order['currency_sign']); ?></span></strong>
                </td>
            </tr>
        </tfoot>

        <?php
        $c = 0;
        foreach ((array) $this->items as $item) {
            $c++;
            ?>
            <tbody class="<?php echo $c % 2 ? 'odd' : 'even'; ?> " >
                <tr >
                    <td><h3 class="product-name"><?php echo strings::htmlentities($item->order_item_name); ?></h3></td>
                    <td><?php echo strings::htmlentities($item->order_item_sku); ?></td>
                    <td class="a-right">
                        <span class="price-excl-tax">
                            <span class="cart-price">
                                <span class="price">
                                    <?php echo $this->price->format($item->product_item_price, $this->order['currency_sign']); ?>
                                </span>
                            </span>
                        </span>
                        <br>
                    </td>
                    <td class="a-right">
                        <?php echo $item->product_quantity; ?>
                    </td>
                    <td class="a-right">
                        <span class="price-excl-tax">
                            <span class="cart-price">

                                <span class="price">
                                    <?php echo $this->price->format(($item->product_quantity * $item->product_item_price), $this->order['currency_sign']); ?>
                                </span>
                            </span>
                        </span>
                        <br>
                    </td>
                </tr>

                <?php foreach ((array) $item->props as $p) { ?>
                    <tr>
                        <td>
                            <div class="option-label">
                                <?php echo strings::htmlentities($p->section_name); ?>
                                <?php if ($p->section_price > 0) { ?> 
                                    <span class="price">( <?php echo $p->section_oprand . " " . $this->price->format($p->section_price, $this->order['currency_sign']); ?> )</span></div></td>
                                <?php } ?>
                        </div></td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                    </tr>

                <?php } ?>
            </tbody>
        <?php } ?>
    </table>
</div>