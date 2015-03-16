<?php
defined('_VALID_EXEC') or die('access denied');
?>

<div class="com_shop" id="shipping_methods_container">

    <form name='adminForm' method='post' action='<?php echo admin_url('admin.php?page=component_com_shop-payment'); ?>'>
        <input type='hidden' name='task' value='delete' />

        <table class="wp-list-table widefat fixed payment-methods">
            <thead>
                <tr>
                    <th style="width:24px; padding:0;" width="10">
                        <input type="checkbox" id="toggle" value=""  />
                    </th>
                    <th>
                        <?php _e('Name', 'com_shop'); ?>		  
                    </th>
                    <th>
                        <?php _e('Class', 'com_shop'); ?>		  
                    </th>

                    <th>
                        <?php _e('Ordering', 'com_shop'); ?>	
                    </th>
                </tr>
            </thead>


            <?php
            $c = 0;
            while ($o = $this->rows->nextObject()):
                $c++;
                ?>

                <tr class="row<?php echo (int) (bool) $c % 2; ?>">
                    <td>
                        <input type="checkbox"  name="ids[]" value="<?php echo $o->method_id; ?>"  />
                    </td>

                    <td>
                        <button onclick="window.location.href = '<?php echo admin_url('admin.php?page=component_com_shop-payment&task=add_payment&method_id=' . $o->method_id); ?>';
                                return false;" class="btn btn-small">
                            <span class="icon-edit">
                            </span>
                            <?php echo strings::htmlentities($o->name); ?>
                        </button>
                    </td>
                    <td>
                        <?php echo strings::htmlentities($o->class); ?>
                    </td>

                    <td align="left">
                        <?php echo $o->method_order; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
        </table>
    </form>
</div>