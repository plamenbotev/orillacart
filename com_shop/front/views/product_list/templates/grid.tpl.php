<?php if (!empty($this->sub_cats)) { ?>
    <div class="productsGrid categoryList" >
        <?php foreach ((array) $this->sub_cats as $o) { ?>
            <a href="<?php echo get_term_link((int) $o->term_id, 'product_cat'); ?>" class="gridItem addBorder">
                <h6><?php echo strings::stripAndEncode($o->name); ?></h6>
                <div class="catThumb">
                    <?php if (!empty($o->image_src)): ?>
                        <img src="<?php echo $o->image_src; ?>" />
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
        <input type="hidden" id="list_type" name="list_type" value="<?php echo $this->list_type; ?>" />

        <div id="products-list-top-controls" class="container-fluid clearfix ">    
            <div class="row clearfix">
                <div class="col-xs-12 col-md-8"> 
                    <div id="products-list-top-view-mode" class="pull-left">
                        <div class="view-mode-label"><?php _e('View as:', 'com_shop'); ?>&nbsp;</div>
                        <a class="first" href="javascript:void(0);" onclick='jQuery("#list_type").val("list").parent().submit();' ></a>
                        <a class="active" href="javascript:void(0);" onclick='jQuery("#list_type").val("grid").parent().submit();'></a>
                    </div>
                </div>
                <div class="col-xs-12 col-md-4">
                    <div class="row">
                        <div class="col-xs-6 text-right">
                            <?php _e('Sort by:', 'com_shop'); ?>
                        </div>
                        <div class="col-xs-6">
                            <select name="product_list_order" class="form-control input-sm" onchange="jQuery('#order_list').submit();">
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
                    </div>
                </div>
            </div>
        </div>
    </form>

    <ul id="activeFilter_itemList" class="productsGrid">  
        <?php
        $c1 = 1;

        while (have_posts()) {
            the_post();
            $product = $this->getModel('product')->load_product(get_the_ID());
            ?>
            <li class="gridItem addBorder <?php
            echo get_post_meta(get_the_ID(), '_onsale', true) == 'yes' ? " onsale" : "";
            echo get_post_meta(get_the_ID(), '_special', true) == 'yes' ? " special" : "";
            ?>" >
                <div class='itemData'> 
                    <a href="<?php the_permalink(); ?>" class="thumb">
                        <?php if (!empty($product->thumb)): ?>
                            <img src="<?php echo $product->thumb; ?>" alt="<?php echo $product->image_title; ?>" title="<?php echo $product->image_title; ?>">                                
                        <?php endif; ?>
                    </a>
                    <h4><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h4>
                </div>
              
				<?php if(((bool)Factory::getParams('shop')->get('hide_the_price') == false && $product->hide_price == 'global') || $product->hide_price == 'no'): ?>
 
				<div>
                    <?php if ($product->price->raw_price < $product->price->base) { ?>
                        <div class="oldPriceContainer">
                            <span class="oldPriceTitle"><?php _e("Regular price: ", "com_shop"); ?></span>
                            <span class="old_price ">
                                <?php echo $product->price->base_formated; ?>
                            </span>
                        </div>       
                    <?php } ?>
                    <div class="priceContainer">
                        <?php if ($product->price->raw_price < $product->price->base) { ?>
                            <span class="specialPriceTitle"><?php _e("Special price: "); ?></span>
                        <?php } ?>
                        <span class="price <?php if ($product->price->raw_price < $product->price->base) echo 'product_has_discount'; ?>">
                            <?php echo $product->price->price_formated; ?>
                        </span>
                    </div>

                    <?php
                    if (get_post_meta((int) get_the_ID(), '_not_for_sale', true) == 'no' &&
                            !Factory::getApplication('shop')->getParams()->get('catalogOnly')) {
                        ?>
                        <a id="buy-product-<?php echo get_the_ID(); ?>" class="buy_button btn btn-primary btn-small " href="<?php echo Route::get('component=shop&con=cart&task=add_to_cart&id=' . get_the_ID()); ?>">
                            <span class="icon-cart"></span>
                            <?php _e('Add To Cart', 'com_shop'); ?>
                        </a>
                    <?php } ?>
                </div>
				<?php endif; ?>
            </li>
            <?php if ($c1 % $this->products_per_row == 0 || $c1 == $this->products_count) { ?>
                <?php
            }
            $c1++;
        }
        ?>
    </ul>
    <?php if (!empty($this->pagination)): ?>
        <div class="text-center move-down-by-30px">
            <ul class="pagination">
                <?php foreach ((array) $this->pagination as $k => $v) : ?>
                    <?php if (strings::stripos($v, "current") !== false && strings::stripos($v, "<a") === false): ?>
                        <li class="active"><?php echo $v; ?></li>
                        <?php else: ?>
                        <li><?php echo $v; ?></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php

 endif;