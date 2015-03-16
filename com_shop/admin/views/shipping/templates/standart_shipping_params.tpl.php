<fieldset class="panelform">
    <ul class="adminformlist">
        <li>
            <label for="paramsWeight">
                <?php _e("Weight condition:", "com_shop"); ?>             
            </label>
            <select id="paramsWeight" name="params[weight]">
                <option <?php echo $this->params->get('weight', 0) == 0 ? 'selected="selected"' : ''; ?>  value="0">and</option>
                <option <?php echo $this->params->get('weight', 0) == 1 ? 'selected="selected"' : ''; ?> value="1">or</option>
                <option <?php echo $this->params->get('weight', 0) == 2 ? 'selected="selected"' : ''; ?> value="2">and/not</option>
                <option <?php echo $this->params->get('weight', 0) == 3 ? 'selected="selected"' : ''; ?> value="2">or/not</option>
            </select>
        </li>
        <li>
            <label for="paramsVolume">
                <?php _e("Volume condition:", "com_shop"); ?>             
            </label>
            <select id="paramsVolume" name="params[volume]">
                <option <?php echo $this->params->get('volume', 0) == 0 ? 'selected="selected"' : ''; ?>  value="0">and</option>
                <option <?php echo $this->params->get('volume', 0) == 1 ? 'selected="selected"' : ''; ?> value="1">or</option>
                <option <?php echo $this->params->get('volume', 0) == 2 ? 'selected="selected"' : ''; ?> value="2">and/not</option>
                <option <?php echo $this->params->get('volume', 0) == 3 ? 'selected="selected"' : ''; ?> value="2">or/not</option>
            </select>
        </li>
        <li>
            <label for="paramsLength">
                <?php _e("Length condition:", "com_shop"); ?>             
            </label>
            <select id="paramsLength" name="params[length]">
                <option <?php echo $this->params->get('length', 0) == 0 ? 'selected="selected"' : ''; ?>  value="0">and</option>
                <option <?php echo $this->params->get('length', 0) == 1 ? 'selected="selected"' : ''; ?> value="1">or</option>
                <option <?php echo $this->params->get('length', 0) == 2 ? 'selected="selected"' : ''; ?> value="2">and/not</option>
                <option <?php echo $this->params->get('length', 0) == 3 ? 'selected="selected"' : ''; ?> value="2">or/not</option>
            </select>
        </li>
        <li>
            <label for="paramsWidth">
                <?php _e("Width condition:", "com_shop"); ?>             
            </label>
            <select id="paramsWidth" name="params[width]">
                <option <?php echo $this->params->get('width', 0) == 0 ? 'selected="selected"' : ''; ?>  value="0">and</option>
                <option <?php echo $this->params->get('width', 0) == 1 ? 'selected="selected"' : ''; ?> value="1">or</option>
                <option <?php echo $this->params->get('width', 0) == 2 ? 'selected="selected"' : ''; ?> value="2">and/not</option>
                <option <?php echo $this->params->get('width', 0) == 3 ? 'selected="selected"' : ''; ?> value="2">or/not</option>
            </select>
        </li>
        <li>
            <label for="paramsHeight">
                <?php _e("Height condition:", "com_shop"); ?>             
            </label>
            <select id="paramsHeight" name="params[height]">
                <option <?php echo $this->params->get('height', 0) == 0 ? 'selected="selected"' : ''; ?>  value="0">and</option>
                <option <?php echo $this->params->get('height', 0) == 1 ? 'selected="selected"' : ''; ?> value="1">or</option>
                <option <?php echo $this->params->get('height', 0) == 2 ? 'selected="selected"' : ''; ?> value="2">and/not</option>
                <option <?php echo $this->params->get('height', 0) == 3 ? 'selected="selected"' : ''; ?> value="2">or/not</option>
            </select>
        </li>
        <li>
            <label for="paramsTotal">
                <?php _e("Total condition:", "com_shop"); ?>             
            </label>
            <select id="paramsTotal" name="params[total]">
                <option <?php echo $this->params->get('total', 0) == 0 ? 'selected="selected"' : ''; ?>  value="0">and</option>
                <option <?php echo $this->params->get('total', 0) == 1 ? 'selected="selected"' : ''; ?> value="1">or</option>
                <option <?php echo $this->params->get('total', 0) == 2 ? 'selected="selected"' : ''; ?> value="2">and/not</option>
                <option <?php echo $this->params->get('total', 0) == 3 ? 'selected="selected"' : ''; ?> value="2">or/not</option>
            </select>
        </li>
        <li>
            <label for="paramsZip">
                <?php _e("Zip condition:", "com_shop"); ?>             
            </label>
            <select id="paramsZip" name="params[zip]">
                <option <?php echo $this->params->get('zip', 0) == 0 ? 'selected="selected"' : ''; ?>  value="0">and</option>
                <option <?php echo $this->params->get('zip', 0) == 1 ? 'selected="selected"' : ''; ?> value="1">or</option>
                <option <?php echo $this->params->get('zip', 0) == 2 ? 'selected="selected"' : ''; ?> value="2">and/not</option>
                <option <?php echo $this->params->get('zip', 0) == 3 ? 'selected="selected"' : ''; ?> value="2">or/not</option>
            </select>
        </li>

        <li>
            <label for="paramsCountry">
                <?php _e("Country condition:", "com_shop"); ?>             
            </label>
            <select id="paramsCountry" name="params[country]">
                <option <?php echo $this->params->get('country', 0) == 0 ? 'selected="selected"' : ''; ?>  value="0">and</option>
                <option <?php echo $this->params->get('country', 0) == 1 ? 'selected="selected"' : ''; ?> value="1">or</option>
                <option <?php echo $this->params->get('country', 0) == 2 ? 'selected="selected"' : ''; ?> value="2">and/not</option>
                <option <?php echo $this->params->get('country', 0) == 3 ? 'selected="selected"' : ''; ?> value="2">or/not</option>
            </select>
        </li>
        <li>
            <label for="paramsState">
                <?php _e("Country condition:", "com_shop"); ?>
            </label>
            <select id="paramsState" name="params[state]">
                <option <?php echo $this->params->get('state', 0) == 0 ? 'selected="selected"' : ''; ?>  value="0">and</option>
                <option <?php echo $this->params->get('state', 0) == 1 ? 'selected="selected"' : ''; ?> value="1">or</option>
                <option <?php echo $this->params->get('state', 0) == 2 ? 'selected="selected"' : ''; ?> value="2">and/not</option>
                <option <?php echo $this->params->get('state', 0) == 3 ? 'selected="selected"' : ''; ?> value="2">or/not</option>
            </select>
        </li>
    </ul>
</fieldset>