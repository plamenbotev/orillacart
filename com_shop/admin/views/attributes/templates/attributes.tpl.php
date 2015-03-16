<?php
defined('_VALID_EXEC') or die('access denied');
?>

<div class='maincontainer com_shop'>
    <div style='width:40%; float:left;' class='toolbar'>
        <input type='button' name='adnew' value='add' onclick='window.location = "admin.php?page=component_com_shop&con=attributes&task=addnew"' />
        <input type='submit' name='delete' value='delete' onclick='jQuery("#delform").submit();' />
    </div>
    <div style='clear:both;'></div>
    <div class='leftcol'>
        <form id='delform' method='post' action='<?php echo admin_url('admin.php?page=component_com_shop-attributes'); ?> '>
            <input type='hidden' name='task' value='delete' />
            <table class='adminlist'>
                <thead>
                    <tr>
                        <th style='width:14px;'><input type="checkbox" id="toggle" value=""  /></th>
                        <th ><?php _e('name', 'shop'); ?></th>
                        <th ><?php _e('edit', 'shop'); ?></th>
                    </tr>
                </thead>
                <?php
                $c = 0;
                while ($o = $this->db->nextObject()):
                    $mod_or_even = ($c % 2 == 0 ) ? 0 : 1;
                    $c++;
                    ?>
                    <tr class="row<?php echo $mod_or_even; ?>" >
                        <td><input type="checkbox" name='ids[]'  value="<?php echo $o->id; ?>"  /></td>
                        <td><?php echo $o->name; ?> </td>
                        <td>
                            <a onclick="jsShopAdminHelper.changeStockRoomState(<?php echo $o->id; ?>, this);" href="javascript:void(0);" class="btn btn-small <?php echo ($o->published == 'yes') ? 'active' : ''; ?>">
                                <i class="icon-<?php echo ($o->published == 'yes') ? 'checkmark' : 'delete'; ?>">
                                </i>
                            </a>


                            <a href='javascript:void(0);' onclick='jsShopAdminHelper.attributes.editSet(<?php echo $o->id; ?>);'><?php _e('edit', 'shop'); ?></a></td>
                    </tr>
                <?php endwhile; ?>
            </table>
        </form>
    </div>
    <div class='rightcol'>
        <div id='container'> </div>
    </div>
</div>