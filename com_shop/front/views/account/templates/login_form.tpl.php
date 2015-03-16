<form method="post" action="<?php echo get_option('home'); ?>/wp-login.php" >
    <input type="hidden" name="redirect_to" value="<?php echo Route::get("component=shop&con=account"); ?>" />

    <div class="container-fluid">
        <div class="row">
            <div class="col-xs-12 col-md-6">
                <label class="control-label" for="username"><?php _e('Username', 'com_shop'); ?> <span class="required">*</span></label>
                <input type="text" class="form-control" name="log" id="username" />
            </div>
            <div class="col-xs-12 col-md-6">
                <label class="control-label" for="password"><?php _e('Password', 'com_shop'); ?> <span class="required">*</span></label>
                <input class="form-control" type="password" name="pwd" id="password" />
            </div>
        </div>
        <div class="clear"></div>

        <div class="row">
            <div class="col-xs-12 move-down-by-20px">
                <button class="btn btn-primary">
                    <span class="icon-key"></span>
                    <?php _e('Login', 'com_shop'); ?>
                </button>

                <a class="btn btn-link" href="<?php echo get_option('home'); ?>/wp-login.php?action=lostpassword"><?php _e('Lost Password?', 'com_shop'); ?></a>
            </div>
        </div>
    </div>
</form>