<div class="com_shop">
    <form name='adminForm' method='post' enctype="multipart/form-data" action='<?php echo admin_url('admin.php?page=component_com_shop-attributes'); ?>'>
        <input type='hidden' name='task' value='save' />
        <input type='hidden' name='attribute_set_id' value='<?php echo $this->set->attribute_set_id; ?>' />

        <fieldset class="panelform">
            <ul class="adminformlist">
                <li>
                    <label for="attribute_set_name">
                        <?php _e('Attribute Set Name:', 'com_shop'); ?>
                    </label>
                    <input type="text" name="attribute_set_name" id="attribute_set_name" value="<?php echo strings::htmlentities($this->set->attribute_set_name); ?>" />
                </li>
                <li>
                    <label for=""><?php _e('Published:', 'com_shop'); ?></label>
                    <fieldset class="panelform">
                        <input type="radio" name="published" id="published0" value="no" <?php echo $this->set->published == 'no' ? 'checked="checked"' : ''; ?> />
                        <label for="published0"><?php _e('No', 'com_shop'); ?></label>
                        <input type="radio" name="published" id="published1" value="yes" <?php echo $this->set->published == 'yes' ? 'checked="checked"' : ''; ?> />
                        <label for="published1"><?php _e('Yes', 'com_shop'); ?></label>
                    </fieldset>
                </li>
            </ul>
        </fieldset>

        <button class="btn btn-success" onclick="new_attribute();
                return false;" ><?php _e('Add New Attribute', 'com_shop'); ?></button>

        <span style="display: none;" id="atitle"><?php _e('Title', 'com_shop'); ?></span>
        <span style="display: none;" id="atitlerequired"> <?php _e('Required Attribute', 'com_shop'); ?></span>
        <span style="display: none;" id="spn_hide_attribute_price"><?php _e('Hide Attribute Price', 'com_shop'); ?></span>
        <span style="display: none;" id="aproperty"> <b><?php _e('Enter sub attribute', 'com_shop'); ?></b></span>
        <span style="display: none;" id="aprice"> <?php _e('Price', 'com_shop'); ?></span>
        <span style="display: none;" id="new_property"> <?php _e('Enter sub attribute', 'com_shop'); ?></span>
        <span style="display:none;" id="delete_attribute"><?php _e('Delete Attribute', 'com_shop'); ?></span>
        <span style="display:none;" id="aordering"><?php _e('Order', 'com_shop'); ?></span>
        <span style="display:none;" id="showpropertytitlespan"><?php _e('Show property title', 'com_shop'); ?></span>
        <span id='stock_rooms' style='display:none;'>
            <select id='stock_room_selector'>
                <option value='' selected='selected'  ><?php _e('select stock room', 'com_shop'); ?></option>

                <?php while ($sr = $this->stock_rooms->nextObject()) { ?>

                    <option value='<?php echo $sr->id; ?>' > <?php echo strings::htmlentities($sr->name); ?></option>

                <?php } ?>
            </select>
        </span>

        <div id='attributes'>

            <?php
            if(!empty($this->set->_data)) for ($c = 0; $c < count($this->set->_data); $c++) {
                $att = $this->set->_data[$c]['att'];
                ?>


                <div class="attributeContainer" id="att-<?php echo $c; ?>">

                    <div class='boxHeader'>

                        <input type='hidden' name='attribute_id[<?php echo $c; ?>][id]' value='<?php echo $att->attribute_id; ?>' />
                        <strong><?php _e('Title', 'com_shop'); ?></strong>
                        <input type='text' value='<?php echo strings::htmlentities($att->attribute_name); ?>' name='title[<?php echo $c; ?>][name]' />
                        <input type='hidden' class='aordering' name='title[<?php echo $c; ?>][ordering]' size='3' value='<?php echo $att->ordering; ?>' >
                        <button class="btn btn-small" onclick="addproperty(jQuery('.attributeProps', this.parentNode.parentNode)[0],<?php echo $c; ?>);
                                return false;"><?php _e('Enter sub attribute', 'com_shop'); ?></button>
                        <button class='btn btn-danger btn-small' onclick="jQuery(this).closest('.attributeContainer').remove();
                                return false;" >
                            <span class="icon-trash"></span>
                        </button>
                        <div title='<?php _e('Click to change', 'com_shop'); ?>' class='handlediv'><br></div>
                    </div>

                    <div class='boxBody boxClosed'>	
                        <div>
                            <?php _e('Required Attribute:', 'com_shop'); ?>
                            <input type='checkbox' value='1' <?php echo $att->attribute_required == 'yes' ? "checked='checked'" : ''; ?>  name='title[<?php echo $c; ?>][required]' />
                            &nbsp;&nbsp;<span><?php _e('Hide Attribute Price', 'com_shop'); ?></span>
                            <input type='checkbox' value="1" <?php echo $att->hide_attribute_price == 'yes' ? "checked='checked'" : ''; ?> name='title[<?php echo $c; ?>][hide_attribute_price]' />
                        </div>
                        <div class='attributeProps'>
                            <?php
                            if (count($this->set->_data[$c]['property']))
                                for ($cp = 0; $cp < count($this->set->_data[$c]['property']); $cp++) {

                                    $prop = $this->set->_data[$c]['property'][$cp];
                                    ?>

                                    <div class="subPropertyContainer">

                                        <div class='boxHeader'>

                                            <input type='hidden' name='property_id[<?php echo $c; ?>][value][]' value='<?php echo $prop->property_id; ?>' />
                                            <strong><?php _e('Title:', 'com_shop'); ?></strong>
                                            <input type='text' size='10' value="<?php echo strings::htmlentities($prop->property_name); ?>" name='property[<?php echo $c; ?>][value][]' />
                                            <input type='hidden' class='pordering' name='propordering[<?php echo $c; ?>][value][]' size='3' value='<?php echo $prop->ordering; ?>' />
                                            <button class='btn btn-danger btn-small' onclick='jQuery(this).closest(".subPropertyContainer").remove();
                                                    return false;' >
                                                <span class="icon-trash"></span>
                                            </button>
                                            <div title='<?php _e('Click to change', 'com_shop'); ?>' class='handlediv'>
                                                <br>
                                            </div>

                                        </div>

                                        <div class='boxBody boxClosed'>
                                            <?php _e('Price:', 'com_shop'); ?>
                                            <input type='text' size='2' name='oprand[<?php echo $c; ?>][value][]' value='<?php echo $prop->oprand; ?>' maxlength='1' onchange='oprand_check(this);' />
                                            <input type='text' size='2' name='att_price[<?php echo $c; ?>][value][]' value="<?php echo $prop->property_price; ?>"  />
                                            <input type='button' class="btn btn-small" id='shop_modal' name='att_stock_manager' value='stocks' onclick='jsShopAdminHelper.attribute.stockRoom(<?php echo $prop->property_id; ?>, this, "property");' />
                                        </div>



                                    </div>

                                    <?php
                                }
                            ?>

                        </div>
                    </div>



                </div>


            <?php } ?>

        </div>
        <input type="hidden" value="<?php echo $this->set->_meta->total_attributes; ?>" id="total_table" name="total_table">
        <input type="hidden" value="<?php echo $this->set->_meta->total_properties; ?>" id="total_g" name="total_g">
    </form>
</div>