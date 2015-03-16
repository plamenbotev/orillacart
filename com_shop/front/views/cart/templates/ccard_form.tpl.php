<form method='post' name='ccard' action='<?php echo Route::get("component=shop&con=cart&task=checkout"); ?>' >
    <?php do_action("com_shop_before_ccard_form", $this); ?>
    <div class="container-fluid clearfix">
        <div class="row clearfix">
            <div class="col-xs-12 col-sm-6">
                <label class="control-label" for="card_holder_name"><?php _e('Card holder name:', 'com_shop'); ?></label>
            </div>
        </div>
        <div class="row clearfix">
            <div class="col-xs-12 col-sm-6">

                <input class="form-control" type='text' name='card_holder_name' id="card_holder_name" value='<?php echo strings::htmlentities($this->input->get('card_holder_name', "", "STRING")); ?>' />
            </div>
        </div>

        <div class="row clearfix">
            <div class="col-xs-12 col-sm-6">
                <label class="control-label" for="card_number"><?php _e('Card number', 'com_shop'); ?></label>
            </div>
        </div>
        <div class="row clearfix">
            <div class="col-xs-12 col-sm-6">
                <input class="form-control" type='text' name='card_number' id="card_number" value='<?php echo strings::htmlentities($this->input->get('card_number', "", "STRING")); ?>' />
            </div>
        </div>
        <div class="row clearfix">
            <div class="col-xs-12 col-sm-6">
                <label class="control-label" for="card_expire_month"><?php _e('Expiry date:', 'com_shop'); ?></label>
            </div> 
        </div>
        <div class="row clearfix">
            <div class="col-xs-12 col-sm-6">
                <div class="row clearfix">
                    <div class="col-xs-6">
                        <select  name="card_expire_month" id="card_expire_month" size="1" class="form-control">
                            <?php
                            $months = range(1, 12);
                            foreach ($months as $month) {
                                ?>
                                <option value='<?php echo $month; ?>' <?php echo $this->input->get('card_expire_month', date('n'), "STRING") == $month ? "selected='selected'" : ""; ?> ><?php echo $month; ?></option>
                            <?php }
                            ?>
                        </select>
                    </div>
                    <div class="col-xs-6">
                        <select class="form-control" name="card_expire_year" size="1">

                            <?php
                            $dates = range(date('Y'), date('Y') + 10);
                            foreach ($dates as $date) {
                                ?>
                                <option value='<?php echo $date; ?>' <?php echo $this->input->get('card_expire_year', "", "STRING") == $date ? "selected='selected'" : ""; ?>><?php echo $date; ?> </option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <?php if ($this->require_cvv) { ?>
            <div class="row clearfix">
                <div class="col-xs-12 col-sm-6">
                    <div class="col-xs-12 col-sm-6 col-md-6">
                        <label class="control-label" for="card_code"><?php _e('Card Security Number:', 'com_shop'); ?></label>
                    </div>
                </div>
                <div class="row clearfix">
                    <div class="col-xs-12 col-sm-6">
                        <input class="form-control" type="password" autocomplete="off" value="" id="card_code" name="card_code" >
                    </div>
                </div>
            <?php } ?>

            <?php if ($this->require_ctype) { ?>
                <div class="row clearfix">
                    <div class="col-xs-12 col-sm-6">
                        <label class="control-label" for="card_type"><?php _e('Card type:', 'com_shop'); ?></label>
                    </div>
                </div>
                <div class="row clearfix">
                    <div class="col-xs-12 col-sm-6">
                        <select class="form-control" name='card_type' id="card_type">
                            <?php foreach ((array) $this->cards as $card) { ?>

                                <option <?php echo $this->input->get('card_type', "", "STRING") == $card->get_symbol() ? "selected='selected'" : ""; ?>  value="<?php echo $card->get_symbol(); ?>">
                                    <?php echo $card->get_name(); ?>
                                </option>

                            <?php } ?>
                        </select>
                    </div>
                </div>
            <?php } ?>
        </div>

        <?php do_action("com_shop_after_ccard_form", $this); ?>

        <div class="row clearfix">
            <div class="col-xs-12 col-sm-6 text-right move-down-by-20px">
                <label for="pay_button" class="btn btn-block btn-large btn-primary btn-success">
                    <i class="icon-cart"><?php _e("pay", "com_shop"); ?></i>
                </label>
                <input type='submit' style="display:none!important;" id="pay_button" name='pay_button' value='<?php _e("pay", "com_shop"); ?>' />
            </div>
        </div>
    </div>
    <input type='hidden' name='ccard_form' value='1' />
</form>