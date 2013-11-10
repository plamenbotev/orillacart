<dt id="tab1"><span><?php _e('Information', 'com_shop'); ?></span></dt>
<dd>
    <fieldset class="panelform">
        <ul class="adminformlist">
            <li>
                <label for="price"> <?php _e('Price:', 'com_shop'); ?> </label>
                <input class="text_area" type="text" name="price" id="price" size="10" maxlength="10" value="<?php echo strings::stripAndEncode($this->row->product->price); ?>" />
            </li>
            <li>
                <label class="hasTip" title="<?php _e('Stock keeping unit.', 'com_shop'); ?>" for="sku"> <?php _e('SKU:', 'com_shop'); ?> </label>
                <input class="text_area" type="text" name="sku" id="sku" size="32" maxlength="250" value="<?php echo strings::stripAndEncode($this->row->product->sku); ?>" />
            </li>
            
            <li>
                <label class="hasTip" title="<?php _e('Ordering', 'com_shop'); ?>" for="product_menu_order"> <?php _e('Ordering:', 'com_shop'); ?> </label>
                <input type="text" id="product_menu_order" name="menu_order" value="<?php echo $this->post->menu_order; ?>" />
            </li>
            <li>
                <label class="hasTip" title="<?php _e('Choose product type.', 'com_shop'); ?>" for="type"><?php _e('Product type:', 'com_shop'); ?></label>
                <select name="type"  id="type" class="inputbox" size="1" onchange="this.value == 'digital' ? jQuery('li.digital').css('display', 'block') : jQuery('li.digital').css('display', 'none');" >
                    <?php foreach ((array) array('regular', 'digital', 'virtual') as $b) { ?>
                        <option value='<?php echo $b ?>' <?php echo $b == $this->row->product->type ? " selected='selected' " : ""; ?> ><?php echo strings::htmlentities($b); ?></option>
                    <?php } ?>
                </select>
            </li>
            <li class="digital" style="display:<?php echo $this->row->product->type == 'digital' ? 'block' : 'none'; ?>">
                <label class="hasTip" title="<?php _e('upload files for that digital product.', 'com_shop'); ?>" for="uploadfiles">Choose files</label>
                <button class="btn btn-small upload_file_button">
                    <span class="icon-upload"></span>
                    Upload a file
                </button>
            </li>
            <li class="digital" style="display:<?php echo $this->row->product->type == 'digital' ? 'block' : 'none'; ?>">
                <label class="hasTip" title="<?php _e('Multiply download limit by quantity of ordered items.', 'com_shop'); ?>" for="file_limit_qty"><?php _e('Download limit multiply by quantity:', 'com_shop'); ?></label>
                <fieldset class="panelform">
                    <input id="download_limit_multiply_qty_no" name="download_limit_multiply_qty" <?php echo!$this->row->product->download_limit_multiply_qty ? "checked='checked'" : ''; ?> type="radio" value="0" /> <label for="download_limit_multiply_qty_no"><?php _e('No', 'com_shop'); ?></label>
                    <input id="download_limit_multiply_qty_yes" name="download_limit_multiply_qty" <?php echo $this->row->product->download_limit_multiply_qty ? "checked='checked'" : ''; ?>  type="radio" value="1" /> <label for="download_limit_multiply_qty_yes"><?php _e('Yes', 'com_shop'); ?></label>
                </fieldset>
            </li>
            <li class="digital" style="display:<?php echo $this->row->product->type == 'digital' ? 'block' : 'none'; ?>">
                <label class="hasTip" title="<?php _e('Allow user to choose which files to purchase.', 'com_shop'); ?>" for="download_choose_file"><?php _e('Allow customer to choose file:', 'com_shop'); ?></label>
                <fieldset class="panelform">
                    <input id="download_choose_file_no" name="download_choose_file" <?php echo!$this->row->product->download_choose_file ? "checked='checked'" : ''; ?> type="radio" value="0" /> <label for="download_choose_file_no"> <?php _e('No', 'com_shop'); ?></label>
                    <input id="download_choose_file_yes" name="download_choose_file" <?php echo $this->row->product->download_choose_file ? "checked='checked'" : ''; ?>  type="radio" value="1" /> <label for="download_choose_file_yes"><?php _e('Yes', 'com_shop'); ?></label>
                </fieldset>
            </li>
            <li class="digital" style="display:<?php echo $this->row->product->type == 'digital' ? 'block' : 'none'; ?>">
                <label class="hasTip" title="<?php _e('Number of allowed downloads for file in that product.', 'com_shop'); ?>" for="download_limit"> <?php _e('Download Limit', 'com_shop'); ?> </label>
                <input type="text" placeholder="<?php _e('Unlimited', 'com_shop'); ?>" value="<?php echo $this->row->product->download_limit; ?>" id="download_limit" name="download_limit" />
            </li>
            <li class="digital" style="display:<?php echo $this->row->product->type == 'digital' ? 'block' : 'none'; ?>">
                <label class="hasTip" title="<?php _e('Number of days, download to be allowed.', 'com_shop'); ?>" for="download_expiry"> <?php _e('Download Expiry', 'com_shop'); ?> </label>
                <input type="text" placeholder="<?php _e('Never', 'com_shop'); ?>" value="<?php echo $this->row->product->download_expiry; ?>" id="download_expiry" name="download_expiry" /> 
            </li>
            <li>
                <label class="hasTip" title="<?php _e('Mark product as on-sale.', 'com_shop'); ?>" for="onsale"><?php _e('Product on sale:', 'com_shop'); ?></label>
                <fieldset class="panelform">
                    <input type="radio" name="onsale" id="onsale0" value="no" <?php echo $this->row->product->onsale == 'no' ? 'checked="checked"' : ''; ?>  />
                    <label for="onsale0"><?php _e('No', 'com_shop'); ?></label>
                    <input type="radio" name="onsale" id="onsale1" value="yes" <?php echo $this->row->product->onsale == 'yes' ? 'checked="checked"' : ''; ?> />
                    <label for="onsale1"><?php _e('Yes', 'com_shop'); ?></label>
                </fieldset>
            </li>
            <li>
                <label class="hasTip" title="<?php _e('Mark product as special.', 'com_shop'); ?>" for=""><?php _e('Special Product:', 'com_shop'); ?></label>
                <fieldset class="panelform">
                    <input type="radio" name="special" id="special0" value="no" <?php echo $this->row->product->special == 'no' ? 'checked="checked"' : ''; ?>  class="inputbox" />
                    <label for="special0"><?php _e('No', 'com_shop'); ?></label>
                    <input type="radio" name="special" id="special1" value="yes" <?php echo $this->row->product->special == 'yes' ? 'checked="checked"' : ''; ?> class="inputbox" />
                    <label for="special1"><?php _e('Yes', 'com_shop'); ?></label>
                </fieldset>
            </li>
            <li>
                <label><?php _e('Expired?:', 'com_shop'); ?></label>
                <fieldset class="panelform">
                    <input type="radio" name="expired" id="expired0" value="no" <?php echo $this->row->product->expired == 'no' ? 'checked="checked"' : ''; ?>  class="inputbox" />
                    <label for="expired0"><?php _e('No', 'com_shop'); ?></label>
                    <input type="radio" name="expired" id="expired1" value="yes" <?php echo $this->row->product->expired == 'yes' ? 'checked="checked"' : ''; ?> class="inputbox" />
                    <label for="expired1"><?php _e('Yes', 'com_shop'); ?></label>
                </fieldset>
            </li>
            <li>
                <label><?php _e('Product is not for sale:', 'com_shop'); ?></label>
                <fieldset class="panelform">
                    <input type="radio" name="not_for_sale" id="not_for_sale0" value="no"  <?php echo $this->row->product->not_for_sale == 'no' ? 'checked="checked"' : ''; ?> class="inputbox" />
                    <label for="not_for_sale0"><?php _e('No', 'com_shop'); ?></label>
                    <input type="radio" name="not_for_sale" id="not_for_sale1" value="yes"  <?php echo $this->row->product->not_for_sale == 'yes' ? 'checked="checked"' : ''; ?> class="inputbox" />
                    <label for="not_for_sale1"><?php _e('Yes', 'com_shop'); ?></label>
                </fieldset>
            </li>
            <li>
                <label for="discount_price"> <?php _e('Discount Price:', 'com_shop'); ?> </label>
                <input  type="text" name="discount_price" id="discount_price" size="10" maxlength="10" value="<?php echo strings::stripAndEncode($this->row->product->discount_price); ?> " />
            </li>
            <li>
                <label for="discount_start"> <?php _e('Discount Start Date:', 'com_shop'); ?> </label>
                <input type="text" name="discount_start" id="discount_start" value="<?php echo strings::stripAndEncode($this->row->product->discount_start); ?>"  class="calendar" size="15" maxlength="19" />
            </li>
            <li>
                <label for="discount_end"> <?php _e('Discount End Date:', 'com_shop'); ?> </label>
                <input type="text" name="discount_end" id="discount_end"  value="<?php echo strings::stripAndEncode($this->row->product->discount_end); ?>" class="calendar" size="15" maxlength="19" />
            </li>
            <li>
                <label for="vat"> <?php _e('Add VAT:', 'com_shop'); ?> </label>
                <select name="vat" id="vat" class="inputbox" size="1" >
                    <option value='global' <?php echo ($this->row->product->vat == 'global') ? "selected='selected'" : ''; ?>><?php _e('global', 'com_shop'); ?></option>
                    <option value="no" <?php echo ($this->row->product->vat == 'no') ? "selected='selected'" : ''; ?> ><?php _e('no', 'com_shop'); ?></option>
                    <option value="yes" <?php echo ($this->row->product->vat == 'yes') ? "selected='selected'" : ''; ?> ><?php _e('yes', 'com_shop'); ?></option>
                </select>
            </li>
            <li>
                <label for="tax_group_id"> <?php _e('VAT group:', 'com_shop'); ?> </label>
                <select name="tax_group_id" id="tax_group_id" class="inputbox" size="1" >
                    <option value='' <?php echo (!$this->row->product->tax_group_id) ? "selected='selected'" : ''; ?>><?php _e('default', 'com_shop'); ?></option>
                    <?php foreach ((array) $this->tax_groups as $group) { ?>
                        <option value='<?php echo $group->tax_group_id; ?>' <?php echo ($this->row->product->tax_group_id == $group->tax_group_id) ? " selected='selected'" : ""; ?>>
                            <?php echo strings::stripAndEncode($group->tax_group_name); ?>
                        </option>
                    <?php } ?>
                </select>
            </li>
            <li>
                <label for="brand"> <?php _e('Product Manufacturer:', 'shop'); ?> </label>
                <select name="brand[]" multiple="multiple" size="5" id="brand" class="inputbox" size="1" >
                    <option value=''></option>
                    <?php foreach ((array) $this->brands as $b) { ?>
                        <option value='<?php echo $b->term_id; ?>' <?php echo in_array($b->term_id, $this->row->brands) ? " selected='selected' " : ""; ?> ><?php echo strings::htmlentities($b->name); ?></option>
                    <?php } ?>
                </select>
            </li>

            <li>
                <label for="brand"> <?php _e('Shipping Group:', 'shop'); ?> </label>
                <select name="shipping_group" id="shipping_group" class="inputbox" size="1" >
                    <option value=''></option>
                    <?php foreach ((array) $this->shipping_groups as $b) { ?>
                        <option value='<?php echo $b->term_id; ?>' <?php echo in_array($b->term_id, $this->row->shipping_groups) ? " selected='selected' " : ""; ?> ><?php echo strings::htmlentities($b->name); ?></option>
                    <?php } ?>
                </select>
            </li>
            <li>
                <label class="hasTip" title="<?php _e('Template.', 'com_shop'); ?>" for="tpl"> <?php _e('Template:', 'com_shop'); ?> </label>
                <select name="tpl" id="tpl">
                    <option value="" <?php echo empty($this->row->product->tpl) ? 'selected="selected"' : '' ?>>Default</option>
                    <?php foreach ((array) $this->templates as $tpl) { ?>
                        <option <?php echo strings::htmlentities($tpl) == strings::htmlentities($this->row->product->tpl) ? 'selected="selected"' : ''; ?> value="<?php echo strings::htmlentities($tpl); ?>">
                            <?php echo strings::htmlentities($tpl); ?>
                        </option>
                    <?php } ?>

                </select>
            </li>
        </ul>
    </fieldset>
</dd>