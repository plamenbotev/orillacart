<?php
defined('_VALID_EXEC') or die('access denied');

switch ($this->col) {

    case "id":
        ?>
        <a class="btn btn-small" href="<?php echo get_edit_post_link($this->id); ?>">
            <i class="icon-edit"></i>
            <?php
            echo (string) $this->id;
            ?>
        </a>
        <?php
        break;


        case "billing":
    ?>

        <address>
            <?php echo $this->helper->format_billing($this->id); ?>
                </address>

                <?php
                
        break;

        case "shipping":
        ?>

    <address>
        <?php echo $this->helper->format_shipping($this->id); ?>
        </address>

        <?php
                break;

        case "total_amount":

        echo Factory::getComponent('shop')->getHelper('price')->format($this->order['order_total'], $this->order['currency_sign']);

                break; case "order_status":
                $st = get_terms('order_status', array('hide_empty' => false));
                ?>
                <select id="order_ <?php echo $this->id; ?>" class="change_order_status" name="new_order_status">

                    <?php foreach((array) $st as $s) { ?>
                    <option <?php echo has_term($s->term_id, 'order_status', $this->id) ? "selected='selected'" : ""; ?> value="<?php echo $s->slug; ?>"><?php echo strings ::htmlentities($s->name); ?></option>
                    <?php } ?>

                </select>
                <?php
                break;

                default:
                echo "";
                break;
                }                  