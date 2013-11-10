<dt id="tab5"><span><?php _e('Variations', 'com_shop'); ?></span></dt>
<dd>

    <?php if (!count($this->all_attributes)) { ?>


        <h4>To create variations, you firstly need to create all needed attributes and associate all needed attribute sets!</h4>
    <?php } else { ?>
  
        <fieldset class="panelform">
            <ul class="adminformlist">
                <li>
                    <label for="variation_title"> <?php _e('Title:', 'com_shop'); ?> </label>
                    <input type="text" name="variation_title" id="variation_title" />
                </li>
                <li>
                    <label for="variation_title"> <?php _e('SKU:', 'com_shop'); ?> </label>
                    <input type="text" name="variation_sku" id="variation_sku" />
                </li>
                <li>
                    <label for="variation_price"> <?php _e('Price:', 'com_shop'); ?> </label>
                    <input type="text" name="variation_price" id="variation_price" />
                </li>

                <?php foreach ((array) $this->all_attributes as $att) { ?>
                    <li>
                        <label for="property_<?php echo $att->attribute_id; ?>"> <?php echo strings::stripAndEncode($att->attribute_name); ?></label>
                        <select id="property_<?php echo $att->attribute_id; ?>" name='property[<?php echo $att->attribute_id; ?>]' class='property' >
                            <option></option>
                            <?php
                            if (!empty($att->properties)) {
                                foreach ((array) $att->properties as $prop) {
                                    ?>
                                    <option value='<?php echo $prop->property_id; ?>'><?php echo strings::stripAndEncode($prop->property_name); ?></option>
                                <?php } ?>
                            </select>
                        <?php } ?>
                    </li>
                <?php } ?>


            </ul>
        </fieldset>




        <button class="btn btn-success" onclick=" return jsShopAdminHelper.attribute.create_variation();">
            <i class="icon-new"></i>
            <?php _e("Save","com_shop"); ?>
        </button>



    <?php } ?>
</dd>