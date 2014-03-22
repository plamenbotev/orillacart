<div id="checkout">
    <?php if (!is_user_logged_in() && Factory::getApplication('shop')->getParams()->get('userReg') != 2) : ?>
        <div class="info login">
            <span class="icon-user"></span>
            <div>
                <?php _e('Already registered?', 'com_shop'); ?> <a href="" class="showlogin"><?php _e('Click here to login', 'com_shop'); ?></a>
            </div>
        </div>

        <form role="form" method="post" action="<?php echo get_option('home'); ?>/wp-login.php" class="login clearfix">
            <?php do_action("com_shop_before_checkout_login", $this); ?>
            <p><?php _e('If you have shopped with us before, please enter your username and password in the boxes below. If you are a new customer please proceed to the Billing &amp; Shipping section.', 'com_shop'); ?></p>
            <div class="row clearfix">
                <div class="col-xs-12 col-sm-5">
                    <label for="username"><?php _e('Username', 'com_shop'); ?> <span class="srequired">*</span></label>
                    <input type="text" class="form-control required" name="log" id="username" />
                </div>
                <div class="col-xs-12 col-sm-5 col-sm-push-1">
                    <label for="password"><?php _e('Password', 'com_shop'); ?> <span class="srequired">*</span></label>
                    <input class="form-control required" type="password" name="pwd" id="password" />
                </div>
            </div>
            <div class="row clearfix move-down-by-10px">
                <div class="col-xs-12 col-sm-5">
                    <input type="hidden" name="redirect_to" value="<?php echo Route::get("component=shop&con=cart&task=checkout"); ?>" />

                    <button class="btn btn-primary btn">
                        <?php _e('Login', 'com_shop'); ?>
                    </button>

                    <a class="btn btn-link" href="<?php echo get_option('home'); ?>/wp-login.php?action=lostpassword"><?php _e('Lost Password?', 'com_shop'); ?></a>
                </div>
            </div>
            <?php do_action("com_shop_after_checkout_login", $this); ?>
        </form>
    <?php endif; ?>


    <form name="checkout"  method="post" class="checkout" action="<?php echo Route::get("component=shop&con=cart&task=checkout"); ?>">
        <?php if (!is_user_logged_in() && Factory::getApplication('shop')->getParams()->get('userReg') != 2) : ?>
            <?php do_action("com_shop_before_checkout_register", $this); ?>
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
                <div class="row clearfix">
                    <div class="col-xs-12 col-sm-5">
                        <label for="account_username" class=""><?php _e('Account username', 'com_shop'); ?></label>
                        <input type="text" class="form-control required" name="account[username]" id="account_username" placeholder="Username" value="<?php strings::htmlentities($this->user_name); ?>" />
                    </div>
                </div>
                <div class="row move-down-by-10px clearfix">
                    <div class="col-xs-12 col-sm-5">
                        <label for="account_password" class=""><?php _e('Account password', 'com_shop'); ?></label>
                        <input type="password" class="form-control required" name="account[password]" id="account_password" placeholder="Password" value="" />
                    </div>
                    <div class="col-xs-12 col-sm-5 col-sd-push-1">
                        <label for="account_password-2" class="invisible"><?php _e('Account password', 'com_shop'); ?></label>
                        <input type="password" class="form-control" name="account[password-2]" id="account_password-2" placeholder="Password" value="" />
                    </div>   
                </div>
                <div class="clearfix"></div>
            </div>
            <?php do_action("com_shop_after_checkout_login", $this); ?>
        <?php endif; ?>

        <?php do_action("com_shop_before_checkout_fields", $this); ?>

        <?php if (Factory::getApplication("shop")->getParams()->get('shipping')) { ?>
            <span id="shiptobilling">
                <input id="ship_to_billing" <?php echo $this->ship_to_billing ? "checked='checked'" : ''; ?> type="checkbox" name="ship_to_billing" value="1" />
                <label for="ship_to_billing"><?php _e('Ship to same address?', 'com_shop'); ?></label>
            </span>
        <?php } ?>

        <div class="container-fluid" id="customer_details">
            <div class="row">
                <div id="billing_fields" class="col-xs-12 col-md-<?php echo Factory::getApplication("shop")->getParams()->get('shipping') ? "6" : "12"; ?>">
                    <h3><?php _e('Billing Address', 'com_shop'); ?></h3>
                    <div class="clearfix"></div>
                    <?php do_action("com_shop_before_billing_fields", $this); ?>
                    <div class="row">
                        <?php
                        $c = 0;
                        while ($field = $this->billing->get_field()) {
                            $c++;
                            ?>
                            <div class=" <?php
                            if ($field->get_name() != 'billing_address') {
                                echo "col-xs-6";
                            } else {
                                $c++;
                                echo "col-xs-12 addressRow";
                            }
                            ?>">
                                <div class="form-group">
                                    <label class="control-label" for="<?php echo $field->get_name(); ?>">
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
                                </div>
                            </div>
                        <?php } ?>

                        <?php do_action("com_shop_after_billing_fields", $this); ?>
                    </div>  
                </div>

                <?php if (Factory::getApplication("shop")->getParams()->get('shipping')) { ?>

                    <div class="col-xs-12 col-md-6" id='col-2'>
                        <h3><?php _e('Shipping Address', 'com_shop'); ?></h3>
                        <div class="clearfix"></div>
                        <?php do_action("com_shop_before_shipping_fields", $this); ?>
                        <div class="row">
                            <?php
                            $c = 0;

                            while ($field = $this->shipping->get_field()) {
                                $c++;
                                ?>

                                <div class=" <?php
                                if ($field->get_name() != 'shipping_address') {
                                    echo "col-xs-6";
                                } else {
                                    $c++;
                                    echo "col-xs-12 addressRow";
                                }
                                ?>">
                                    <div class="form-group">
                                        <label class="control-label" for="<?php echo $field->get_name(); ?>">
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
                                    </div>
                                </div>

                            <?php } ?>
                            <?php do_action("com_shop_after_shipping_fields", $this); ?>
                        </div>
                    </div>
                <?php } ?>

            </div>
            <div class="row">
                <div class="col-xs-12">
                    <label for="order_comments" class=""><?php _e('Order Notes', 'com_shop'); ?></label>
                    <textarea name="order_comments" class="form-control" id="order_comments" placeholder="<?php _e('Notes about your order, e.g. special notes for delivery.', 'com_shop'); ?>" cols="5" rows="2"><?php echo strings::htmlentities($this->order_comments); ?></textarea>
                </div>
            </div>

            <h3 id="order_review_heading"><?php _e('Your order', 'com_shop'); ?></h3>
            <?php $this->loadTemplate('order'); ?>
            <?php do_action("com_shop_after_checkout_fields", $this); ?>
    </form>
</div>
</div>