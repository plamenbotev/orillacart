<?php if ($this->products->have_posts()) : ?>
    <div id='com-shop'> <ul class="productsGrid">
            <?php
            while ($this->products->have_posts()) : $this->products->the_post();
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
                </li>

            <?php endwhile; // end of the loop.    ?>

        </ul></div>

    <?php





endif;