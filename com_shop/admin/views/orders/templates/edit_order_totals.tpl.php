<table border="0" cellspacing="0" cellpadding="0" class="wp-list-table widefat">
    <tbody>
        <tr align="left">
            <td align="right" width="70%"><strong><?php _e('Order Subtotal:', 'com_shop'); ?></strong></td>
            <td align="right" width="30%">
                <?php echo $this->price->format($this->order->order_subtotal, $this->order->currency_sign); ?>
            </td>
        </tr>
        <tr align="left">
            <td align="right" width="70%"><strong><?php _e('TAX:', 'com_shop'); ?></strong></td>
            <td align="right" width="30%">
                <?php echo $this->price->format($this->order->order_tax, $this->order->currency_sign); ?>
            </td>
        </tr>
        <tr align="left">
            <td align="right" width="70%"><strong><?php _e('Shipping:', 'com_shop'); ?></strong></td>
            <td align="right" width="30%">
                <?php echo $this->price->format($this->order->order_shipping, $this->order->currency_sign); ?>
            </td>
        </tr>
        <tr align="left">
            <td align="right" width="70%"><strong><?php _e('Shipping Tax:', 'com_shop'); ?></strong></td>
            <td align="right" width="30%">
                <?php echo $this->price->format($this->order->order_shipping_tax, $this->order->currency_sign); ?>
            </td>
        </tr>
        <tr align="left">
            <td colspan="2" align="left">
                <hr>
            </td>
        </tr>
        <tr align="left">
            <td align="right" width="70%"><strong><?php _e('Total:', 'com_shop'); ?></strong></td>
            <td align="right" width="30%">
                <?php echo $this->price->format($this->order->order_total, $this->order->currency_sign); ?>
            </td>
        </tr>
    </tbody>
</table>
