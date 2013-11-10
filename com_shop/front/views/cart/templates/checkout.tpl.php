<div id="checkout">
    <?php if (!is_user_logged_in() && Factory::getApplication('shop')->getParams()->get('userReg') != 2) { ?>
        <div class="info login">
            <span class="icon-user"></span>

            <div>
                <?php _e('Already registered?', 'com_shop'); ?> <a href="" class="showlogin"><?php _e('Click here to login', 'com_shop'); ?></a>
            </div>
        </div>

        <form method="post" action="<?php echo get_option('home'); ?>/wp-login.php" class="login clearfix">
         <?php do_action("com_shop_before_checkout_login",$this); ?>
		 <p><?php _e('If you have shopped with us before, please enter your username and password in the boxes below. If you are a new customer please proceed to the Billing &amp; Shipping section.', 'com_shop'); ?></p>
            <p class="form-row form-row-first">
                <label for="username"><?php _e('Username', 'com_shop'); ?> <span class="srequired">*</span></label>
                <input type="text" class="input-text required" name="log" id="username" />
            </p>
            <p class="form-row form-row-last">
                <label for="password"><?php _e('Password', 'com_shop'); ?> <span class="srequired">*</span></label>
                <input class="input-text required" type="password" name="pwd" id="password" />
            </p>
            <div class="clearfix"></div>

            <p class="form-row">
                <input type="hidden" name="redirect_to" value="<?php echo Route::get("component=shop&con=cart&task=checkout"); ?>" />

                <button class="btn btn-primary btn">
                    <?php _e('Login', 'com_shop'); ?>
                </button>

                <a class="btn btn-link" href="<?php echo get_option('home'); ?>/wp-login.php?action=lostpassword"><?php _e('Lost Password?', 'com_shop'); ?></a>
            </p>
			<?php do_action("com_shop_after_checkout_login",$this); ?>
        </form>
    <?php } ?>


    <form name="checkout"  method="post" class="checkout" action="<?php echo Route::get("component=shop&con=cart&task=checkout"); ?>">
        <?php if (!is_user_logged_in() && Factory::getApplication('shop')->getParams()->get('userReg') != 2) { ?>
		<?php do_action("com_shop_before_checkout_register",$this); ?>
            <div class="info register">
                <span class="icon-locked"></span>
                <div>
                    <?php _e('Create account?', 'com_shop'); ?><input type="checkbox" id="createaccount" name="createaccount" value="1" <?php echo request::getBool('createaccount') ? "checked='checked'" : ""; ?> />
                    <label for="createaccount">&nbsp;</label>
                </div>

            </div>

            <div id="register">
                <p>
                    <?php _e('Create an account by entering the information below. If you are a returning customer please login with your username at the top of the page.', 'com_shop'); ?>
                </p>
                <p class="form-row ">
                    <label for="account_username" class=""><?php _e('Account username', 'com_shop'); ?></label>
                    <input type="text" class="input-text required" name="account[username]" id="account_username" placeholder="Username" value="<?php strings::htmlentities($this->user_name); ?>" />
                </p><p class="form-row form-row-first">
                    <label for="account_password" class=""><?php _e('Account password', 'com_shop'); ?></label>
                    <input type="password" class="input-text required" name="account[password]" id="account_password" placeholder="Password" value="" />
                </p><p class="form-row form-row-last">
                    <label for="account_password-2" class="hidden"><?php _e('Account password', 'com_shop'); ?></label>
                    <input type="password" class="input-text required" name="account[password-2]" id="account_password-2" placeholder="Password" value="" />
                </p>      
                <div class="clearfix"></div>
            </div>
			<?php do_action("com_shop_after_checkout_login",$this); ?>
        <?php } ?>

		<?php do_action("com_shop_before_checkout_fields",$this); ?>
        <span id="shiptobilling">
            <input id="ship_to_billing" <?php echo $this->ship_to_billing ? "checked='checked'" : ''; ?> type="checkbox" name="ship_to_billing" value="1" />
            <label for="ship_to_billing"><?php _e('Ship to same address?', 'com_shop'); ?></label>
        </span>

        <div class="col2-set" id="customer_details">
            <div class="col-1">

                <h3><?php _e('Billing Address', 'com_shop'); ?></h3>
                <div class="clearfix"></div>
				<?php do_action("com_shop_before_billing_fields",$this); ?>
                <?php
                $c = 0;

                while ($field = $this->billing->get_field()) {
                    $c++;
                    ?>

                    <p class="form-row <?php
                    if ($field->get_name() != 'billing_address') {
                        echo ($c % 2 != 0) ? " form-row-first" : " form-row-last";
                    } else {
                        $c++;
                        echo " addressRow";
                    }
                    ?>">
                        <label for="<?php echo $field->get_name(); ?>">
                            <?php echo $field->get_label(); ?>
                            <?php if ($field->required()) { ?>
                                <span class="srequired">*</span>
                            <?php } ?>
                        </label>

                        <?php if ($field instanceof state && $field->get_name() == 'billing_state') { ?>
                            <span id='billing_states_container'><?php echo $field->render(); ?></span>
                        <?php } else { ?>

                            <?php echo $field->render(); ?>

                        <?php } ?>



                    </p>

                    <?php if ($c % 2 == 0 || $field->get_name() == 'billing_address') { ?>

                        <div class='clearfix'></div>
                    <?php } ?>

                <?php } ?>
				<?php do_action("com_shop_after_billing_fields",$this); ?>
            </div>



            <div class="col-2" id='col-2'>
                <h3><?php _e('Shipping Address', 'com_shop'); ?></h3>
                <div class="clearfix"></div>
				<?php do_action("com_shop_before_shipping_fields",$this); ?>
                <?php
                $c = 0;

                while ($field = $this->shipping->get_field()) {
                    $c++;
                    ?>

                    <p class="form-row <?php
                    if ($field->get_name() != 'shipping_address') {
                        echo ($c % 2 != 0) ? " form-row-first" : " form-row-last";
                    } else {
                        $c++;
                        echo " addressRow";
                    }
                    ?>">
                        <label for="<?php echo $field->get_name(); ?>">
                            <?php echo $field->get_label(); ?>
                            <?php if ($field->required()) { ?>
                                <span class="srequired">*</span>
                            <?php } ?>
                        </label>

                        <?php if ($field instanceof state && $field->get_name() == 'shipping_state') { ?>
                            <span id='shipping_states_container'><?php echo $field->render(); ?></span>
                        <?php } else { ?>
                            <?php echo $field->render(); ?>
                        <?php } ?>
                    </p>

                    <?php if ($c % 2 == 0 || $field->get_name() == 'shipping_address') { ?>
                        <div class='clearfix'></div>
                    <?php } ?>
                <?php } ?>
				<?php do_action("com_shop_after_shipping_fields",$this); ?>
            </div>
        </div>

        <p class="form-row notes">
            <label for="order_comments" class=""><?php _e('Order Notes', 'com_shop'); ?></label>
            <textarea name="order_comments" class="input-text" id="order_comments" placeholder="<?php _e('Notes about your order, e.g. special notes for delivery.', 'com_shop'); ?>" cols="5" rows="2"><?php echo strings::htmlentities($this->order_comments); ?></textarea>
        </p>

        <h3 id="order_review_heading"><?php _e('Your order', 'com_shop'); ?></h3>
        <?php $this->loadTemplate('order'); ?>
		<?php do_action("com_shop_after_checkout_fields",$this); ?>
    </form>
</div>