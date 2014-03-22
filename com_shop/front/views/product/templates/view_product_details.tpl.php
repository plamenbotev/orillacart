<div class="product-view container-fluid">
    <form role="form" onsubmit="" action='<?php echo Route::get("component=shop&con=cart"); ?>' method="post" id="product_addtocart_form">

        <div clas="row">
            <div class="col-xs-12 col-md-8">
                <div id="product_gallery">
                    <?php $this->loadTemplate('gallery'); ?>
                </div>
                <?php do_action("add_to_product_left_column", $GLOBALS['post']); ?>
            </div>
            <div class="col-xs-12 col-md-4">

                <div class="row">
                    <div class="col-xs-6 col-md-12" id="product_data">     
                        <?php $this->loadTemplate('product_data'); ?>
                    </div>
                    <div class="col-xs-6 col-md-12">
                        <div id="product_attributes">
                            <?php $this->loadTemplate('attributes'); ?>
                        </div>
                        <div id="product_files">
                            <?php $this->loadTemplate('files'); ?>
                        </div>
                    </div>
                    <?php do_action("add_to_product_right_column", $GLOBALS['post']); ?>
                </div>
            </div>

        </div>
    </form>

    <div class="clr"></div>

    <!-- Nav tabs -->
    <div clas="row">
        <div class="tabbable col-xs-12">
            <ul class="nav nav-tabs clearfix">
                <li class="active"><a href="#product_description" data-toggle="tab"><?php _e('Description', 'com_shop'); ?></a></li>
                <?php if (comments_open()): ?>
                    <li><a href="#product_comments" data-toggle="tab"><?php _e('Comments', 'com_shop'); ?></a></li>
                <?php endif; ?>
                <?php do_action("add_product_tabs_title", $GLOBALS['post']); ?>
            </ul>
            <!-- Tab panes -->
            <div class="tab-content">
                <div class="tab-pane active" id="product_description">

                    <?php the_content(); ?>
                    <div class="clr"></div>
                    <div class="productTaxonomies">
                        <div class="taxonomyProductTags"><?php echo(get_the_term_list(get_the_ID(), 'product_tags', __('Tagged Under:', 'com_shop'), ',', '')); ?></div>
                        <div class="taxonomyProductBrands"><?php echo(get_the_term_list(get_the_ID(), 'product_brand', __('Brands:', 'com_shop'), ',', '')); ?></div>
                    </div>
                </div>
                <?php if (comments_open()): ?>
                    <div class="tab-pane" id="product_comments"><?php comments_template(); ?> </div>
                <?php endif; ?>

            </div>
            <?php do_action("add_product_tabs_content", $GLOBALS['post']); ?>
        </div>
    </div>




    <div class="clr"></div>
</div>