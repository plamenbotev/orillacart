<table class="wp-list-table widefat fixed">
    <tr>
        <th><?php _e("Title", "com_shop"); ?></th>
        <th><?php _e("Remove", "com_shop"); ?></th>
    </tr>

    <?php foreach ((array) $this->variations as $id => $title): ?>
        <tr>
            <td>

                <?php echo edit_post_link($title, '', '', $id); ?></td>
            <td>
                <button onclick="jsShopAdminHelper.attribute.delete_variation(<?php echo $id; ?>);
                        return false;" class="btn btn-danger btn-small">
                    <span class="icon-trash"></span>
                </button>
            </td>


        </tr>


    <?php endforeach; ?>


</table>