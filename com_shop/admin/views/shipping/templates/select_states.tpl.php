<select name="shipping_rate_state[]" multiple="multiple" size="5">
    <?php foreach((array)$this->rows as $row){ ?>

    <option value="<?php echo $row->state_2_code; ?>">
        <?php echo strings::stripAndEncode($row->state_name); ?>
    </option>

    <?php } ?>
    
</select>