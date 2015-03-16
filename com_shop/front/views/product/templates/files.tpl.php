<?php if (count($this->files) && $this->row->product->download_choose_file) { ?>

    <div class="product-attributes product-files">

        <h2><?php _e('Choose files', 'com_shop'); ?></h2>

        <?php
        $errors = (array) Factory::getComponent('shop')->getCustomError('product_digital_files');
        ?>
        <div class="std <?php echo count($errors) ? '' : 'highlight'; ?>">

            <?php if (count($errors)) { ?>

                <div>
                    <?php foreach ((array) $errors as $e) { ?>
                        <div><?php echo strings::htmlentities($e); ?></div>
                    <?php } ?>


                </div>


            <?php } ?>
        </div>

        <?php foreach ((array) $this->files as $k => $v) { ?>
            <div class="clearfix">
                <input class="required" type="checkbox" onclick="shop_helper.recalc_price(<?php the_ID(); ?>, this.value, '');" name="files[]" id="file<?php echo $k; ?>" value="<?php echo $k; ?>" /> 
                <label for="file<?php echo $k; ?>"><?php echo strings::htmlentities($v); ?></label>
            </div>

        <?php } ?>
    </div>

<?php } ?>