<ul class="order_actions">
    <input type='hidden' id='send_invoice' name='send_invoice' value='0' />
    <li>
        <button class="btn btn-success btn-small">
            <i class='icon-save'></i>
            <?php _e('Save Order', 'com_shop'); ?>
        </button>
    </li>
    <li>
        <button class="btn btn-small" name='invoice' onclick="jQuery('#send_invoice').val(1);
                jQuery(this).closest('form').submit();">
            <i class='icon-mail'></i>
            <?php _e('Email invoice', 'com_shop'); ?>
        </button>
    </li>

    <?php do_action('orillacart_order_actions', $this->post->ID); ?>

    <li class="wide">
        <?php
        if (current_user_can("delete_post", $this->post->ID)) {
            if (!EMPTY_TRASH_DAYS)
                $delete_text = __('Delete Permanently', 'com_shop');
            else
                $delete_text = __('Move to Trash', 'com_shop');
            ?>
            <button class="btn btn-danger btn-small" onclick="window.location = '<?php echo esc_url(get_delete_post_link($this->post->ID)); ?>';
                    return false;">
                <i class='icon-trash'></i>
                <?php echo $delete_text; ?>
            </button>
        <?php } ?>
    </li>
    <li class="wide">
        <?php _e("Add product:"); ?>
    </li>
    <li class="wide">
        <input type="text" id="select_parent" value="" />
    </li>
</ul>