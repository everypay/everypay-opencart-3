<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

class ControllerExtensionPaymentEverypay extends Controller
{
    public function index()
    {
        $this->language->load('extension/payment/everypay');
        $data['button_confirm'] = $this->language->get('button_confirm');

        $this->load->model('checkout/order');

        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        $data['public_key'] = $this->config->get('payment_everypay_public_key');
        $data['currency_code'] = $order_info['currency_code'];
        $data['total'] = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false) * 100;
        $data['merchant_order_id'] = $this->session->data['order_id'];
        $data['card_holder_name'] = $order_info['payment_firstname'].' '.$order_info['payment_lastname'];
        $data['email'] = $order_info['email'];
        $data['phone'] = $order_info['telephone'];
        $data['name'] = $this->config->get('config_name');
        $data['lang'] = $this->session->data['language'];
        $data['sandbox'] = $this->config->get('payment_everypay_sandbox');
        $data['sandbox_warning'] = $this->language->get('text_sandbox_warning');
        $data['return_url'] = $this->url->link('extension/payment/everypay/callback', '', 'SSL');
        $data['installments'] = $this->getInstallments($data['total']);

        return $this->load->view('extension/payment/everypay', $data);
    }


    public function callback()
    {
        $this->load->model('checkout/order');
        if (isset($this->request->request['everypayToken']) && isset($this->request->request['merchant_order_id'])) {
            $everypayToken = $this->request->request['everypayToken'];
            $merchant_order_id = $this->request->request['merchant_order_id'];

            $order_info = $this->model_checkout_order->getOrder($merchant_order_id);
            $amount = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false) * 100;

            $success = false;
            $error = '';

            try {
                $phone = str_replace(['+', '-', ' '], null, $order_info['telephone']);
                $ch = $this->getCurlHandle($everypayToken, $amount, $order_info['email'], $phone, $merchant_order_id);

                //execute post
                $result = curl_exec($ch);
                $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if ($result === false) {
                    $success = false;
                    $error = 'Curl error: '.curl_error($ch);
                } else {
                    $response_array = json_decode($result, true);
                    //Check success response
                    if ($http_status === 200 and isset($response_array['error']) === false) {
                        $success = true;
                    } else {
                        $success = false;

                        if (!empty($response_array['error']['code'])) {
                            $error = $response_array['error']['code'].':'.$response_array['error']['message'];
                        } else {
                            $error = 'EVERYPAY_ERROR:Invalid Response <br/>'.$result;
                        }
                    }
                }

                //close connection
                curl_close($ch);
            } catch (Exception $e) {
                $success = false;
                $error = 'OPENCART_ERROR:Request to EveryPay Failed';
            }

            if ($success === true) {
                if (!$order_info['order_status_id']) {
                    $this->model_checkout_order->addOrderHistory(
                        $merchant_order_id,
                        $this->config->get('payment_everypay_order_status_id'),
                        'Payment Successful. EveryPay Payment Id:'.$response_array['token']
                    );
                } else {
                    $this->model_checkout_order->addOrderHistory(
                        $merchant_order_id,
                        $this->config->get('payment_everypay_order_status_id'),
                        'Payment Successful. EveryPay Payment Id:'.$response_array['token']
                    );
                }

                echo '<html>'."\n";
                echo '<head>'."\n";
                echo '  <meta http-equiv="Refresh" content="0; url='.$this->url->link('checkout/success').'">'."\n";
                echo '</head>'."\n";
                echo '<body>'."\n";
                echo '  <p>Please follow <a href="'.$this->url->link('checkout/success').'">link</a>!</p>'."\n";
                echo '</body>'."\n";
                echo '</html>'."\n";
                exit();
            } else {
                $this->model_checkout_order->addOrderHistory($this->request->request['merchant_order_id'], 10, $error.' Payment Failed! Check EveryPay dashboard for details.');
                echo '<html>'."\n";
                echo '<head>'."\n";
                echo '  <meta http-equiv="Refresh" content="0; url='.$this->url->link('checkout/failure').'">'."\n";
                echo '</head>'."\n";
                echo '<body>'."\n";
                echo '  <p>Please follow <a href="'.$this->url->link('checkout/failure').'">link</a>!</p>'."\n";
                echo '</body>'."\n";
                echo '</html>'."\n";
                exit();
            }
        } else {
            echo 'An error occured. Contact site administrator, please!';
        }
    }

    private function getCurlHandle($token, $amount, $email, $phone, $orderId)
    {
        $sandbox = $this->config->get('payment_everypay_sandbox');
        $url = 1 == $sandbox
            ? 'https://sandbox-api.everypay.gr/payments'
            : 'https://api.everypay.gr/payments';
        $secret_key = $this->config->get('payment_everypay_secret_key');
        $data = array(
            'amount' => $amount,
            'token' => $token,
            'payee_email' => $email,
            'payee_phone' => $phone,
            'description' => 'Order #' . $orderId . ' - ' . round($amount / 100, 2) . '€',
        );
        
        $fields_string =http_build_query($data);

        //cURL Request
        $ch = curl_init();

        //set the url, number of POST vars, POST data
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, $secret_key.':');
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        return $ch;
    }

    private function getInstallments($total)
    {
        $total = round($total / 100, 2);
        $inst = htmlspecialchars_decode($this->config->get('everypay_installments'));
        if ($inst) {
            $installments = json_decode($inst, true);
            foreach ($installments as $i) {
                if ($total >= $i['from'] && $total <= $i['to']) {
                    return $i['max'];
                }
            }
        }


        return false;
    }
}
