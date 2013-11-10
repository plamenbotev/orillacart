<?php if (!empty($this->states)) { ?>

    <select name="<?php echo $this->name; ?>">
        <?php foreach ((array) $this->states as $o) { ?>
            <option value="<?php echo $o->state_2_code; ?>"><?php echo strings::stripAndEncode($o->state_name); ?></option>
        <?php } ?>
    </select>
<?php } else { ?>
    <input type="text" name="<?php echo $this->name; ?>" value="" />
<?php } ?>