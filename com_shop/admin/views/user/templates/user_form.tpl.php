<h2><?php _e('Billing fields', 'com_shop'); ?></h2>
<?php
while ($f = $this->billing->get_field()) {
    if ($f instanceof state)
        $f->set_country(null);
    echo "<br />";
    echo "<label for='" . $f->get_name() . "'>" . __($f->get_label(), 'com_shop') . "</label>";
    echo "<br />";
    echo $f->render();
}
?>

<h2><?php _e('Shipping fields', 'com_shop'); ?></h2>
<p><label for="ship_to_billing"><?php _e('Ship to billing address', 'com_shop'); ?></label>
    <select id="ship_to_billing" name="ship_to_billing">
        <option value="0" <?php echo empty($this->row->ship_to_billing) ? "selected='selected'" : ""; ?> >
            <?php _e('no', 'com_shop'); ?>
        </option>
        <option value="1" <?php echo $this->row->ship_to_billing ? "selected='selected'" : ""; ?> >
            <?php _e('yes', 'com_shop'); ?>
        </option>
    </select>
</p>
<?php
while ($f = $this->shipping->get_field()) {
    if ($f instanceof state)
        $f->set_country(null);
    echo "<br />";
    echo "<label for='" . $f->get_name() . "'>" . __($f->get_label(), 'com_shop') . "</label>";
    echo "<br />";
    echo $f->render();
}
?>