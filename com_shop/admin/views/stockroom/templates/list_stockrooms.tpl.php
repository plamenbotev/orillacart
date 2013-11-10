<?php
defined('_VALID_EXEC') or die('access denied');
?>
<div class="com_shop">
    <form id='deletestockrooms' name='adminForm' method='post' action='<?php echo admin_url('admin.php?page=component_com_shop-stockroom'); ?>'>
        <input type='hidden' name='task' value='delete' />
        <table class="wp-list-table widefat fixed posts">
            <thead>
                <tr>
                    <th style="padding:0; width:24px;">
                        <input type="checkbox" id="toggle" value=""  />
                    </th>
                    <th>
                        <?php _e('Stock Room Name', 'com_shop'); ?>		    
                    </th>

                    <th>
                        <?php _e('Description', 'com_shop'); ?>		   
                    </th>

                    <th>
                        <?php _e('Published', 'com_shop'); ?>		  
                    </th>
                </tr>
            </thead>


            <?php
            $c = 0;
            while ($o = $this->db->nextObject()):

                $mod_or_even = ($c % 2 == 0 ) ? 0 : 1;
                $c++;
                ?>
                <tr class="row<?php echo $mod_or_even; ?>">
                    <td width="10">
                        <input type="checkbox"  name="ids[]" value="<?php echo $o->id; ?>"  />		
                    </td>
                    <td align="left">
                        <button onclick="window.location.href = '<?php echo admin_url('admin.php?page=component_com_shop-stockroom&task=addnew&id=' . $o->id); ?>';
                                    return false;" class="btn btn-small">
                            <span class="icon-edit">
                            </span>
                            <?php echo strings::stripandencode($o->name); ?>
                        </button>
                    </td>
                    <td align="left">

                        <?php echo $o->desc; ?>

                    </td>
                    <td>
                        <a onclick="jsShopAdminHelper.changeStockRoomState(<?php echo $o->id; ?>, this);" href="javascript:void(0);" class="btn btn-small <?php echo ($o->published == 'yes') ? 'active' : ''; ?>">
                            <i class="icon-<?php echo ($o->published == 'yes') ? 'checkmark' : 'delete'; ?>">
                            </i>
                        </a>
                    </td>
                </tr>

            <?php endwhile; ?>
        </table>
    </form>
</div>