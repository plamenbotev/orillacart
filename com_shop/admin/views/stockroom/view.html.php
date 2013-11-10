<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewStockroom extends view {

    public function display() {



        $bar = toolbar::getInstance('toolbar', __('Stockrooms', 'com_shop'), 'default', 'cube');

        $bar->appendButton('Link', 'new', __('new', 'com_shop'), admin_url('admin.php?page=component_com_shop-stockroom&task=addnew'), false, true);
        $bar->appendButton('Standard', 'delete', __('delete', 'com_shop'), 'document.adminForm.submit()', false, true);





        Factory::getMainframe()->addCustomHeadTag('stockroom-display', "

<script type='text/javascript'>
jQuery(document).ready(function() {

jQuery('#toggle').click(
   function()
   {
   
      jQuery(\"INPUT[type='checkbox']\").attr('checked', jQuery('#toggle').is(':checked'));   
   }
);




});


</script>
");







        parent::display('list_stockrooms');
    }

    public function addnew() {

        $bar = toolbar::getInstance('toolbar', __('Stockrooms', 'com_shop'), 'default', 'cube');
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url('admin.php?page=component_com_shop-stockroom'), false, true);
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()', false, true);

        parent::display('stockroomform');
    }

    public function manage_stocks() {


        $bar = toolbar::getInstance('toolbar', __('Amounts', 'com_shop'), 'default', 'cube');
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url('admin.php?page=component_com_shop-stockroom'), false, true);




        /* load the ui and the dialog plugin */
        Factory::getMainframe()->addScript('jquery-ui-dialog');
        Factory::getMainframe()->addStyle('jquery.ui.theme', Factory::getApplication('shop')->getAssetsUrl() . "/jquery.ui.css");

        Factory::getMainframe()->addCustomHeadTag('stockroom-manage_stocks', "<script type='text/javascript'>

		var shop_tree_folderImage = \"" . Factory::getApplication('shop')->getAssetsUrl() . "/images/folder.png" . "\";
		var shop_tree_root_image  = \"" . Factory::getApplication('shop')->getAssetsUrl() . "/images/root.png" . "\";
		</script>
		
		
<script type='text/javascript'>




jQuery(function() {



    jQuery('input#select_parent').autocomplete({
        source: function(request, response) {
            jQuery.ajax({
                url: ajaxurl + '?action=framework-ajax-admin&component=shop&con=orders&task=get_parent_list',
                dataType: 'json',
                data: {
                    str: request.term
                },
                success: function(data) {

                    response(jQuery.map(data, function(value, key) {

                        return {
                            pid: key,
                            label: value,
                            value: value
                        }
                    }));
                }
            });
        },
        minLength: 2,
        select: function(event, ui) {
            if (ui.item) {

                document.getElementById('parent_product').value = ui.item.pid;

                return ui.item.label;
            }

        },
        open: function() {
            jQuery(this).removeClass('ui-corner-all').addClass('ui-corner-top');
        },
        close: function() {
            jQuery(this).removeClass('ui-corner-top').addClass('ui-corner-all');
        }
    });










dialog = jQuery('<div id=\"catselect\" style=\"display:none;\"><div>\
<input type=\"checkbox\" name=\"recursive\" checked=\"checked\" value=\"1\" /> recursive\
<input type=\"button\" value=\"clear\" id=\"clearall\" />\
</div>\<div id=\"tree\"  class=\"tree\"></div></div>').appendTo('body');
	

jQuery.getScript('" . Factory::getApplication('shop')->getAssetsUrl() . "/js/stockroom_categories_tree.js');	

  jQuery(\"#modal\").click(function() {
 
  	
        
		 dialog.dialog({modal:true,draggable:false});
		
		 

	
	return false;
		
  
  
  });


jQuery(\"#clearall\").click(function(){
	jQuery(\"#tree INPUT[type='checkbox']\").attr('checked', false);
});


 jQuery('#adminForm').submit(function() {

  jQuery('#catselect').css('display','none').prependTo('#adminForm');

  });
  });

</script>
		
		
		");





        /* load the tree */
         Factory::getMainframe()->addscript('jquery');
   
        Factory::getMainframe()->addStyle('jquery-tree-css', Factory::getApplication('shop')->getAssetsUrl() . '/jstree.css');
        Factory::getMainframe()->addScript('jquery-tree-js', Factory::getApplication('shop')->getAssetsUrl() . '/js/jquery.jstree.js');
        Factory::getMainframe()->addScript('jquery-hotkeys-js', Factory::getApplication('shop')->getAssetsUrl() . '/js/jquery.hotkeys.js');
        Factory::getMainframe()->addScript('jquery-cookie-js', Factory::getApplication('shop')->getAssetsUrl() . '/js/jquery.cookie.js');
         Factory::getMainframe()->addscript('jquery-ui-autocomplete');
   
        




        switch (request::getWord('stockroom_type', 'product', 'POST')) {

            case 'product_attribute_property':

                parent::display('manage_property_stocks');

                break;

            case 'product':
            default:
                parent::display('manage_product_stocks');
                break;
        }
    }

}