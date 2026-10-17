<?php defined('_VALID_EXEC') or die('access denied'); ?>
<input type="hidden" name="shop_category_meta" value="1" />
<div class="form-field">
    <label for="shop_cat_position"><?php _e('Order', 'com_shop'); ?></label>
    <input type="number" id="shop_cat_position" name="position" value="<?php echo (int) $this->row->position; ?>" />
    <p><?php _e('Subcategories are shown in ascending order, then by name.', 'com_shop'); ?></p>
</div>
<div class="form-field">
    <label for="shop_cat_products_per_row"><?php _e('Products per row', 'com_shop'); ?></label>
    <input type="number" min="0" id="shop_cat_products_per_row" name="products_per_row" value="<?php echo esc_attr($this->row->products_per_row); ?>" />
</div>
<div class="form-field">
    <label for="shop_cat_list_template"><?php _e('List template', 'com_shop'); ?></label>
    <select id="shop_cat_list_template" name="list_template">
        <option value=""><?php _e('default', 'com_shop'); ?></option>
        <?php foreach ((array) $this->list_templates as $tpl) { ?>
            <option value="<?php echo esc_attr($tpl); ?>" <?php selected($this->row->list_template, $tpl); ?>><?php echo esc_html($tpl); ?></option>
        <?php } ?>
    </select>
</div>
<div class="form-field">
    <label for="shop_cat_view_style"><?php _e('View style', 'com_shop'); ?></label>
    <select id="shop_cat_view_style" name="view_style">
        <option value=""><?php _e('default', 'com_shop'); ?></option>
        <option value="grid" <?php selected($this->row->view_style, 'grid'); ?>><?php _e('grid', 'com_shop'); ?></option>
        <option value="list" <?php selected($this->row->view_style, 'list'); ?>><?php _e('list', 'com_shop'); ?></option>
    </select>
</div>
<div class="form-field shop-cat-thumbnail">
    <label><?php _e('Thumbnail', 'com_shop'); ?></label>
    <input type="hidden" class="shop-cat-thumbnail-id" name="thumbnail_id" value="<?php echo (int) $this->row->image_id; ?>" />
    <img class="shop-cat-thumbnail-img" src="<?php echo esc_url($this->row->image_src); ?>" width="60" height="60" <?php echo $this->row->image_src ? '' : 'style="display:none;"'; ?> />
    <p>
        <button type="button" class="button shop-cat-thumbnail-set"><?php _e('Upload/Add image', 'com_shop'); ?></button>
        <button type="button" class="button shop-cat-thumbnail-remove"><?php _e('Remove thumbnail', 'com_shop'); ?></button>
    </p>
</div>
