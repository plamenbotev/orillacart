<a style='height:<?php echo $this->row->gallery_height; ?>; display:block; text-align:center;' href="<?php echo $this->thumbnail_full; ?>" rel="lightbox" >
    <img  id="product_medium_image" src="<?php echo $this->thumbnail_medium; ?>" alt="" title="" />
</a>
<?php if (!empty($this->row->images)) { ?>
    <div class="more-views">
        <h2><?php _e('More Views', 'com_shop'); ?></h2>
        <ul id='gallery'>
            <?php
            foreach ((array) $this->row->images as $k => $i) {
                ?>
                <li>
                    <a href="<?php echo $i->image; ?>" id='image-<?php echo $k; ?>' onmouseover="<?php echo apply_filters("com_shop_product_gallery_onmouseover", "gallery(this, '" . $i->medium . "');", $this, $i); ?>" rel="" ><img src="<?php echo $i->mini; ?>"  alt="" /></a>
                </li>
            <?php } ?>
        </ul>
    </div>
<?php } ?>