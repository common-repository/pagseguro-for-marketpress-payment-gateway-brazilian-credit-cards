<?php

/*


Plugin: Pagseguro for Marketpress - Brazilian Credit Cards
Plugin URI: http://wpsoft.com.br/store/products/pagseguro-marketpress-gateway-de-pagamento/
Author: diegpl , pkelbert , Leo Baiano
Author URL: http://wpsoft.com.br/
Description:
Payment gateway plugin for Pagseguro Brazilian Credit Cards, for Marketpress.
Plugin de gateway de pagamento pelo Pagseguro, para MarketPress.
Version: 1.0
License: GNU General Public License v2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Tags: https,pagseguro,e-commerce,https,ssl,brazilian credit cards,credit cards, payment gateway, marketpress plugin,marketpress brazilian pagseguro,marketpress brazilian credit cards
Text Domain: twentythirteen


 */

require_once plugin_dir_path( __FILE__ ) . "pagseguro/pagseguro.php";
require_once plugin_dir_path( __FILE__ ) . . "pagseguro/api/PagSeguroLibrary/PagSeguroLibrary.php";

class MP_Gateway_PagSeguro extends MP_Gateway_API {

  //private gateway slug. Lowercase alpha (a-z) and dashes (-) only please!
  var $plugin_name = 'pagseguro';
  
  //name of your gateway, for the admin side.
  var $admin_name = '';
  
  //public name of your gateway, for lists and such.
  var $public_name = '';

  //url for an image for your checkout method. Displayed on checkout form if set
  var $method_img_url = '';
  
  //url for an submit button image for your checkout method. Displayed on checkout form if set
  var $method_button_img_url = '';

  //whether or not ssl is needed for checkout page
  var $force_ssl = false;

  //always contains the url to send payment notifications to if needed by your gateway. Populated by the parent class
  var $ipn_url;

  //whether if this is the only enabled gateway it can skip the payment_form step
  var $skip_form = true;
  
  //only required for global capable gateways. The maximum stores that can checkout at once
  var $max_stores = 10;

  // Payment action
  var $payment_action = 'Sale';

  //paypal vars
  var $API_Username, $API_Password, $API_Signature, $SandboxFlag, $returnURL, $cancelURL, $API_Endpoint, $paypalURL, $version, $currencyCode, $locale;
  
  /**
   * Runs when your class is instantiated. Use to setup your plugin instead of __construct()
   */
  function on_creation() {
    global $mp;
    $settings = get_option('mp_settings');
    
    //set names here to be able to translate
    $this->admin_name = __('Pagseguro Checkout', 'mp');
    $this->public_name = __('Pagseguro', 'mp');
		
    //set paypal vars
    /** @todo Set all array keys to resolve Undefined indexes notice */;
    if ( $mp->global_cart )
      $settings = get_site_option( 'mp_network_settings' );
    
    $this->API_Username = $settings['gateways']['pagseguro']['api_user'];
    $this->API_Password = $settings['gateways']['pagseguro']['api_pass'];
    $this->API_Signature = $settings['gateways']['pagseguro']['api_sig'];
    $this->currencyCode = $settings['gateways']['pagseguro']['currency'];
    $this->locale = $settings['gateways']['pagseguro']['locale'];
    $this->returnURL = mp_checkout_step_url('confirm-checkout');
    $this->cancelURL = mp_checkout_step_url('checkout') . "?cancel=1";
    $this->version = "69.0"; //api version

  }
  
  /**
   * Echo a settings meta box with whatever settings you need for you gateway.
   *  Form field names should be prefixed with mp[gateways][plugin_name], like "mp[gateways][plugin_name][mysetting]".
   *  You can access saved settings via $settings array.
   */

  function gateway_settings_box($settings) {
    global $mp;
    
?>
        <div class="postbox"> 
            <h3 class="handle">Configurações PagSeguro</h3>  
            <table class="form-table">
                <tr>
                    <th scope="row">Autenticação PagSeguro</th>
                    <td>
                        <p>
                            <label>Email</label><br />
                            <input value="<?php echo esc_attr($settings['gateways']['pagseguro']['email_pagseguro']); ?>" size="50" name="mp[gateways][pagseguro][email_pagseguro]" type="text" />
                        </p>
                        <p>
                            <label>Token</label><br />
                            <input value="<?php echo esc_attr($settings['gateways']['pagseguro']['token_pagseguro']); ?>" size="50" name="mp[gateways][pagseguro][token_pagseguro]" type="text" />
                        </p>
                    </td>
                </tr>
            </table>
        </div>
<?php
    
  }
  
  function process_payment_form($global_cart, $shipping_info) {
    global $mp;
    
    $order_id = $mp->generate_order_id();
    
    $resultado = $this->SetPagSeguro($global_cart, $shipping_info, $order_id);
    
    $check = explode("/",$resultado);
        
    if($check[0] == 'https:'){
      $this->RedirectToPagSeguro($resultado);
    }
    else{
      $mp->cart_checkout_error( __('Ocorreu um erro enquanto você efetuava o pagamento no PagSeguro.', 'mp') . $resultado );
    }
    
  }
  
