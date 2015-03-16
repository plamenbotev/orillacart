<div class="com_shop">
    <form name="adminForm"  action="<?php echo admin_url('admin.php?page=component_com_shop-shipping'); ?>" method='post'>
        <fieldset class="panelform">
            <ul class="adminformlist">
                <li>
                    <label for="shipping_rate_name">
                        <?php _e('Shipping Rate Name:', 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo strings::htmlentities($this->row->shipping_rate_name); ?>" id="shipping_rate_name" name="shipping_rate_name" />
                </li>
                <li>
                    <label for="shipping_rate_weight_start">
                        <?php _e('Weight Start:', 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo (double) $this->row->shipping_rate_weight_start; ?>" id="shipping_rate_weight_start" name="shipping_rate_weight_start" />
                </li>
                <li>
                    <label for="shipping_rate_weight_end">
                        <?php _e('Weight End:', 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo (double) $this->row->shipping_rate_weight_end; ?>" id="shipping_rate_weight_end" name="shipping_rate_weight_end" />
                </li>
                <li>
                    <label for="shipping_rate_volume_start">
                        <?php _e('Volume Start:', 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo (double) $this->row->shipping_rate_volume_start; ?>" id="shipping_rate_volume_start" name="shipping_rate_volume_start" />
                </li>
                <li>
                    <label for="shipping_rate_volume_end">
                        <?php _e('Volume End:', 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo (double) $this->row->shipping_rate_volume_end; ?>" id="shipping_rate_volume_end" name="shipping_rate_volume_end" />
                </li>
                <li>
                    <label for="shipping_rate_length_start">
                        <?php _e("Shipping Rate Length Start:", 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo (double) $this->row->shipping_rate_length_start; ?>" id="shipping_rate_length_start" name="shipping_rate_length_start" />
                </li>
                <li>
                    <label for="shipping_rate_length_end">
                        <?php _e('Shipping Rate Length End:', 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo (double) $this->row->shipping_rate_length_end; ?>" id="shipping_rate_length_end" name="shipping_rate_length_end" />
                </li>
                <li>
                    <label for="shipping_rate_width_start">
                        <?php _e('Shipping Rate Width Start:', 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo (double) $this->row->shipping_rate_width_start; ?>" id="shipping_rate_width_start" name="shipping_rate_width_start" />
                </li>
                <li>
                    <label for="shipping_rate_width_end">
                        <?php _e('Shipping Rate Width End:', 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo (double) $this->row->shipping_rate_width_end; ?>" id="shipping_rate_width_end" name="shipping_rate_width_end" />
                </li>
                <li>
                    <label for="shipping_rate_height_start">
                        <?php _e('Shipping Rate Height Start:', 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo (double) $this->row->shipping_rate_height_start; ?>" id="shipping_rate_height_start" name="shipping_rate_height_start" />
                </li>
                <li>
                    <label for="shipping_rate_height_end">
                        <?php _e('Shipping Rate Height End:', 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo (double) $this->row->shipping_rate_height_end; ?>" id="shipping_rate_height_end" name="shipping_rate_height_end" />
                </li>
                <li>
                    <label for="shipping_rate_ordertotal_start">
                        <?php _e('Order Total Start:', 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo (double) $this->row->shipping_rate_ordertotal_start; ?>" id="shipping_rate_ordertotal_start" name="shipping_rate_ordertotal_start" />
                </li>
                <li>
                    <label for="shipping_rate_ordertotal_end">
                        <?php _e('Order Total End:', 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo (double) $this->row->shipping_rate_ordertotal_end; ?>" id="shipping_rate_ordertotal_end" name="shipping_rate_ordertotal_end" />
                </li>
                <li>
                    <label for="shipping_rate_zip_start">
                        <?php _e('Zip code start:', 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo (int) $this->row->shipping_rate_zip_start; ?>" id="shipping_rate_zip_start" name="shipping_rate_zip_start" />
                </li>
                <li>
                    <label for="shipping_rate_zip_end">
                        <?php _e('Zip code end:', 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo (int) $this->row->shipping_rate_zip_end; ?>" id="shipping_rate_zip_end" name="shipping_rate_zip_end" />
                </li>
                <li>
                    <label for="shipping_rate_country">
                        <?php _e('Country:', 'com_shop'); ?>
                    </label>
                    <select onchange="jsShopAdminHelper.loadStatesByCountries(this, 'changestate')" size="5" multiple="multiple" id="shipping_rate_country" name="shipping_rate_country[]">
                        <option value="*"><?php _e('- Everything -', 'com_shop'); ?></option>
                        <?php foreach ((array) $this->countries as $o) { ?>
                            <option value="<?php echo $o->country_2_code; ?>" <?php echo (in_array($o->country_2_code, $this->row->shipping_rate_country)) ? "selected='selected'" : ""; ?> ><?php echo strings::htmlentities($o->country_name); ?></option>
                        <?php } ?>
                    </select>
                </li>
                <li>
                    <label for="shipping_rate_state">
                        <?php _e('State:', 'com_shop'); ?>
                    </label>
                    <div id="changestate">
                        <select multiple="multiple" id="shipping_rate_state" name="shipping_rate_state[]">
                            <?php foreach ((array) $this->states as $o) { ?>
                                <option value="<?php echo $o->state_2_code; ?>" <?php echo (in_array($o->state_2_code, $this->row->shipping_rate_state)) ? "selected='selected'" : ""; ?> ><?php echo strings::htmlentities($o->state_name); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </li>
                <li>
                    <label for="shipping_rate_value">
                        <?php _e('Shipping Rate:', 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo (double) $this->row->shipping_rate_value; ?>" id="shipping_rate_value" name="shipping_rate_value" />
                </li>
                <li>
                    <label for="shipping_rate_priority">
                        <?php _e('Shipping Priority:', 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo (int) $this->row->shipping_rate_priority; ?>" id="shipping_rate_priority" name="shipping_rate_priority" />
                </li>
                <li>
                    <label for="shipping_tax_group_id">
                        <?php _e('Shipping vat group:', 'com_shop'); ?>
                    </label>
                    <select  id="shipping_tax_group_id" name="shipping_tax_group_id">
                        <option value="0"><?php _e('global', 'com_shop'); ?></option>
                        <?php foreach ((array) $this->vat_groups as $o) { ?>
                            <option value="<?php echo $o->tax_group_id; ?>" <?php echo ($o->tax_group_id == $this->row->shipping_tax_group_id) ? "selected='selected'" : ""; ?> >
                                <?php echo strings::htmlentities($o->tax_group_name); ?>
                            </option>
                        <?php } ?>
                    </select>
                </li>
                <li>
                    <label for="apply_vat">
                        <?php _e('Add Vat:', 'com_shop'); ?>
                    </label>
                    <select name="apply_vat" id="apply_vat">
                        <option value="global"  <?php echo ($this->row->apply_vat == 'global') ? "selected='selected'" : ""; ?> ><?php _e('global', 'com_shop'); ?></option>
                        <option value="yes" <?php echo ($this->row->apply_vat == 'yes') ? "selected='selected'" : ""; ?> ><?php _e('yes', 'com_shop'); ?></option>
                        <option value="no"  <?php echo ($this->row->apply_vat == 'no') ? "selected='selected'" : ""; ?> ><?php _e('no', 'com_shop'); ?></option>
                    </select>
                </li>
                <li>
                    <label for="apply_vat">
                        <?php _e('Multiply by quantity sum:', 'com_shop'); ?>
                    </label>
                    <select name="qty_multiply" id="qty_multiply">
                        <option value="no"  <?php echo ($this->row->qty_multiply == 'no') ? "selected='selected'" : ""; ?> ><?php _e('no', 'com_shop'); ?></option>
                        <option value="yes" <?php echo ($this->row->qty_multiply == 'yes') ? "selected='selected'" : ""; ?> ><?php _e('yes', 'com_shop'); ?></option>
                    </select>
                </li>
            </ul>
        </fieldset>
        <input type="hidden" name="task" value="save_rate" />
        <input type="hidden" name="shipping_rate_id" value="<?php echo $this->row->shipping_rate_id; ?>" />
        <input type="hidden" name="carrier" value="<?php echo $this->row->carrier | Factory::getApplication()->getInput()->get('cid', null, "INT"); ?>" />
    </form>
</div>