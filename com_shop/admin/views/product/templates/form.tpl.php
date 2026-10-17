<?php defined('_VALID_EXEC') or die('access denied'); ?>

<div class="com_shop">
    <input id="product_parent_id" type="hidden" name="parent_id" value="<?php echo $this->parent_id; ?>" />

    <dl class="tabs" id="pane">
        <?php
        $this->loadTemplate('productinformation');

        //if the current product is variation disable creations of attributes and variations
        if (!$this->parent_id) {
            $this->loadTemplate('attributes');
            $this->loadTemplate('variations');
        } else { // and allow editing of the assocs with the parent product attributes
            $this->loadTemplate('variation_assocs');
        }
        $this->loadTemplate('dimensions');
        $this->loadTemplate('inventory');
        ?>
    </dl>
</div>