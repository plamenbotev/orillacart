<?php defined('_VALID_EXEC') or die('access denied'); ?>
<div class="com_shop" id="shop-settings-container">
    <form name='adminForm' method='post' action='<?php echo admin_url('admin.php?page=component_com_shop-admin-configuration'); ?>' >
        <dl class='tabs'>
            <dt><?php _e('Global', 'com_shop'); ?></dt>
            <dd><?php $this->loadTemplate('global.settings'); ?></dd>
            <dt><?php _e('Appearance', 'com_shop'); ?></dt>
            <dd><?php $this->loadTemplate('site.settings'); ?></dd>
        </dl>
        <input type='hidden' name='task' value='saveOpts' />
    </form>
</div>