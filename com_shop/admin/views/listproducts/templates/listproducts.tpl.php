<?php
defined('_VALID_EXEC') or die('access denied');


switch ($this->col) {

    case "menu_order":
        echo $this->p->post->menu_order;
        break;

    case "image":
        ?>
        <a href="<?php echo get_edit_post_link($this->id); ?>">
            <?php
            echo get_the_post_thumbnail($this->id, 'product_mini');
            ?>
        </a>
        <?php
        break;

    case "product_number":

        echo strings::stripAndEncode($this->p->sku);
        break;

    case 'price':
        echo $this->p->price . Factory::getApplication('shop')->getParams()->get('currency');
        break;

    case 'category':

        $cats = $this->getModel('product_admin')->getProductCats($this->id);

        foreach ($cats as $cat) {
            echo "<strong>" . strings::stripandencode($cat->name) . "</strong><hr style='height:1px; ' />";
        }
        break;

    case 'manufacturer':
        $brands = $this->getModel('product_admin')->get_product_brands($this->id);

        foreach ((array) $brands as $brand) {
            echo "<strong>" . strings::stripandencode($brand->name) . "</strong><hr style='height:1px;' />";
        }
        break;

    case'published':
        ?>
        <a class="btn btn-small <?php echo $this->p->published == 'yes' ? 'active' : ''; ?>" href="javascript:void(0);" onclick="jsShopAdminHelper.changeProductState(<?php echo $this->id; ?>, this);"  >
            <i class="icon-<?php echo $this->p->published == 'yes' ? 'checkmark' : 'delete'; ?>">
            </i>
        </a>

        <?php
        break;
}