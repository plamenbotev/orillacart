<dt id="tab5"><span><?php _e('Attribute Associations', 'com_shop'); ?></span></dt>
<dd>
    <fieldset class="panelform">
        <ul class="adminformlist">
            <?php foreach ((array) $this->all_attributes as $att) { ?>
                <li>
                    <label for="property_<?php echo $att->attribute_id; ?>"> <?php echo strings::stripAndEncode($att->attribute_name); ?></label>
                    <select id="property_<?php echo $att->attribute_id; ?>" name='property[<?php echo $att->attribute_id; ?>]' class='property' >
                        <option></option>
                        <?php
                        if (!empty($att->properties)) {
                            foreach ((array) $att->properties as $prop) {
                                ?>
                                <option <?php echo (in_array($prop->property_id,(array)$this->variations_assoc) ? 'selected="selected"':''); ?> value='<?php echo $prop->property_id; ?>'><?php echo strings::stripAndEncode($prop->property_name); ?></option>
                            <?php } ?>
                        </select>
                    <?php } ?>
                </li>
            <?php } ?>
        </ul>
    </fieldset>
</dd>