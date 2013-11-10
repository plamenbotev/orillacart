<?php if (!empty($this->sub_cats)) { ?>

    <div class="productsGrid categoryList" >
        <?php foreach ((array) $this->sub_cats as $o) { ?>
            <a href="<?php echo get_term_link((int) $o->term_id, 'product_cat'); ?>" class="gridItem addBorder">
                <h6><?php echo strings::stripAndEncode($o->name); ?></h6>
                <div class="catThumb">
                    <?php if (!empty($o->image_src)): ?>
                        <img  border="0" alt="" src="<?php echo $o->image_src; ?>" />
                    <?php endif; ?>
                </div>
            </a>
        <?php } ?>
    </div>
<?php } ?>

<?php if (have_posts()): ?>

    <?php
    $uri = uri::getInstance();
    ?>
    <form  id='order_list' name="order_list" method="post" action="<?php echo $uri->toString(array('scheme', 'host', 'port', 'path', 'query')); ?>">
        <input type="hidden" id="list_type" name="list_type" value="<?php echo $this->listtype; ?>" />

        <div id="products-list-top-controls" class="clearfix">

            <div id="products-list-top-view-mode">
                <div class="view-mode-label"><?php _e('View as:', 'com_shop'); ?>&nbsp;</div>
                <a class="first" href="javascript:void(0);" onclick='jQuery("#list_type").val("list").parent().submit();' ></a>
                <a class="active" href="javascript:void(0);" onclick='jQuery("#list_type").val("grid").parent().submit();'></a>
            </div>

            <div class="select">
                <select name="product_list_order" onchange="jQuery('#order_list').submit();">
                    <option value="id" <?php echo ($this->ordering == 'id') ? "selected='selected'" : ""; ?> >
                        <?php _e('Most Recent', 'com_shop'); ?>
                    </option>
                    <option value="name" <?php echo ($this->ordering == 'name') ? "selected='selected'" : ""; ?> >
                        <?php _e('Name', 'com_shop'); ?>
                    </option>
                    <option value="price_lowest" <?php echo ($this->ordering == 'price_lowest') ? "selected='selected'" : ""; ?> >
                        <?php _e('Price lowest first', 'com_shop'); ?>
                    </option>
                    <option value="price_highest" <?php echo ($this->ordering == 'price_highest') ? "selected='selected'" : ""; ?> >
                        <?php _e('Price highest first', 'com_shop'); ?>
                    </option>
                    <option value="ordering" <?php echo ($this->ordering == 'ordering') ? "selected='selected'" : ""; ?> >
                        <?php _e('Featured', 'com_shop'); ?>
                    </option>
                </select>
            </div>
            <div class="sort-label"><?php _e('Sort by', 'com_shop'); ?></div>
        </div>
    </form>

    <div id="activeFilter_itemList" class="productsList">  
        <?php
        while (have_posts()) {
            the_post();
            $product = $this->getModel('product')->load_product(get_the_ID());
            ?>
            <div class="listItem clearfix">
                <div class="column<?php echo get_post_meta(get_the_ID(), '_onsale', true) == 'yes' ? " onsale" : ""; ?>
                     <?php echo get_post_meta(get_the_ID(), '_special', true) == 'yes' ? " special" : ""; ?>" >
                    <a href="<?php the_permalink(); ?>" class="thumb">
                        <img  src="<?php echo $product->thumb; ?>" alt="<?php echo $product->image_title; ?>" title="<?php echo $product->image_title; ?>">                                
                    </a>
                </div>

                <div class="column width70 moveRightBy10px">
                    <h4><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h4>
                    <span class="price"><?php echo $product->price->price_formated; ?></span>
                    <?php
                    if (get_post_meta((int) get_the_ID(), '_not_for_sale', true) == 'no' &&
                            !Factory::getApplication('shop')->getParams()->get('catalogOnly')) {
                        ?>
                        <a class="buy_button moveRightBy10px btn btn-primary btn-small" id="buy-product-<?php echo get_the_ID(); ?>" href="<?php echo Route::get('component=shop&con=cart&task=add_to_cart&id=' . get_the_ID()); ?>">
                            <span class="icon-cart"></span>
                            <?php _e('Add To Cart', 'com_shop'); ?></a>
                    <?php } ?>

                    <p>
                        <?php the_excerpt(); ?>
                    </p>    
                    <p>
                        <?php echo(get_the_term_list(get_the_ID(), 'product_tags', __('Tagged Under:', 'com_shop'), ',', '')); ?>
                    </p>
                    <p>
                        <?php echo(get_the_term_list(get_the_ID(), 'product_cat', __('Categories:', 'com_shop'), ',', '')); ?>
                    </p>
                    <p>
                        <?php echo(get_the_term_list(get_the_ID(), 'product_brand', __('Brands:', 'com_shop'), ',', '')); ?>
                    </p>
                </div>
            </div>
        <?php } ?>
    </div>

    <div id="products-list-btm-controls" class="clearfix">
        <?php echo $this->pagination; ?>
    </div>
<?php endif; ?>