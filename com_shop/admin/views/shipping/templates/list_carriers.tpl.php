<?php
defined('_VALID_EXEC') or die('access denied');
?>


<div class="com_shop" id="shipping_methods_container">

    <form name='adminForm' method='post' action='<?php echo admin_url('admin.php?page=component_com_shop-shipping'); ?>'>
        <input type='hidden' name='task' value='delete' />





        <table class="wp-list-table widefat fixed shipping-carriers">
            <thead>
                <tr>
                    <th style='padding:0; width:24px;'>
                        <input type="checkbox" id="toggle" value=""  />
                    </th>
                    <th>
                        <?php _e('Name', 'com_shop'); ?>		    </th>
                    <th>
                        <?php _e('Rates', 'com_shop'); ?>		    </th>

                    <th>
                        <?php _e('Ordering', 'com_shop'); ?>		    </th>
                </tr>
            </thead>


            <?php
            $c = 0;
            while ($o = $this->rows->nextObject()):
                $c++;
                ?>


                <tr class="row<?php echo (int) (bool) $c % 2; ?>">
                    <td width="10">
                        <input type="checkbox"  name="ids[]" value="<?php echo $o->method_id; ?>"  />
                    </td>

                    <td width="75%">
                        <button onclick="window.location.href = '<?php echo admin_url('admin.php?page=component_com_shop-shipping&task=add_carrier&method_id=' . $o->method_id); ?>';
                                return false;" class="btn btn-small">
                            <span class="icon-edit">
                            </span>
                            <?php echo strings::htmlentities($o->name); ?>
                        </button>


                    </td>
                    <td>
                        <button onclick="window.location.href = '<?php echo admin_url('admin.php?page=component_com_shop-shipping&task=list_rates&carrier=' . $o->method_id); ?>';
                                return false;" class="btn btn-small">
                            <span class="icon-list">
                            </span>
                            <?php _e('List Rates', 'com_shop'); ?>
                        </button>


                    </td>

                    <td align="left">
                        <?php echo $o->method_order; ?>

                    </td>

                </tr>

            <?php endwhile; ?>

        </table>
    </form>

    <?php echo $this->pagination->getPagesLinks(); ?>
</div>