<div class="com_shop">
    <form name='adminForm' method='post' action='<?php echo admin_url('admin.php?page=component_com_shop-attributes'); ?>'>
        <input type='hidden' name='task' value='save' />
        <fieldset class="panelform">
            <ul class="adminformlist">
                <li>
                    <label for="attribute_set_name">
                        <?php _e('Attribute Set Name:', 'com_shop'); ?>
                    </label>
                    <input type="text" name="attribute_set_name" id="attribute_set_name" value="" />
                </li>
                <li>
                    <label for="file_limit_qty"><?php _e('Published:', 'com_shop'); ?></label>
                    <fieldset class="panelform">
                        <input type="radio" name="published" id="published0" value="no" />
                        <label for="published0"><?php _e('No', 'com_shop'); ?></label>
                        <input type="radio" name="published" id="published1" value="yes" checked="checked" />
                        <label for="published1"><?php _e('Yes', 'com_shop'); ?></label>
                    </fieldset>
                </li>
            </ul>
        </fieldset>

        <button class="btn btn-success" onclick="new_attribute(); return false;" ><?php _e('Add New Attribute', 'com_shop'); ?></button>

        <span style="display: none;" id="atitle"><?php _e('Title', 'com_shop'); ?></span>
        <span style="display: none;" id="atitlerequired"> <?php _e('Required Attribute', 'com_shop'); ?></span>
        <span style="display: none;" id="spn_allow_multiple_selection"><?php _e('Allow Multiple Property Selection', 'com_shop'); ?></span>
        <span style="display: none;" id="spn_hide_attribute_price"><?php _e('Hide Attribute Price', 'com_shop'); ?></span>
        <span style="display: none;" id="aproperty"> <b><?php _e('Enter sub attribute', 'com_shop'); ?></b></span>
        <span style="display: none;" id="aprice"> <?php _e('Price', 'com_shop'); ?></span>
        <span style="display: none;" id="new_property"> <?php _e('Enter sub attribute', 'com_shop'); ?></span>
        <span style="display:none;" id="delete_attribute"><?php _e('Delete Attribute', 'com_shop'); ?></span>
        <span style="display:none;" id="aordering"><?php _e('Order', 'com_shop'); ?></span>
        <span style="display:none;" id="adselected"><?php _e('Default', 'com_shop'); ?></span>
        <span style="display:none;" id="showpropertytitlespan"><?php _e('Show property title', 'com_shop'); ?></span>
        <div id='attributes'></div>
        <input type="hidden" name="total_table" id="total_table"  value="0">
        <input type="hidden" name="total_g" id="total_g"  value="1">
        <input type="hidden" name="total_z" id="total_z"  value="1">

    </form>
</div>