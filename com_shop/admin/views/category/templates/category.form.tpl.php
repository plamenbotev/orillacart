<?php defined('_VALID_EXEC') or die('access denied'); ?>
<div class="com_shop">
    <div style='float:left; width:25%'>
        <?php
        $this->loadTemplate('category.tree');
        ?>
    </div>
    <div  style='float:left; margin-left:5%;  margin-top:8px; width:70%;'>
        <form name='adminForm' method='post' enctype="multipart/form-data" action='<?php echo admin_url('admin.php?page=component_com_shop-category'); ?>' >
            <dl class='tabs'>
                <dt><?php _e('Category information', 'com_shop'); ?></dt>
                <dd>
                    <?php $this->loadTemplate('category.editor'); ?>
                </dd>
            </dl>
            <input type='hidden' name='con' value='category' />
            <input type='hidden' name='task' value='save' />
        </form>

    </div>
</div>