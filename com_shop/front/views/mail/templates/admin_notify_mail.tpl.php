<html>
    <head>
        <title></title>
        <meta content="text/html; charset=UTF-8" http-equiv="Content-Type">
        <style>
            body {
                background-color: white;
            }
            body, p {
                font-family: Tahoma, sans-serif;
                font-size: 13px;
            }
        </style>
        <style type="text/css">
            body, td {
                color:#2f2f2f;
                font:11px/1.35em Verdana, Arial, Helvetica, sans-serif;
            }
        </style>
    </head>
    <body style="background:#F6F6F6; font-family:Verdana, Arial, Helvetica, sans-serif; font-size:12px; margin:0; padding:0;">
        <div style="background:#F6F6F6; font-family:Verdana, Arial, Helvetica, sans-serif; font-size:12px; margin:0; padding:0;">
            <table width="100%" cellspacing="0" cellpadding="0" border="0">
                <tbody>
                    <tr>
                        <td valign="top" align="center" style="padding:20px 0 20px 0"><table width="650" cellspacing="0" cellpadding="10" border="0" bgcolor="#FFFFFF" style="border:1px solid #E0E0E0;">

                                <tbody>

                                    <tr>
                                        <td valign="top"><h1  style="font-size:22px; font-weight:normal; line-height:22px; margin:0 0 11px 0;">
                                                <p style="font-size:12px; line-height:16px; margin:0;">
                                                    <?php _e("You have received new order, and that order was marked as completed. You can view order details below.", "com_shop"); ?>
                                                </p>
                                    </tr>
                                    <tr>
                                        <td><h2 style="font-size:18px; font-weight:normal; margin:0;"><?php _e("Your Order #", "com_shop"); ?><?php echo (string) $this->order['ID']; ?> <small>(<?php _e("placed on", "com_shop"); ?> <?php echo $this->order['cdate']; ?>)</small></h2></td>
                                    </tr>
                                    <tr>
                                        <td>
                                            <p><?php _e('Order key: ', 'com_shop'); ?> <?php echo $this->order['post_password']; ?></p>
                                        </td>

                                    </tr>
                                    <tr>
                                        <td><table width="650" cellspacing="0" cellpadding="0" border="0">
                                                <thead>
                                                    <tr>
                                                        <th width="325" bgcolor="#EAEAEA" align="left" style="font-size:13px; padding:5px 9px 6px 9px; line-height:1em;"><?php _e("Billing Information:", "com_shop"); ?></th>
                                                        <th width="10"></th>
                                                        <th width="325" bgcolor="#EAEAEA" align="left" style="font-size:13px; padding:5px 9px 6px 9px; line-height:1em;"><?php _e("Payment Method:", "com_shop"); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td valign="top" style="font-size:12px; padding:7px 9px 9px 9px; border-left:1px solid #EAEAEA; border-bottom:1px solid #EAEAEA; border-right:1px solid #EAEAEA;">
                                                            <?php echo Factory::getComponent('shop')->getHelper('order')->format_billing($this->order['ID']); ?>
                                                        </td>
                                                        <td></td>
                                                        <td valign="top" style="font-size:12px; padding:7px 9px 9px 9px; border-left:1px solid #EAEAEA; border-bottom:1px solid #EAEAEA; border-right:1px solid #EAEAEA;">
                                                            <p><?php echo strings::htmlentities($this->order['payment_name']); ?></p>
                                                            <?php if (!empty($this->on_receipt_content)) { ?>
                                                                <?php echo $this->on_receipt_content; ?>
                                                            <?php } ?>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                            <br>
                                            <?php if (Factory::getComponent("shop")->getParams()->get("shipping")) { ?>
                                                <table width="650" cellspacing="0" cellpadding="0" border="0">
                                                    <thead>
                                                        <tr>
                                                            <th width="325" bgcolor="#EAEAEA" align="left" style="font-size:13px; padding:5px 9px 6px 9px; line-height:1em;"><?php _e("Shipping Information:", "com_shop"); ?></th>
                                                            <th width="10"></th>
                                                            <th width="325" bgcolor="#EAEAEA" align="left" style="font-size:13px; padding:5px 9px 6px 9px; line-height:1em;"><?php _e("Shipping Method:", "com_shop"); ?></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr>
                                                            <td valign="top" style="font-size:12px; padding:7px 9px 9px 9px; border-left:1px solid #EAEAEA; border-bottom:1px solid #EAEAEA; border-right:1px solid #EAEAEA;">
                                                                <?php echo Factory::getComponent('shop')->getHelper('order')->format_shipping($this->order['ID']); ?>
                                                            </td>
                                                            <td></td>
                                                            <td valign="top" style="font-size:12px; padding:7px 9px 9px 9px; border-left:1px solid #EAEAEA; border-bottom:1px solid #EAEAEA; border-right:1px solid #EAEAEA;"> 
                                                                <?php echo strings::htmlentities($this->order['shipping_name']); ?> - <?php echo strings::htmlentities($this->order['shipping_rate_name']); ?>
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                                <br>
                                            <?php } ?>    

                                            <?php if (!empty($this->files)) { ?>


                                                <table width="650px" cellspacing="0" cellpadding="0" border="0" >

                                                    <thead>
                                                        <tr style="background-color:#cccccc;">

                                                            <th><?php _e("Item", "com_shop"); ?></th>
                                                            <th><?php _e("File", "com_shop"); ?></th>


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
                                                                <td><a href="<?php echo home_url("?order_key=" . $file->order_key . "&file=" . $file->file_id) . "&item=" . $file->item_id; ?>"><?php _e("download", "com_shop"); ?></a></td>

                                                            </tr>
                                                            <?php
                                                        }
                                                        ?>

                                                    </tbody>
                                                    <tfoot>
                                                    </tfoot>
                                                </table>
                                                <br>
                                            <?php } ?>



                                            <table width="650" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #EAEAEA;">
                                                <thead>
                                                    <tr>
                                                        <th bgcolor="#EAEAEA" align="left" style="font-size:13px; padding:3px 9px"><?php _e("Item", "com_shop"); ?></th>
                                                        <th bgcolor="#EAEAEA" align="left" style="font-size:13px; padding:3px 9px"><?php _e("Sku", "com_shop"); ?></th>
                                                        <th bgcolor="#EAEAEA" align="center" style="font-size:13px; padding:3px 9px"><?php _e("Qty", "com_shop"); ?></th>
                                                        <th bgcolor="#EAEAEA" align="right" style="font-size:13px; padding:3px 9px"><?php _e("Subtotal", "com_shop"); ?></th>
                                                    </tr>
                                                </thead>


                                                <?php foreach ((array) $this->items as $c => $item) { ?>

                                                    <tbody bgcolor="<?php echo $c % 2 == 0 ? '#F6F6F6' : ''; ?>" >
                                                        <tr id="order-item-row-92255">
                                                            <td valign="top" align="left" style="padding:3px 9px"><strong><?php echo strings::htmlentities($item->order_item_name); ?></strong></td>
                                                            <td valign="top" align="left" style="padding:3px 9px"><?php echo strings::htmlentities($item->order_item_sku); ?></td>
                                                            <td valign="top" align="center" style="padding:3px 9px">  <?php echo $item->product_quantity; ?> </td>
                                                            <td valign="top" align="right" style="padding:3px 9px"><?php echo $this->price->format(($item->product_quantity * $item->product_item_price), $this->order['currency_sign']); ?></td>
                                                        </tr>
                                                        <?php foreach ((array) $item->props as $p) { ?>
                                                            <tr>
                                                                <td valign="top" align="left" style="padding:3px 9px"><strong>
                                                                        <em>
                                                                            <?php echo strings::htmlentities($p->section_name); ?>
                                                                            <?php if ($p->section_price > 0) { ?> 
                                                                                (&nbsp;<?php echo $p->section_oprand . " " . $this->price->format($p->section_price, $this->order['currency_sign']); ?> )</td>
                                                                            <?php } ?>
                                                                        </em>
                                                                    </strong>
                                                                </td>
                                                                <td></td>
                                                                <td></td>
                                                                <td></td>
                                                            </tr>
                                                        <?php } ?>
                                                    </tbody>
                                                <?php } ?>
                                                <tfoot>
                                                    <tr class="subtotal">
                                                        <td align="right" style="padding:3px 9px" colspan="3"> <?php _e("Subtotal", "com_shop"); ?> </td>
                                                        <td align="right" style="padding:3px 9px"><?php echo $this->price->format($this->order['order_subtotal'], $this->order['currency_sign']); ?></td>
                                                    </tr>
                                                    <tr class="shipping">
                                                        <td align="right" style="padding:3px 9px" colspan="3"> <?php _e("Shipping &amp; Handling", "com_shop"); ?> </td>
                                                        <td align="right" style="padding:3px 9px"><?php echo $this->price->format($this->order['order_shipping'], $this->order['currency_sign']); ?></td>
                                                    </tr>
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
                                                        <td align="right" style="padding:3px 9px" colspan="3"> <?php _e("Tax", "com_shop"); ?> </td>
                                                        <td align="right" style="padding:3px 9px"><?php echo $this->price->format($this->order['order_shipping_tax'] + $this->order['order_tax'], $this->order['currency_sign']); ?></td>
                                                    </tr>

                                                    <tr class="grand_total">
                                                        <td align="right" style="padding:3px 9px" colspan="3"><strong><?php _e("Grand Total", "com_shop"); ?></strong></td>
                                                        <td align="right" style="padding:3px 9px"><strong><?php echo $this->price->format($this->order['order_total'], $this->order['currency_sign']); ?></strong></td>
                                                    </tr>
                                                </tfoot>

                                            </table>
                                            <p style="font-size:12px; margin:0 0 10px 0"></p></td>
                                    </tr>

                                </tbody>
                            </table></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </body>
</html>