  function process_payment($cart, $shipping_info){}
  
  function order_confirmation($order) {}
  
  function order_confirmation_email($msg, $order) {
    return $msg;
  }
  
  function order_confirmation_msg($content, $order) {
    global $mp;
    
    $content .= '<p>' . __('Compra cadastrada com sucesso, no momento o status do seu pedido encontra-se como "recebido", acompanhe através do link abaixo pois este status será alterado a medida que formos recebendo a confirmação de pagamento.', 'mp') . '</p>';
    return $content;
  }
  
  function SetPagSeguro($global_cart, $shipping_info, $order_id){
    global $mp;
    $selected_cart = $global_cart;
    
    $totals = array();
    foreach ($selected_cart as $product_id => $variations) {
			foreach ($variations as $data) {
      	$totals[] = $data['price'] * $data['quantity'];
      }
    }
    $total = array_sum($totals);

    if ( $coupon = $mp->coupon_value($mp->get_coupon_code(), $total) ) {
      $total = $coupon['new_total'];
    }

    //shipping line
    if ( ($shipping_price = $mp->shipping_price()) !== false ) {
      $total = $total + $shipping_price;
    }

    //tax line
    if ( ($tax_price = $mp->tax_price()) !== false ) {
      $total = $total + $tax_price;
    }
    
    // Registra a ordem na loja
    $settings = get_option('mp_settings');
    $timestamp = time();
    
    $payment_info['gateway_public_name'] = $this->public_name;
    $payment_info['gateway_private_name'] = $this->admin_name;
    $payment_info['status'][$timestamp] = __('Invoiced', 'mp');
    $payment_info['total'] = $total;
    $payment_info['currency'] = $settings['currency'];
    $payment_info['method'] = __('pagseguro', 'mp');
    $payment_info['transaction_id'] = $order_id;
    
    $result = $mp->create_order($order_id, $selected_cart, $shipping_info, $payment_info, false);
    
    // Manda pro pagseguro
    
    // Instantiate a new payment request
    $paymentRequest = new PaymentRequest();
    
    // Sets the currency
    $paymentRequest->setCurrency("BRL");
    
    $i = 1;
    foreach($selected_cart as $key => $cart){
      foreach ($cart as $product_id => $variations) {
	$cod = '000' . $i;
	$nome = $variations['name'];
	$qtd = $variations['quantity'];
	$preco = $variations['price'];
	$paymentRequest->addItem($cod, $nome, $qtd, $preco);
	$i++;
      }
    }

    //$paymentRequest->addItem('0001', 'Notebook', 1, 2430.00);
    //$debug = $cod . '-' . $nome . '-' . $qtd . '-' . $preco;
    
    // Sets a reference code for this payment request, it is useful to identify this payment in future notifications.
    $paymentRequest->setReference($order_id);
    
    // Sets shipping information for this payment request
    $paymentRequest->setShippingType(1);
    
    // Dados do comprador
    $nome = $shipping_info['name'];
    $email = $shipping_info['email'];
    
    //$cep = $shipping_info['zip'];
    $newCep = ereg_replace("[^0-9]", "", $shipping_info['zip']);
    if(strlen($newCep) == 8){
      $cep = $newCep;
    }
    else{
      $cep = '';
    }

    
    $endereco = $shipping_info['address1'];
    $numero = 's/n';
    $complemento = $shipping_info['address2'];
    $bairro = $shipping_info['address2'];
    $cidade = $shipping_info['city'];
    $estado = substr($shipping_info['state'], 0, 2);
    $pais = 'BRA';
    
    $paymentRequest->setShippingAddress($cep,  $endereco,  $numero, $complemento, $bairro, $cidade, '', $pais);
    
    // Sets your customer information.
    $paymentRequest->setSender($nome, $email, '', '');
    $paymentRequest->setRedirectUrl(mp_checkout_step_url('confirmation'));
    
    try {
      $settings = get_option('mp_settings');
      $credentials = new AccountCredentials($settings['gateways']['pagseguro']['email_pagseguro'], $settings['gateways']['pagseguro']['token_pagseguro']);
      
      // Register this payment request in PagSeguro, to obtain the payment URL for redirect your customer.
      $url = $paymentRequest->register($credentials);
      //self::printPaymentUrl($url);
      return $url;
    } catch (PagSeguroServiceException $e) {
      return $e->getMessage();
    }
  }
  
  function RedirectToPagSeguro($url) {

    // Redirect to paypal.com here
    wp_redirect($url);

    exit;

  }
  
}
//register shipping plugin
mp_register_gateway_plugin( 'MP_Gateway_PagSeguro', 'pagseguro', __('Pagseguro Checkout', 'mp'), true );
?>
