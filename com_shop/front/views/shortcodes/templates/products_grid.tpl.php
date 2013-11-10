<?php if ($this->products->have_posts()) : ?>
    <div id='com-shop'> <ul class="productsGrid">
            <?php while ($this->products->have_posts()) : $this->products->the_post();
                $product = $this->getModel('product')->load_product(get_the_ID());
                ?>
                <li class="gridItem addBorder <?php
                    echo get_post_meta(get_the_ID(), '_onsale', true) == 'yes' ? " onsale" : "";
                    echo get_post_meta(get_the_ID(), '_special', true) == 'yes' ? " special" : "";
                    ?>" >
                    <div class='itemData'> 
                        <a href="<?php the_permalink(); ?>" class="thumb">
                            <img  src="<?php echo $product->thumb; ?>" alt="<?php echo $product->image_title; ?>" title="<?php echo $product->image_title; ?>">                                
                        </a>
                        <h4><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h4>
                    </div>
                    <div>
                        <span class="price"><?php echo $product->price->price_formated; ?></span>
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
                </li>

    <?php endwhile; // end of the loop.   ?>

        </ul></div>

    <?php

endif;