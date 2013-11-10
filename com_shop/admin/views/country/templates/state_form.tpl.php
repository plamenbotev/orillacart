<div class="com_shop">
    <form target="_self" name="adminForm" action="<?php echo admin_url('admin.php?page=component_com_shop-country'); ?>" method="post"> 
        <input type='hidden' name='task' value='save_state' />
        <input type="hidden" value="<?php echo $this->row->state_id; ?>" name="state_id" />
        <input type="hidden" value="<?php echo $this->country_id; ?>" name="country_id" />

        <fieldset class="panelform">
            <ul class="adminformlist">
                <li>
                    <label for="state_name">
                        <?php _e('State Name:', 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo strings::htmlentities($this->row->state_name); ?>" name="state_name" id="state_name" />
                </li>
                <li>
                    <label for="state_2_code">
                        <?php _e('State Code (2):', 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo strings::htmlentities($this->row->state_2_code); ?>" name="state_2_code" id="state_2_code" />
                </li>
                <li>
                    <label for="state_3_code">
                        <?php _e('State Code (3):', 'com_shop'); ?>
                    </label>
                    <input type="text" value="<?php echo strings::htmlentities($this->row->state_3_code); ?>" name="state_3_code" id="state_3_code" />
                </li>
            </ul>
        </fieldset>
        <input type="hidden" value="<?php echo $this->row->state_id; ?>" name="state_id">
        <input type="hidden" value="<?php echo $this->country_id; ?>" name="country_id">
    </form>
</div>