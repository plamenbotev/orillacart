<div class="com_shop">
    <form method='post' name='adminForm' enctype="multipart/form-data" action='<?php echo admin_url('admin.php?page=component_com_shop-stockroom'); ?>' >
        <input type='hidden' name='task' value='save' />
        <input type='hidden' name='id' value='<?php echo is_object($this->row) ? $this->row->id : 0; ?>' />

        <fieldset class="panelform">
            <ul class="adminformlist">
                <li>
                    <label for="stockroom_name">
                        <?php _e('Name:', 'com_shop'); ?>
                    </label>
                    <input class="text_area" type="text" name="name" id="stockroom_name" value="<?php echo is_object($this->row) ? strings::stripAndEncode($this->row->name) : ''; ?>" />
                </li>
                <li>
                    <label>
                        <?php _e('Delivery time in:', 'com_shop'); ?>
                    </label>
                    <fieldset class="panelform">
                        <input type="radio" name="delivery_time" id="delivery_timeDays" value="day" <?php echo is_object($this->row) ? ($this->row->delivery_time == 'day') ? 'checked="checked"' : ''  : 'checked="checked"'; ?>  />
                        <label for="delivery_timeDays"> <?php _e('Days', 'com_shop'); ?></label>
                        <input type="radio" name="delivery_time" id="delivery_timeWeeks" value="week" <?php echo is_object($this->row) ? ($this->row->delivery_time == 'week') ? 'checked="checked"' : ''  : ''; ?> />
                        <label for="delivery_timeWeeks"> <?php _e('Weeks', 'com_shop'); ?></label>
                    </fieldset>
                </li>
                <li>
                    <label for="min_del_time">
                        <?php _e('Minimum delivery time:', 'com_shop'); ?>
                    </label>
                    <input type="text" name="min_del_time" id="min_del_time" value="<?php echo is_object($this->row) ? (int) $this->row->min_del_time : ''; ?>" />
                </li>
                <li>
                    <label for="max_del_time">
                        <?php _e('Maximum delivery time:', 'com_shop'); ?>
                    </label>
                    <input type="text" name="max_del_time" id="max_del_time" value="<?php echo is_object($this->row) ? (int) $this->row->max_del_time : ''; ?>" />
                </li>
                <li>
                    <label>
                        <?php _e('Published:', 'com_shop'); ?>
                    </label>
                    <fieldset class="panelform">
                        <input type="radio" name="published" id="published0" value="no" <?php echo is_object($this->row) ? ($this->row->published == 'no') ? 'checked="checked"' : ''  : 'checked="checked"'; ?> class="inputbox" />
                        <label for="published0"><?php _e('No', 'com_shop'); ?></label>
                        <input type="radio" name="published" id="published1" value="yes" <?php echo is_object($this->row) ? ($this->row->published == 'yes') ? 'checked="checked"' : ''  : ''; ?> class="inputbox" />
                        <label for="published1"><?php _e('Yes', 'com_shop'); ?></label>
                    </fieldset>
                </li>
                <li>
                    <label for="stockroom_desc">
                        <?php _e('Description', 'com_shop'); ?>
                    </label>
                    <textarea id="stockroom_desc" name="desc" cols="100" rows="20"  class="mce_editable"><?php echo is_object($this->row) ? strings::stripAndEncode($this->row->desc) : ''; ?></textarea>
                </li>
            </ul>
        </fieldset>
    </form>
</div>