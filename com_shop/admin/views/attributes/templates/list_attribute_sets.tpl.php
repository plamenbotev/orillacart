<?php
defined('_VALID_EXEC') or die('access denied');
?>
<div class="com_shop">
    <form name='adminForm' method='post' action='<?php echo admin_url('admin.php?page=component_com_shop-attributes'); ?> '>
        <input type='hidden' name='task' value='delete_sets' />
        <table class="wp-list-table widefat fixed posts">
            <thead>
                <tr>
                    <th style="padding:0; width:24px;">
                        <input type="checkbox" id="toggle" value=""  />
                    </th>
                    <th>
                        <?php _e('Set name', 'com_shop'); ?>		  
                    </th>
                    <th >
                        <?php _e('Published', 'com_shop'); ?>
                    </th>
                </tr>
            </thead>
            <?php
            $c = 0;
            while ($o = $this->att_sets->nextObject()):

                $mod_or_even = ($c % 2 == 0 ) ? 0 : 1;
                $c++;
                ?>


                <tr class="row<?php echo $mod_or_even; ?>">
                    <td>
                        <input type="checkbox"  name="ids[]" value="<?php echo $o->attribute_set_id; ?>"  />		
                    </td>
                    <td align="left">
                        <a href="<?php echo admin_url('admin.php?page=component_com_shop-attributes&task=edit&id=' . $o->attribute_set_id); ?>"><?php echo strings::htmlentities($o->attribute_set_name); ?></a>

                    </td>


                    <td align="center">


                        <a onclick="jsShopAdminHelper.changeAttributeSetState(<?php echo $o->attribute_set_id; ?>, this);" href="javascript:void(0);" class="btn btn-small <?php echo ($o->published == 'yes') ? 'active' : ''; ?>">
                            <i class="icon-<?php echo ($o->published == 'yes') ? 'checkmark' : 'delete'; ?>">
                            </i>
                        </a>




                    </td>
                </tr>

            <?php endwhile; ?>

        </table>
    </form>
</div>
