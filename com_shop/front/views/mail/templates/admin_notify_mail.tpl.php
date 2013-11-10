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
                                        <td valign="top"><h1  style="font-size:22px; font-weight:normal; line-height:22px; margin:0 0 11px 0;">Hello, test test</h1>
                                            <p style="font-size:12px; line-height:16px; margin:0;">
                                                You have received new order, and that order was marked as completed. You can view order details below.
                                            </p>
                                    </tr>
                                    <tr>
                                        <td><h2 style="font-size:18px; font-weight:normal; margin:0;">Your Order #<?php echo (string) $this->order['ID']; ?> <small>(placed on <?php echo $this->order['cdate']; ?>)</small></h2></td>
                                    </tr>
                                    <tr>
                                        <td><table width="650" cellspacing="0" cellpadding="0" border="0">
                                                <thead>
                                                    <tr>
                                                        <th width="325" bgcolor="#EAEAEA" align="left" style="font-size:13px; padding:5px 9px 6px 9px; line-height:1em;">Billing Information:</th>
                                                        <th width="10"></th>
                                                        <th width="325" bgcolor="#EAEAEA" align="left" style="font-size:13px; padding:5px 9px 6px 9px; line-height:1em;">Payment Method:</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td valign="top" style="font-size:12px; padding:7px 9px 9px 9px; border-left:1px solid #EAEAEA; border-bottom:1px solid #EAEAEA; border-right:1px solid #EAEAEA;"> Ime Familia<br>
                                                            <?php echo Factory::getApplication('shop')->getHelper('order')->format_billing($this->order['ID']); ?>
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
                                            <table width="650" cellspacing="0" cellpadding="0" border="0">
                                                <thead>
                                                    <tr>
                                                        <th width="325" bgcolor="#EAEAEA" align="left" style="font-size:13px; padding:5px 9px 6px 9px; line-height:1em;">Shipping Information:</th>
                                                        <th width="10"></th>
                                                        <th width="325" bgcolor="#EAEAEA" align="left" style="font-size:13px; padding:5px 9px 6px 9px; line-height:1em;">Shipping Method:</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td valign="top" style="font-size:12px; padding:7px 9px 9px 9px; border-left:1px solid #EAEAEA; border-bottom:1px solid #EAEAEA; border-right:1px solid #EAEAEA;">
                                                            <?php echo Factory::getApplication('shop')->getHelper('order')->format_shipping($this->order['ID']); ?>
                                                        </td>
                                                        <td></td>
                                                        <td valign="top" style="font-size:12px; padding:7px 9px 9px 9px; border-left:1px solid #EAEAEA; border-bottom:1px solid #EAEAEA; border-right:1px solid #EAEAEA;"> 
                                                            <?php echo strings::htmlentities($this->order['shipping_name']); ?> - <?php echo strings::htmlentities($this->order['shipping_rate_name']); ?>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                            <br>


                                            <?php if (!empty($this->files)) { ?>


                                                <table width="650px" cellspacing="0" cellpadding="0" border="0" >

                                                    <thead>
                                                        <tr style="background-color:#cccccc;">

                                                            <th>Item</th>
                                                            <th>File</th>


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
                                                                <td><a href="<?php echo home_url("?order_key=" . $file->order_key . "&file=" . $file->file_id) . "&item=" . $file->item_id; ?>">download</a></td>

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
                                                        <th bgcolor="#EAEAEA" align="left" style="font-size:13px; padding:3px 9px">Item</th>
                                                        <th bgcolor="#EAEAEA" align="left" style="font-size:13px; padding:3px 9px">Sku</th>
                                                        <th bgcolor="#EAEAEA" align="center" style="font-size:13px; padding:3px 9px">Qty</th>
                                                        <th bgcolor="#EAEAEA" align="right" style="font-size:13px; padding:3px 9px">Subtotal</th>
                                                    </tr>
                                                </thead>


                                                <?php foreach ((array) $this->items as $c => $item) { ?>

                                                    <tbody bgcolor="<?php echo $c % 2 == 0 ? '#F6F6F6' : ''; ?>" >
                                                        <tr id="order-item-row-92255">
                                                            <td valign="top" align="left" style="padding:3px 9px"><strong><?php echo strings::stripAndEncode($item->order_item_name); ?></strong></td>
                                                            <td valign="top" align="left" style="padding:3px 9px"><?php echo strings::stripAndEncode($item->order_item_sku); ?></td>
                                                            <td valign="top" align="center" style="padding:3px 9px">  <?php echo $item->product_quantity; ?> </td>
                                                            <td valign="top" align="right" style="padding:3px 9px"><?php echo $this->price->format(($item->product_quantity * $item->product_item_price), $this->order['currency_sign']); ?></td>
                                                        </tr>
                                                        <?php foreach ((array) $item->props as $p) { ?>
                                                            <tr>
                                                                <td valign="top" align="left" style="padding:3px 9px"><strong>
                                                                        <em>
                                                                            <?php echo strings::stripandencode($p->section_name); ?>
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
                                                        <td align="right" style="padding:3px 9px" colspan="3"> Subtotal </td>
                                                        <td align="right" style="padding:3px 9px"><?php echo $this->price->format($this->order['order_subtotal'], $this->order['currency_sign']); ?></td>
                                                    </tr>
                                                    <tr class="shipping">
                                                        <td align="right" style="padding:3px 9px" colspan="3"> Shipping &amp; Handling </td>
                                                        <td align="right" style="padding:3px 9px"><?php echo $this->price->format($this->order['order_shipping'], $this->order['currency_sign']); ?></td>
                                                    </tr>

                                                    <tr class="tax">
                                                        <td align="right" style="padding:3px 9px" colspan="3"> Tax </td>
                                                        <td align="right" style="padding:3px 9px"><?php echo $this->price->format($this->order['order_shipping_tax'] + $this->order['order_tax'], $this->order['currency_sign']); ?></td>
                                                    </tr>

                                                    <tr class="grand_total">
                                                        <td align="right" style="padding:3px 9px" colspan="3"><strong>Grand Total</strong></td>
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