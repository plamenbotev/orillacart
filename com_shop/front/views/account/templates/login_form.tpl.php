<form method="post" action="<?php echo get_option('home'); ?>/wp-login.php" >
    <p class="form-row form-row-first">
        <label for="username"><?php _e('Username', 'com_shop'); ?> <span class="required">*</span></label>
        <input type="text" class="input-text" name="log" id="username" />
    </p>
    <p class="form-row form-row-last">
        <label for="password"><?php _e('Password', 'com_shop'); ?> <span class="required">*</span></label>
        <input class="input-text" type="password" name="pwd" id="password" />
    </p>
    <div class="clear"></div>

    <p class="form-row">
        <input type="hidden" name="redirect_to" value="<?php echo Route::get("component=shop&con=account"); ?>" />
        <button class="btn btn-primary">
            <span class="icon-key"></span>
            <?php _e('Login', 'com_shop'); ?>
        </button>

        <a class="btn btn-link" href="<?php echo get_option('home'); ?>/wp-login.php?action=lostpassword"><?php _e('Lost Password?', 'com_shop'); ?></a>
    </p>
</form>