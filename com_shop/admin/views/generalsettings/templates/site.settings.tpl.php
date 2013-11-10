<?php defined('_VALID_EXEC') or die('access denied'); ?>

<div class="tab-page">
    <fieldset class="panelform">
        <ul class="adminformlist">
            <li>
                <label class="hasTip" for="objects_per_page">
                    <?php _e('Number of objects per page?', 'com_shop'); ?>
                </label>
                <input type="text" value="<?php echo $this->settings->get('objects_per_page'); ?>" name="objects_per_page" id="objects_per_page">
            </li>
            <li>
                <label class="hasTip" for="display_cetegories">
                    <?php _e('Display categories in content?', 'com_shop'); ?>
                </label>
                <input type="checkbox" value="1" name="display_cetegories" <?php echo $this->settings->get('display_cetegories') ? 'checked="checked"' : ''; ?> id="display_cetegories">
            </li>
            <li>
                <label class="hasTip" for="exclude_frontpage_cat">
                    <?php _e('Exclude frontpage category from list?', 'com_shop'); ?>
                </label>
                <input type="checkbox" value="1" name="exclude_frontpage_cat" <?php echo $this->settings->get('exclude_frontpage_cat') ? 'checked="checked"' : ''; ?> id="exclude_frontpage_cat">
            </li>
            <li>
                <label class="hasTip" for="front_page_cat">
                    <?php _e('Shop Front Page :', 'com_shop'); ?>
                </label>
                <?php
                wp_dropdown_categories(array(
                    'selected' => $this->settings->get('front_page_cat'),
                    'hierarchical' => true,
                    'name' => 'front_page_cat',
                    'id' => 'front_page_cat',
                    'taxonomy' => 'product_cat',
                    'hide_empty' => 0,
                    'show_option_none' => __("All products", "com_shop")
                ));
                ?> 
            </li>

            <li>
                <label class="hasTip" for="list_variations">
                    <?php _e('List variations in categories?', 'com_shop'); ?>
                </label>
                <input type="checkbox" value="1" name="list_variations" <?php echo $this->settings->get('list_variations') ? 'checked="checked"' : ''; ?> id="list_variations">
            </li>

            <li>
                <label class="hasTip" for="productSort">
                    <?php _e('Product default sorting:', 'com_shop'); ?>
                </label>
                <select id="productSort" name="productSort">
                    <option value="id" <?php echo $this->settings->get('productSort') == 'id' ? 'selected="selected"' : ''; ?> >
                        <?php _e('id', 'com_shop'); ?>
                    </option>
                    <option value="name" <?php echo $this->settings->get('productSort') == 'name' ? 'selected="selected"' : ''; ?> >
                        <?php _e('name', 'com_shop'); ?>
                    </option>
                    <option value="price_lowest" <?php echo $this->settings->get('productSort') == 'price_lowest' ? 'selected="selected"' : ''; ?> >
                        <?php _e('price ascending[lowest first ]', 'com_shop'); ?>
                    </option>
                    <option value="price_highest" <?php echo $this->settings->get('productSort') == 'price_highest' ? 'selected="selected"' : ''; ?> >
                        <?php _e('price descending[highest first ]', 'com_shop'); ?>
                    </option>
                    <option value="ordering" <?php echo $this->settings->get('productSort') == 'ordering' ? 'selected="selected"' : ''; ?> >
                        <?php _e('product ordering', 'com_shop'); ?>
                    </option>
                </select>
            </li>
            <li>
                <label class="hasTip" for="products_per_row">
                    <?php _e('Products per row :', 'com_shop'); ?>
                </label>
                <input type='text' value='<?php echo $this->settings->get('products_per_row'); ?>' name='products_per_row' id='products_per_row' />
            </li>
            <li>
                <label class="hasTip" for="page_id">
                    <?php _e('Shop Page :', 'com_shop'); ?>
                </label>
                <?php
                wp_dropdown_pages(array(
                    'selected' => $this->settings->get('page_id')
                ));
                ?> 
            </li>
            <li>
                <label class="hasTip" for="list_type">
                    <?php _e('Default List Type :', 'com_shop'); ?>
                </label>
                <select id="list_type" name="list_type">
                    <option value="grid" <?php echo $this->settings->get('list_type') == 'grid' ? "selected='selected'" : ""; ?> >
                        <?php _e('grid', 'com_shop'); ?>
                    </option>
                    <option value="list" <?php echo $this->settings->get('list_type') == 'list' ? "selected='selected'" : ""; ?> >
                        <?php _e('list', 'com_shop'); ?>
                    </option>                              
                </select>
            </li>
            <li>
                <label class="hasTip" for="catX">
                    <?php _e('cat thumb X :', 'com_shop'); ?>
                </label>
                <input type='text' value='<?php echo $this->settings->get('catX'); ?>' name='catX' id='catX' />
            </li>
            <li>
                <label class="hasTip" for="catY">
                    <?php _e('cat thumb Y :', 'com_shop'); ?>
                </label>
                <input type='text' value='<?php echo $this->settings->get('catY'); ?>' name='catY' id='catY' />
            </li>         
            <li>
                <label class="hasTip" for="miniX">
                    <?php _e('Mini X :', 'com_shop'); ?>
                </label>
                <input type='text' value='<?php echo $this->settings->get('miniX'); ?>' name='miniX' id='miniX' />
            </li>
            <li>
                <label class="hasTip" for="miniY">
                    <?php _e('Mini Y :', 'com_shop'); ?>
                </label>
                <input type='text' value='<?php echo $this->settings->get('miniY'); ?>' name='miniY' id='miniY' />
            </li>
            <li>
                <label class="hasTip" for="mediumX">
                    <?php _e('Medium X :', 'com_shop'); ?>
                </label>
                <input type='text' value='<?php echo $this->settings->get('mediumX'); ?>' name='mediumX' id='mediumX' />
            </li>
            <li>
                <label class="hasTip" for="mediumY">
                    <?php _e('Medium Y :', 'com_shop'); ?>
                </label>
                <input type='text' value='<?php echo $this->settings->get('mediumY'); ?>' name='mediumY' id='mediumY' />
            </li>
            <li>
                <label class="hasTip" for="thumbX">
                    <?php _e('Thumbs X :', 'com_shop'); ?>
                </label>
                <input type='text' value='<?php echo $this->settings->get('thumbX'); ?>' name='thumbX' id='thumbX' />
            </li>
            <li>
                <label class="hasTip" for="thumbY">
                    <?php _e('Thumbs Y :', 'com_shop'); ?>
                </label>
                <input type='text' value='<?php echo $this->settings->get('thumbY'); ?>' name='thumbY' id='thumbY' />
            </li>
        </ul>
    </fieldset>
</div>