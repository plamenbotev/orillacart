<?php defined('_VALID_EXEC') or die('access denied'); ?>
<input type='hidden' name='category_id' id='category_id' value='' />
<fieldset class="panelform">
    <ul class="adminformlist">
        <li>
            <label>
                <?php _e("Category ID"); ?>
            </label>
            <span id='category_show_id'></span>
        </li>
        <li>
            <label>
                <?php _e('Category name:', 'com_shop'); ?>
            </label>
            <input type="text" id="category_name" name="category_name" value="" />
        </li>
        <li>
            <label>
                <?php _e('Category description:', 'com_shop'); ?>
            </label>
            <textarea name='category_description' id='category_description'></textarea>
        </li>
        <li>
            <label>
                <?php _e('Products per row:', 'com_shop'); ?>
            </label>
            <input type="text" class="inputbox" size="3" name="products_per_row" id="products_per_row" value="" />
        </li>

        <li>
            <label>
                <?php _e('List template:', 'com_shop'); ?>
            </label>
            <select selected="selected" name="list_template" id="list_template">
                <option value=""><?php _e('default', 'com_shop'); ?></option>
                <?php foreach ((array) $this->list_templates as $tpl) { ?>
                    <option value="<?php echo strings::htmlentities($tpl); ?>">
                        <?php echo strings::htmlentities($tpl); ?>
                    </option>
                <?php } ?>
            </select>
        </li>

        <li>
            <label>
                <?php _e('View style:', 'com_shop'); ?>
            </label>
            <select name="view_style" id="view_style">
                <option selected="selected" value=""><?php _e('default', 'com_shop'); ?></option>
                <option value="grid"><?php _e('grid', 'com_shop'); ?></option>
                <option value="list"><?php _e('list', 'com_shop'); ?></option>
            </select>
        </li>

        <li>
            <label>
                <?php _e('Thumbnail', 'com_shop'); ?>
            </label>
            <input type="hidden" id="product_cat_thumbnail_id" name="thumbnail_id" />
            <img id="product_cat_thumbnail" src="" width="60px" height="60px" />
            <button class="btn btn-mini" id='set_term_image'>
                <i class="icon-upload "> </i>
                <?php _e('Upload/Add image', 'com_shop'); ?>
            </button>
            <button class="btn btn-mini" id='remove_term_image'>
                <i class="icon-remove "></i>
                <?php _e('Remove thumbnail', 'com_shop'); ?>
            </button>

        </li>
    </ul>
</fieldset>

<script type="text/javascript">

    window.send_to_editor_default = window.send_to_editor;

    window.send_to_termmeta = function (html) {

        jQuery('body').append('<div id="temp_image">' + html + '</div>');

        var img = jQuery('#temp_image').find('img');

        imgurl = img.attr('src');
        imgclass = img.attr('class');
        imgid = parseInt(imgclass.replace(/\D/g, ''), 10);

        jQuery('#product_cat_thumbnail_id').val(imgid);
        jQuery('img#product_cat_thumbnail').attr('src', imgurl);
        jQuery('#temp_image').remove();

        tb_remove();

        window.send_to_editor = window.send_to_editor_default;
    }

    jQuery('#set_term_image').live('click', function () {
        var post_id = 0;

        window.send_to_editor = window.send_to_termmeta;


        tb_show('', 'media-upload.php?post_id=' + post_id + '&amp;type=image&amp;TB_iframe=true');
        return false;
    });
    
    jQuery('#remove_term_image').live('click', function () {
        jQuery('img#product_cat_thumbnail').attr('src', '');
                jQuery('#product_cat_thumbnail_id').val('');
                return false;
    });
            
</script>