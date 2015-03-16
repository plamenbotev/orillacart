<table  border="0" cellspacing="0" cellpadding="0" class="wp-list-table widefat" width="100%">
    <tbody>
        <tr>
            <td></td>
            <td><?php _e('Product Name', 'com_shop'); ?></td>
            <td><?php _e('Note', 'com_shop'); ?></td>
            <td><?php _e('Price', 'com_shop'); ?></td>
            <td><?php _e('Quantity', 'com_shop'); ?></td>

            <td><?php _e('Product Number', 'com_shop'); ?></td>
            <td align="right"><?php _e('Total Price', 'com_shop'); ?></td>
        </tr>

        <?php foreach ((array) $this->items as $item) { ?>
            <tr id = "item-<?php echo $item->order_item_id; ?>">
                <td <?php echo $item->product_type == 'digital' ? "rowspan='2'" : ""; ?> >
                    <input type="hidden" name="items[]" value="<?php echo $item->order_item_id; ?>" />
                    <button class="btn btn-small btn-danger remove-order-item">
                        <i class="icon-trash"></i>
                        <?php _e("remove", "com_shop"); ?>
                    </button>

                </td>
                <td><?php echo strings::htmlentities($item->order_item_name); ?></td>
                <td>
                    <br>
                    <div class="checkout_attribute_static"></div>
                    <?php foreach ((array) $item->props as $prop) { ?>
                    <div cl ass="checkout_attribute_title">
                        <?php
                        echo strings::htmlentities($prop->section_name);
                        echo ($prop->section_price > 0)  ? "(" . $prop->section_oprand . " " . $this->price->format($prop->section_price, $this->order->currency_sign) . ")"    : "";
                        ?>
                    </div>
                    <?php } ?>
                </td>
                <td>
                    <?php echo $this->order->currency_sign; ?> <input type="text" name="new_price[<?php echo $item->order_item_id; ?>]" id="new_price_<?php echo $item->order_item_id; ?>" value="<?php echo round($item->product_item_price, 2); ?>" size="10">
                </td>
                <td>
                    <input type="text" name="new_quantity[<?php echo $item->order_item_id; ?>]" id="new_quantity_<?php echo $item->order_item_id; ?>" value="<?php echo $item->product_quantity; ?>" size="3">

                </td>
                <td><?php echo $item->product_id; ?> </td>
                <td align="right">
                    <?php echo $this->order->currency_sign; ?> &nbsp;&nbsp;<?php echo round($item->product_quantity * $item->product_item_price, 2); ?>
                </td>
            </tr>
            <?php if (!empty($item->files)) { ?>
            <tr id="item-downloads-<?php echo $item->order_item_id; ?>">
                <td colspan="6" >
                    <div style="max-height:142px; overflow-x: auto; overflow-y: scroll;">
                        <table>
                            <thead>
                                <tr>
                                    <th><?php _e('File', 'com_shop'); ?></th>
                                    <th><?php _e('Downloads remaining', 'com_shop'); ?></th>
                                    <th><?php _e('Expires on', 'com_shop'); ?></th>
                                    <th><?php _e('Access granted on', 'com_shop'); ?></th>
                                </tr>   
                            </thead>
                            <?php
                            foreach ((array) $item->files as $id => $name) {
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="files[<?php echo $item->order_item_id; ?>][<?php echo $id; ?>][id]" value="<?php echo $id; ?>" <?php echo isset($item->selected_downloads[$id])  ?  "checked='checked'"    :      ""; ?> />
                                    <?php echo strings::htmlentities($name); ?>
                                </td>
                                <td>

                                    <input type="text" name="files[<?php echo $item->order_item_id; ?>][<?php echo $id; ?>][downloads_remaining]" value="<?php echo isset($item->selected_downloads[$id])  ? $item->selected_downloads[$id]->downloads_remaining  : ''; ?>"
                                </td>
                                <td>
                                    <input type="text" class='calendar' name="files[<?php echo $item->order_item_id; ?>][<?php echo $id; ?>][expires]" value="<?php echo isset($item->selected_downloads[$id])  ? $item->selected_downloads[$id]->expires  : ''; ?>"
                                </td>
                                <?php if ($id == key($item->files)) { ?>
                                <td valign="top" rowspan="<?php echo count($item->files); ?>">
                                            <input type="text" class='calendar' name="access_granted[<?php echo $item->order_item_id; ?>]" value="<?php echo $item->access_granted; ?>" />
                                </td>
                                <?php } ?>
                            </tr>
                            <?php } ?>
                        </table>
                    </div>
            </tr>
            <?php } ?>
            <?php } ?>  

    </tbody>
</table>

