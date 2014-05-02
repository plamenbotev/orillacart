<?php defined('_VALID_EXEC') or die('access denied'); ?>

<div class="paramsContainer">
    <fieldset class="panelform">
        <ul class="adminformlist">
            <li>
                <label class="hasTip" for="email_from_address">
                    <?php _e('Mails from e-mail:', 'com_shop'); ?>
                </label>
                <input type="text" value="<?php echo $this->settings->get('email_from_address'); ?>" name="email_from_address" id="email_from_address">         
            </li>
            <li>
                <label class="hasTip" for="email_from_name">
                    <?php _e('Mails from name:', 'com_shop'); ?>
                </label>
                <input type="text" value="<?php echo $this->settings->get('email_from_name'); ?>" name="email_from_name" id="email_from_name">
            </li>
            <li>
                <label class="hasTip" for="notify_admin_on_new_order">
                    <?php _e('Notify admin on order', 'com_shop'); ?>
                </label>
                <input type="checkbox" value="1" <?php echo $this->settings->get('notify_admin_on_new_order') ? 'checked="checked"' : ''; ?> id="notify_admin_on_new_order" name="notify_admin_on_new_order">
            </li>
            <li>
                <label class="hasTip" for="notify_admin_mail">
                    <?php _e('Notify e-mail:', 'com_shop'); ?>
                </label>
                <input type="text" value="<?php echo $this->settings->get('notify_admin_mail'); ?>" name="notify_admin_mail" id="notify_admin_mail">
            </li>
            <li>
                <label class="hasTip" for="download_method">
                    <?php _e('File download method', 'com_shop'); ?>
                </label>
                <select name="download_method" id="download_method">
                    <option value="readfile" <?php echo $this->settings->get('download_method') == 'readfile' ? "selected='selected'" : ""; ?> >
                        <?php _e('readfile', 'com_shop'); ?>
                    </option>
                    <option value="xsendfile" <?php echo $this->settings->get('download_method') == 'xsendfile' ? "selected='selected'" : ""; ?> >
                        <?php _e('xsendfile', 'com_shop'); ?>
                    </option>

                </select>
            </li>
            <li>
                <label class="hasTip" for="catalogOnly">
                    <?php _e('Use only as catalogue', 'com_shop'); ?>
                </label>
                <input name="catalogOnly" type="checkbox" value="1"  <?php echo $this->settings->get('catalogOnly') ? 'checked="checked"' : ''; ?> id="catalogOnly">

            </li>
			<li>
               <label class="hasTip" for="hide_the_price">
                   <?php _e('Hide product price', 'com_shop'); ?>
               </label>
               <input name="hide_the_price" type="checkbox" value="1"  <?php echo $this->settings->get('hide_the_price') ? 'checked="checked"' : ''; ?> id="hide_the_price">

           </li>
            <li>
                <label class="hasTip" for="retail_countries">
                    <?php _e('Retail Countries', 'com_shop'); ?>
                </label>
                <select name='retail_countries[]' multiple='multiple' id="retail_countries" size='5'>
                    <?php foreach ((array) $this->countries as $country) { ?>
                        <option <?php echo is_array($this->settings->get('retail_countries')) && in_array($country->country_2_code, $this->settings->get('retail_countries')) ? "selected='selected'" : ''; ?> value='<?php echo $country->country_2_code; ?>'>
                            <?php echo strings::stripAndEncode($country->country_name); ?>
                        </option>
                    <?php } ?>
                </select>
            </li>
            <li>
                <label class="hasTip" for="price_decimal">
                    <?php _e('Price Decimal', 'com_shop'); ?>
                </label>
                <input type="text" value="<?php echo $this->settings->get('price_decimal'); ?>" id="price_decimal" name="price_decimal" />
            </li>
            <li>
                <label class="hasTip" for="price_separator">
                    <?php _e('Price Separator', 'com_shop'); ?>
                </label>
                <input type="text" value="<?php echo $this->settings->get('price_separator'); ?>" id="price_separator" name="price_separator" />
            </li>
            <li>
                <label class="hasTip" for="thousand_separator">
                    <?php _e('Thousand Separator', 'com_shop'); ?>
                </label>
                <input type="text" value="<?php echo $this->settings->get('thousand_separator'); ?>" id="thousand_separator" name="thousand_separator" />
            </li>
            <li>
                <label class="hasTip" for="currency">
                    <?php _e('Currency', 'com_shop'); ?>
                </label>
                <input type="text" value="<?php echo $this->settings->get('currency'); ?>" id="currency" name="currency">
            </li>
            <li>
                <label class="hasTip" for="currency_sign">
                    <?php _e('Currency Sign', 'com_shop'); ?>
                </label>
                <input type="text" value="<?php echo $this->settings->get('currency_sign'); ?>" id="currency_sign" name="currency_sign">
            </li>
            <li>
                <label class="hasTip" for="currency_place">
                    <?php _e('Currency Place', 'com_shop'); ?>
                </label>
                <select name="currency_place">
                    <option <?php echo "before" == $this->settings->get('currency_place') ? "selected='selected'" : ''; ?> value="before"><?php _e("Before the price ($100)", "com_shop"); ?></option>
                    <option <?php echo "before_with_space" == $this->settings->get('currency_place') ? "selected='selected'" : ''; ?> value="before_with_space"><?php _e("Before the price with space ($ 100)", "com_shop"); ?></option>
                    <option <?php echo "after" == $this->settings->get('currency_place') ? "selected='selected'" : ''; ?> value="after"><?php _e("After the price (100$)", "com_shop"); ?></option>
                    <option <?php echo "after_with_space" == $this->settings->get('currency_place') ? "selected='selected'" : ''; ?> value="after_with_space"><?php _e("After the price with space (100 $)", "com_shop"); ?></option>
                </select>
            </li>
            <li>
                <label class="hasTip" for="vat">
                    <?php _e('Virtual Tax', 'com_shop'); ?>
                </label>
                <input type="checkbox" value="1" <?php echo $this->settings->get('vat') ? 'checked="checked"' : ''; ?> id="vat" name="vat">
            </li>
            <li>
                <label class="hasTip" for="shop_country">
                    <?php _e('Shop Country', 'com_shop'); ?>
                </label>
                <select id="shop_country" name='shop_country' onchange='jsShopAdminHelper.loadStates(this.value, "states_container");'>
                    <option value=''></option>
                    <?php foreach ((array) $this->countries as $country) { ?>
                        <option <?php echo $country->country_2_code == $this->settings->get('shop_country') ? "selected='selected'" : ''; ?> value='<?php echo $country->country_2_code; ?>'>
                            <?php echo strings::stripAndEncode($country->country_name); ?>
                        </option>
                    <?php } ?>
                </select>
            </li>
            <li>
                <label class="hasTip" for="shop_state">
                    <?php _e('Shop State', 'com_shop'); ?>
                </label>
                <span id="states_container">
                    <select id="shop_state" name='shop_state'>
                        <option value=''></option>
                        <?php foreach ((array) $this->states as $state) { ?>
                            <option <?php echo ($state->state_2_code == $this->settings->get('shop_state') ? "selected='selected'" : ''); ?> value='<?php echo $state->state_2_code; ?>'>
                                <?php echo strings::stripAndEncode($state->state_name); ?>
                            </option>
                        <?php } ?>
                    </select>
                </span>
            </li>
            <li>
                <label class="hasTip" for="shop_zip">
                    <?php _e('Shop Zip', 'com_shop'); ?>
                </label>
                <input type="text" value="<?php echo $this->settings->get('shop_zip'); ?>" id="shop_zip" name="shop_zip" />
            </li>
            <li>
                <label class="hasTip" for="shop_tax_group">
                    <?php _e('Default tax group', 'com_shop'); ?>
                </label>
                <select id="shop_tax_group" name='shop_tax_group'>
                    <option value=''></option>
                    <?php foreach ((array) $this->tax_groups as $tax) { ?>
                        <option <?php echo $tax->tax_group_id == $this->settings->get('shop_tax_group') ? "selected='selected'" : ''; ?> value='<?php echo $tax->tax_group_id; ?>'>
                            <?php echo strings::stripAndEncode($tax->tax_group_name); ?>
                        </option>
                    <?php } ?>
                </select>
            </li>
            <li>
                <label class="hasTip" for="vatType">
                    <?php _e('Tax mode:', 'com_shop'); ?>
                </label>
                <select id="vatType" name="vatType">
                    <option value="0" <?php echo $this->settings->get('vatType') == 0 ? 'selected="selected"' : ''; ?> >
                        <?php _e('Based on shipping address', 'com_shop'); ?>
                    </option>
                    <option  value="1" <?php echo $this->settings->get('vatType') == 1 ? 'selected="selected"' : ''; ?>>
                        <?php _e('Based on vendor address', 'com_shop'); ?>
                    </option>
                    <option  value="2" <?php echo $this->settings->get('vatType') == 2 ? 'selected="selected"' : ''; ?>>
                        <?php _e('EU mode', 'com_shop'); ?>
                    </option>
                    <option  value="3" <?php echo $this->settings->get('vatType') == 3 ? 'selected="selected"' : ''; ?>>
                        <?php _e('Based on billing address', 'com_shop'); ?>
                    </option>
                </select>
            </li>
            <li>
                <label class="hasTip" for="userReg">
                    <?php _e('User Registration Type', 'com_shop'); ?>
                </label>
                <select id="userReg" name="userReg">
                    <option value="0" <?php echo $this->settings->get('userReg') == 0 ? "selected='selected'" : ""; ?> >optional</option>
                    <option value="1" <?php echo $this->settings->get('userReg') == 1 ? "selected='selected'" : ""; ?> >required</option>
                    <option value="2" <?php echo $this->settings->get('userReg') == 2 ? "selected='selected'" : ""; ?> >guest only</option>
                </select>
            </li>
            <li>
                <label class="hasTip" for="checkStock">
                    <?php _e('Check Stock', 'com_shop'); ?>
                </label>
                <input type="checkbox" value="1" <?php echo $this->settings->get('checkStock') ? 'checked="checked"' : ''; ?> id="checkStock" name="checkStock">
            </li>
            <li>
                <label class="hasTip" for="shipping">
                    <?php _e('Enable shipping?', 'com_shop'); ?>
                </label>
                <input type="checkbox" value="1" name="shipping" <?php echo $this->settings->get('shipping') ? 'checked="checked"' : ''; ?> id="shipping">
            </li>
            <li>
                <label class="hasTip" for="default_volume_unit">
                    <?php _e('Default volume unit:', 'com_shop'); ?>
                </label>
                <select class="inputbox" name="default_volume_unit" id="default_volume_unit">
                    <option  <?php echo $this->settings->get('default_volume_unit') == 'mm' ? "selected='selected'" : ""; ?> value="mm"><?php _e('Millimeter', 'com_shop'); ?></option>
                    <option  <?php echo $this->settings->get('default_volume_unit') == 'cm' ? "selected='selected'" : ""; ?> value="cm"><?php _e('Centimeters', 'com_shop'); ?></option>
                    <option  <?php echo $this->settings->get('default_volume_unit') == 'inch' ? "selected='selected'" : ""; ?> value="inch"><?php _e('Inches', 'com_shop'); ?></option>
                    <option  <?php echo $this->settings->get('default_volume_unit') == 'feet' ? "selected='selected'" : ""; ?> value="feet"><?php _e('Feet', 'com_shop'); ?></option>
                    <option  <?php echo $this->settings->get('default_volume_unit') == 'm' ? "selected='selected'" : ""; ?> value="m"><?php _e('Meter', 'com_shop'); ?></option>
                </select>
            </li>
            <li>
                <label class="hasTip" for="default_weight_unit">
                    <?php _e('Default weight unit:', 'com_shop'); ?>
                </label>
                <select class="inputbox" name="default_weight_unit" id="default_weight_unit">
                    <option  <?php echo $this->settings->get('default_weight_unit') == 'gram' ? "selected='selected'" : ""; ?> value="gram"><?php _e('Grams', 'com_shop'); ?></option>
                    <option <?php echo $this->settings->get('default_weight_unit') == 'pounds' ? "selected='selected'" : ""; ?> value="pounds"><?php _e('Pounds', 'com_shop'); ?></option>
                    <option <?php echo $this->settings->get('default_weight_unit') == 'kg' ? "selected='selected'" : ""; ?> value="kg"><?php _e('Kg.', 'com_shop'); ?></option>
                </select>
            </li>
        </ul>
    </fieldset>
</div>