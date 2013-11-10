<form method='post' name='ccard' action='<?php echo Route::get("component=shop&con=cart&task=checkout"); ?>' >
<?php do_action("com_shop_before_ccard_form",$this); ?>
    <table>
        <tr>
            <td><?php _e('Card holder name:', 'com_shop'); ?></td>
            <td><input type='text' name='card_holder_name' value='<?php echo strings::htmlentities(request::getString('card_holder_name')); ?>' /></td>
        </tr>
        <tr>
            <td><?php _e('Card number', 'com_shop'); ?></td>
            <td><input type='text' name='card_number' value='<?php echo strings::htmlentities(request::getString('card_number')); ?>' /></td>
        </tr>
        <tr>
            <td><?php _e('Expiry date:', 'com_shop'); ?></td>
            <td><select  name="card_expire_month" size="1" class="inputbox">
                    <?php
                    $months = range(1, 12);
                    foreach ($months as $month) {
                        ?>
                        <option value='<?php echo $month; ?>' <?php echo request::getString('card_expire_month', date('n')) == $month ? "selected='selected'" : ""; ?> ><?php echo $month; ?></option>
                    <?php }
                    ?>
                </select>
                /<select class="inputbox" name="card_expire_year" size="1">

                    <?php
                    $dates = range(date('Y'), date('Y') + 10);
                    foreach ($dates as $date) {
                        ?>
                        <option value='<?php echo $date; ?>' <?php echo request::getString('card_expire_year') == $date ? "selected='selected'" : ""; ?>><?php echo $date; ?> </option>
                    <?php } ?>
                </select></td>
        </tr>
        <?php if ($this->require_cvv) { ?>
            <tr>
                <td><?php _e('Card Security Number:', 'com_shop'); ?></td>
                <td><input type="password" autocomplete="off" value="" name="card_code" ></td>
            </tr>
        <?php } ?>

        <?php if ($this->require_ctype) { ?>
            <tr>
                <td><?php _e('Card type:', 'com_shop'); ?></td>
                <td>
                    <select name='card_type'>
                        <?php foreach ((array) $this->cards as $card) { ?>

                            <option <?php echo request::getString('card_type') == $card->get_symbol() ? "selected='selected'" : ""; ?>  value="<?php echo $card->get_symbol(); ?>">
                                <?php echo $card->get_name(); ?>
                            </option>

                        <?php } ?>
                    </select>
                </td>
            </tr>
        <?php } ?>
    </table>
	<?php do_action("com_shop_after_ccard_form",$this); ?>
    <input type='hidden' name='ccard_form' value='1' />
    <input type='submit' name='' value='pay' />
</form>