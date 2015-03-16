<?php

defined('_VALID_EXEC') or die('access denied');

class shopViewStockroom extends view {

    public function display() {



        $bar = toolbar::getInstance('toolbar', __('Stockrooms', 'com_shop'), 'default', 'cube');

        $bar->appendButton('Link', 'new', __('new', 'com_shop'), admin_url('admin.php?page=component_com_shop-stockroom&task=addnew'));
        $bar->appendButton('Standard', 'delete', __('delete', 'com_shop'), 'document.adminForm.submit()');





        Factory::getHead()->addCustomHeadTag('stockroom-display', "

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







        $this->loadTemplate('list_stockrooms');
    }

    public function addnew() {

        $bar = toolbar::getInstance('toolbar', __('Stockrooms', 'com_shop'), 'default', 'cube');
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url('admin.php?page=component_com_shop-stockroom'));
        $bar->appendButton('Standard', 'save', __('save', 'com_shop'), 'document.adminForm.submit()');

         $this->loadTemplate('stockroomform');
    }

    public function manage_stocks() {


        $bar = toolbar::getInstance('toolbar', __('Amounts', 'com_shop'), 'default', 'cube');
        $bar->appendButton('Link', 'cancel', __('cancel', 'com_shop'), admin_url('admin.php?page=component_com_shop-stockroom'));




        /* load the ui and the dialog plugin */
        Factory::getHead()->addScript('jquery-ui-dialog');
        Factory::getHead()->addStyle('jquery.ui.theme', Factory::getComponent('shop')->getAssetsUrl() . "/jquery.ui.css");

        Factory::getHead()->addCustomHeadTag('stockroom-manage_stocks', "<script type='text/javascript'>

		var shop_tree_folderImage = \"" . Factory::getComponent('shop')->getAssetsUrl() . "/images/folder.png" . "\";
		var shop_tree_root_image  = \"" . Factory::getComponent('shop')->getAssetsUrl() . "/images/root.png" . "\";
		</script>
		
		
<script type='text/javascript'>




jQuery(function() {



    jQuery('input#select_parent').autocomplete({
        source: function(request, response) {
            jQuery.ajax({
                url: ajaxurl + '?action=ajax-call-admin&component=shop&con=orders&task=get_parent_list',
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
	

jQuery.getScript('" . Factory::getComponent('shop')->getAssetsUrl() . "/js/stockroom_categories_tree.js');	

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
        Factory::getHead()->addscript('jquery');

        Factory::getHead()->addStyle('jquery-tree-css', Factory::getComponent('shop')->getAssetsUrl() . '/jstree.css');
        Factory::getHead()->addScript('jquery-tree-js', Factory::getComponent('shop')->getAssetsUrl() . '/js/jquery.jstree.js');
        Factory::getHead()->addScript('jquery-hotkeys-js', Factory::getComponent('shop')->getAssetsUrl() . '/js/jquery.hotkeys.js');
        Factory::getHead()->addScript('jquery-cookie-js', Factory::getComponent('shop')->getAssetsUrl() . '/js/jquery.cookie.js');
        Factory::getHead()->addscript('jquery-ui-autocomplete');



        $input = Factory::getApplication()->getInput();

        $this->assign("input", $input);

        switch ($input->get('stockroom_type', 'product', 'WORD')) {

            case 'product_attribute_property':

                $this->loadTemplate('manage_property_stocks');

                break;

            case 'product':
            default:
                 $this->loadTemplate('manage_product_stocks');
                break;
        }
    }

}
