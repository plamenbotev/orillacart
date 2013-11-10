<input type="hidden" name="task" value="add_to_cart_custom" />
<input type="hidden" name="id" value ="<?php the_ID(); ?>" />
<div class="product-name product-attributes">
    <h1><?php the_title(); ?></h1>
    <?php if (!empty($this->row->product->sku)) { ?>    
        <div class='sku'><?php _e('SKU:', 'com_shop'); ?> <?php echo strings::htmlentities($this->row->product->sku); ?></div>
    <?php } ?>    
    <div class='price' ><?php _e('Price:', 'com_shop'); ?> <span id='price_container'><?php echo $this->row->price->price_formated; ?></span></div>

    <div class="availability"><?php _e("Availability:", "com_shop"); ?> 
        <span><?php echo $this->availability; ?></span> 
    </div>


    <?php if (get_post_meta((int) get_the_ID(), '_not_for_sale', true) == 'no' && !Factory::getApplication('shop')->getParams()->get('catalogOnly')) { ?>
        <div id="submit-form-container">
            <button class="addToCartButton btn btn-primary">
                <span class="icon-cart"></span>
                <?php _e('Add To Cart', 'com_shop'); ?>
            </button>

            <?php
            if (!( has_term('digital', 'product_type', $post) && !$this->row->product->download_limit_multiply_qty )) {
                ?>     
                <input class="addFocusGlow" type='text' name="qty" size='1' value='1' />
            <?php } ?>
        </div>
    <?php } ?>
</div>