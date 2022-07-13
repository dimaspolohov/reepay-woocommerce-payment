<?php

class WC_Reepay_Order_Capture {
    /**
     * Constructor
     */
    public function __construct() {
        add_action('woocommerce_after_order_itemmeta', array($this, 'add_item_capture_button'), 10, 3);
        add_action('woocommerce_after_order_fee_item_name', array($this, 'add_item_capture_button'), 10, 3);
        add_action('woocommerce_order_status_changed', array($this, 'capture_full_order'), 10, 4);
        add_action('admin_init', array($this, 'process_item_capture'));
        add_action('woocommerce_order_item_add_action_buttons', array($this, 'capture_full_order_button'), 10, 1);
    }

    public function capture_full_order( $order_id,  $this_status_transition_from,  $this_status_transition_to,  $instance){
        $order = wc_get_order( $order_id );
        $payment_method = $order->get_payment_method();

        if(strpos($payment_method, 'reepay') === false){
            return;
        }

        if($this_status_transition_to == 'completed'){

            foreach ( $order->get_items() as  $item_key => $item ) {
                $this->settle_item($item, $order);
            }

            foreach( $order->get_items( 'shipping' ) as $item_id => $item ){
                $this->settle_item($item, $order);
            }
        }
    }

    public function settle_item($item, $order){
        $settled = $item->get_meta('settled');
        if(empty($settled)) {
            $gateway = rp_get_payment_method( $order );

            $item_data = $this->get_item_data($item, $order);
            $line_item_data = [$item_data];
            $total = $item_data['amount'];
            unset($_POST['post_status']);
            if($total > 0 && $this->check_allow_capture($order)){
                $result = $gateway->api->settle( $order, $total, $line_item_data );
                if ( is_wp_error( $result )) {
                    $gateway->log( sprintf( '%s Error: %s', __METHOD__, $result->get_error_message() ) );
                    set_transient( 'reepay_api_action_error', __( $result->get_error_message(), 'reepay-checkout-gateway' ), MINUTE_IN_SECONDS / 2 );
                    return false;
                }

                if($result){
                    $item->update_meta_data('settled',  $total / 100);
                    $item->save(); // Save item
                }
            }
        }
    }

    public function check_allow_capture($order){
        $payment_method = $order->get_payment_method();

        if(strpos($payment_method, 'reepay') === false){
            return false;
        }

        $gateway = rp_get_payment_method( $order );

        $invoice_data = $gateway->api->get_invoice_data($order);

        if ( is_wp_error( $invoice_data ) ) {
            echo __( 'Invoice not found', 'reepay-checkout-gateway' );
            return false;
        }

        if($invoice_data['authorized_amount'] > $invoice_data['settled_amount']){
            return true;
        }

        return false;
    }

    public function get_no_settled_amount($order){
        $amount = 0;
        foreach ( $order->get_items() as  $item_key => $item ) {
            $settled = $item->get_meta('settled');
            if(empty($settled)) {
                $price = $this->get_item_price($item, $order);
                if(!empty($price)){
                    $amount += $price['with_tax'];
                }
            }
        }

        foreach( $order->get_items( 'shipping' ) as $item_id => $item ){
            $settled = $item->get_meta('settled');
            if(empty($settled)) {
                $price = $this->get_item_price($item, $order);
                if(!empty($price)){
                    $amount += $price['with_tax'];
                }
            }
        }

        return $amount;
    }

    public function capture_full_order_button($order){

        if($this->check_allow_capture($order)){
            $amount = $this->get_no_settled_amount($order);
            if($amount > 0){
                $amount = number_format(round($amount, 2), 2, '.', '');
                echo '<button type="submit" class="button save_order button-primary capture-item-button" name="all_items_capture" value="'.$order->get_id().'">
                    '.__( 'Capture '. $order->get_currency() . $amount, 'reepay-checkout-gateway' ).'
                </button>';
            }
        }
    }

    public function add_item_capture_button($item_id, $item, $product){
        $order_id = wc_get_order_id_by_order_item_id( $item_id );
        $order = wc_get_order( $order_id );

        $settled = $item->get_meta('settled');
        $data = $item->get_data();

        if(empty($settled) && floatval($data['total']) > 0 && $this->check_allow_capture($order)){
            $order_item = WC_Order_Factory::get_order_item( $item_id );
            $price = $this->get_item_price($order_item, $order);
            $unitPrice = number_format(round($price['with_tax'], 2), 2, '.', '');

            echo '<button type="submit" class="button save_order button-primary capture-item-button" name="line_item_capture" value="'.$item_id.'">
                '.__( 'Capture '. $order->get_currency() . $unitPrice, 'reepay-checkout-gateway' ).'
            </button>';
        }
    }

    public function process_item_capture(){
        if(isset($_POST['line_item_capture']) && isset($_POST['post_type']) && isset($_POST['post_ID'])){
            if($_POST['post_type'] == 'shop_order'){

                $order = wc_get_order( $_POST['post_ID'] );

                $item = WC_Order_Factory::get_order_item( $_POST['line_item_capture'] );
                $this->settle_item($item, $order);
            }
        }

        if(isset($_POST['all_items_capture']) && isset($_POST['post_type']) && isset($_POST['post_ID'])){
            if($_POST['post_type'] == 'shop_order'){
                $order = wc_get_order( $_POST['post_ID'] );

                $payment_method = $order->get_payment_method();

                if(strpos($payment_method, 'reepay') === false){
                    return;
                }

                foreach ( $order->get_items() as  $item_key => $item ) {
                    $this->settle_item($item, $order);
                }

                foreach( $order->get_items( 'shipping' ) as $item_id => $item ){
                    $this->settle_item($item, $order);
                }
            }
        }
    }

    public function get_item_data($order_item, $order){
        $prices_incl_tax = wc_prices_include_tax();

        $price = $this->get_item_price($order_item, $order);
        $tax = $price['with_tax'] - $price['original'];
        $taxPercent = ($tax > 0) ? round(100 / ($price['original'] / $tax)) : 0;
        $unitPrice = round(($prices_incl_tax ? $price['with_tax'] : $price['original']) / $order_item->get_quantity(), 2);

        $item_data = [
            'ordertext' => $order_item->get_name(),
            'quantity' => $order_item->get_quantity(),
            'amount' => rp_prepare_amount($unitPrice, $order->get_currency()),
            'vat' => round($taxPercent / 100, 2),
            'amount_incl_vat' => $prices_incl_tax
        ];

        return $item_data;
    }


    public function get_item_price($order_item, $order){
        if(is_object($order_item) && get_class($order_item) == 'WC_Order_Item_Product'){
            $price['original'] = floatval($order->get_line_subtotal( $order_item, false, false ));
            if($order_item->get_subtotal() !== $order_item->get_total()){
                $discount = $order_item->get_subtotal() - $order_item->get_total();
                $price['original'] = $price['original'] - $discount;
            }
        }else{
            $price['original'] = floatval($order->get_line_total( $order_item, false, false ));
        }


        $tax_data = wc_tax_enabled() ? $order_item->get_taxes() : false;
        $taxes = $order->get_taxes();

        $res_tax = 0;
        if(!empty($taxes)){
            foreach ($taxes as $tax){
                $tax_item_id = $tax->get_rate_id();
                $tax_item_total = isset( $tax_data['total'][ $tax_item_id ] ) ? $tax_data['total'][ $tax_item_id ] : '';
                if(!empty($tax_item_total)){
                    $res_tax += floatval($tax_item_total);
                }
            }
        }

        $price['with_tax'] = $price['original'] + $res_tax;

        return $price;
    }
}

new WC_Reepay_Order_Capture();
