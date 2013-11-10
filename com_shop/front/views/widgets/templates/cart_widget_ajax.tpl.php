<?php if ($this->cart->is_empty()) { ?>
    <?php _e("Your cart is empty.", "com_shop"); ?>
<?php } else { ?>
    <div>
        <span class='cart-num-products'>
            <?php echo (int) $this->cart->get_total_products(); ?>
        </span> 
        <?php echo _n("item in cart.", "items in cart.", (int) $this->cart->get_total_products(), "com_shop"); ?>
    </div>
    <div class="btn-group">
        <a class="btn  btn-small" href='<?php echo Route::get("component=shop&con=cart"); ?>'>
            <span class="icon-cart"></span>
            <?php _e("View Cart", "com_shop"); ?>
        </a>
    </div>
    <div class="btn-group">
        <a class="btn btn-small btn-primary" href='<?php echo Route::get("component=shop&con=cart&task=checkout"); ?>'>
            <?php _e("Proceed to checkout", "com_shop"); ?>
        </a>
    </div>
<?php } ?>
