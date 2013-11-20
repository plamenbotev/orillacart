<div class="product-view">
    <form onsubmit="" action='<?php echo Route::get("component=shop&con=cart"); ?>' method="post" id="product_addtocart_form">
        <div class="product-shop">
            <div id="product_data">     
                <?php $this->loadTemplate('product_data'); ?>
            </div>
            <?php if (count($this->childs)) { ?>

                <div class='product-attributes'>
                    <h2>Select Variation</h2>
                    <select class="product-childs" name="child_product" id="child_product">
                        <option value="<?php echo get_the_ID(); ?>"></option>
                        <?php foreach ((array) $this->childs as $k => $v) { ?>
                            <option value="<?php echo $k; ?>"><?php echo strings::htmlentities($v); ?></option>
                        <?php } ?>
                    </select>
                </div>
            <?php } ?>

            <div id="product_attributes">
                <?php $this->loadTemplate('attributes'); ?>
            </div>
            <div id="product_files">
                <?php $this->loadTemplate('files'); ?>
            </div>

            <?php do_action("add_to_product_right_column", $GLOBALS['post']); ?>
        </div>

        <div class="product-img-box">
            <div id="product_gallery">
                <?php $this->loadTemplate('gallery'); ?>
            </div>
            <?php do_action("add_to_product_left_column", $GLOBALS['post']); ?>
        </div>
    </form>

    <div class="clr"></div>
    <div class="tabsContainer">

        <dl class="tabs">
            <dt><span><?php _e('Description', 'com_shop'); ?></span></dt>
            <dd>
                <?php the_content(); ?>
                <div class="clearfix"></div>
                <div class="productTaxonomies">
                    <div class="taxonomyProductTags"><?php echo(get_the_term_list(get_the_ID(), 'product_tags', __('Tagged Under:','com_shop'), ',', '')); ?></div>
                    <div class="taxonomyProductBrands"><?php echo(get_the_term_list(get_the_ID(), 'product_brand', __('Brands:','com_shop'), ',', '')); ?></div>
                </div>
            </dd>
			<?php if(comments_open()){ ?>
            <dt><span><?php _e('Comments', 'com_shop'); ?></span></dt>
            <dd>
                <?php comments_template(); ?> 
            </dd>
			<?php } ?>
            <?php do_action("add_product_tabs", $GLOBALS['post']); ?>
        </dl>
    </div>
    <div class="clr"></div>
</div>