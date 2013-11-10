<dt><?php _e('Dimensions', 'com_shop'); ?></dt>
<dd>
    <fieldset class="panelform">
        <ul class="adminformlist">
            <li>
                <label for="product_length">
                    <?php _e('Length:', 'com_shop'); ?>
                </label>
                <input type="text" id="product_length" value="<?php echo (double) $this->row->product->product_length; ?>" name="product_length" />
            </li>
            <li>
                <label for="product_width">
                    <?php _e('Width:', 'com_shop'); ?>
                </label>
                <input type="text" id="product_width" value="<?php echo (double) $this->row->product->product_width; ?>" name="product_width" />
            </li>
            <li>
                <label for="product_height">
                    <?php _e('Height:', 'com_shop'); ?>
                </label>
                <input type="text" id="product_height" value="<?php echo (double) $this->row->product->product_height; ?>" name="product_height" />
            </li>
            <li>
                <label for="product_volume">
                    <?php _e('Volume:', 'com_shop'); ?>
                </label>
                <input type="text" id="product_volume" value="<?php echo (double) $this->row->product->product_volume; ?>" name="product_volume" />
            </li>
            <li>
                <label for="product_diameter">
                    <?php _e('Diameter:', 'com_shop'); ?>
                </label>
                <input type="text" id="product_diameter" value="<?php echo (double) $this->row->product->product_diameter; ?>" name="product_diameter" />
            </li>
            <li>
                <label for="product_weight">
                    <?php _e('Weight:', 'com_shop'); ?>
                </label>
                <input type="text" id="product_weight" value="<?php echo (double) $this->row->product->product_weight; ?>" name="product_weight" />
            </li>
        </ul>
    </fieldset>
</dd>