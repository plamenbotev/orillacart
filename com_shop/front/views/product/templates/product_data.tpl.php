<input type="hidden" name="task" value="add_to_cart_custom" />
<input type="hidden" name="id" value ="<?php the_ID(); ?>" />
<div class="product-name product-attributes">
    <h1><?php the_title(); ?></h1>
    <?php if (!empty($this->row->product->sku)) { ?>    
        <div class='sku'><?php _e('SKU:', 'com_shop'); ?> <?php echo strings::htmlentities($this->row->product->sku); ?></div>
    <?php } ?>    
<?php if(((bool)Factory::getParams('shop')->get('hide_the_price') == false && ($this->row->product->hide_price == 'global' || empty($this->row->product->hide_price))) || $this->row->product->hide_price == 'no'): ?>
  <?php if ($this->row->price->raw_price < $this->row->price->base) { ?>
        <div id="oldPriceContainer" class="oldPriceContainer">
            <span class="oldPriceTitle"><?php _e("Regular price: ", "com_shop"); ?></span>
            <span class="old_price ">
                <?php echo $this->row->price->base_formated; ?>
            </span>
        </div>       
    <?php } ?>
    <div class="priceContainer">
        <?php if ($this->row->price->raw_price < $this->row->price->base) : ?>
            <span class="specialPriceTitle"><?php _e("Special price: ","com_shop"); ?></span>
        <?php else: ?>
            <span class="priceTitle"><?php _e("Price: ","com_shop"); ?></span>
        <?php endif; ?>
        <span id="price_container" class="price <?php if ($this->row->price->raw_price < $this->row->price->base) echo 'product_has_discount'; ?>">
            <?php echo $this->row->price->price_formated; ?>
        </span>
    </div>
<?php endif; ?>
    <?php if((Factory::getParams('shop')->get('checkStock') && $this->row->product->manage_stock == 'global') || $this->row->product->manage_stock == 'yes'): ?>
	<div class="availability"><?php _e("Availability:", "com_shop"); ?> 
        <span><?php echo $this->availability; ?></span> 
    </div>
	<?php endif; ?>


    <?php if (get_post_meta((int) get_the_ID(), '_not_for_sale', true) == 'no' && !Factory::getApplication('shop')->getParams()->get('catalogOnly')) { ?>
        <div id="submit-form-container">
            <div class="col-xs-8">
                <button class="addToCartButton btn btn-primary">
                    <span class="icon-cart"></span>
                    <?php _e('Add To Cart', 'com_shop'); ?>
                </button>
            </div>
            <?php
            if (!( has_term('digital', 'product_type', $GLOBALS['post']) && !$this->row->product->download_limit_multiply_qty )):
                ?>     
                <div class="col-xs-4 ">
                    <input class="form-control input-sm" type='text' name="qty" size='1' value='1' />
                </div>

            <?php endif; ?>
        </div>
    <?php } ?>
</div>