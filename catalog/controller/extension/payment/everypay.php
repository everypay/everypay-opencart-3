<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

class ControllerExtensionPaymentEverypay extends Controller
{
    private function logIris($event, $message, $context = array())
    {
        $contextStr = '';
        if (!empty($context)) {
            $pairs = array();
            foreach ($context as $key => $value) {
                if (is_array($value)) {
                    $pairs[] = $key . '=' . json_encode($value);
                } else {
                    $pairs[] = $key . '=' . $value;
                }
            }
            $contextStr = ' - ' . implode(', ', $pairs);
        }
        $this->log->write('IRIS: [' . $event . '] ' . $message . $contextStr);
    }

    public function index()
    {
        $this->language->load('extension/payment/everypay');
        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['text_iris_request_error'] = $this->language->get('text_iris_request_error');
        $data['text_iris_callback_error'] = $this->language->get('text_iris_callback_error');
        $data['iris_callback_notice'] = '';
        if (!empty($this->session->data['iris_callback_notice'])) {
            $data['iris_callback_notice'] = $this->session->data['iris_callback_notice'];
            unset($this->session->data['iris_callback_notice']);
        }

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
        $data['checkout_url'] = $this->url->link('checkout/checkout', '', true);
        $data['installments'] = $this->getInstallments($data['total']);
        $data['billingAddress'] = $order_info['payment_address_1'];
        $data['iris_enabled'] = (bool)$this->config->get('payment_everypay_iris_enabled');
        $data['iris_md'] = '';
        $data['iris_session_url'] = '';
        $data['iris_callback_url'] = '';
        $data['iris_merchant_name'] = '';
        $data['iris_country'] = '';

        if ($data['iris_enabled']) {
            $this->load->model('checkout/order');
            $this->ensureIrisSchema();
            $reference = $this->getIrisReference($this->session->data['order_id']);
            if (!$reference) {
                $reference = $this->createIrisReference($this->session->data['order_id']);
            }
            $country = $order_info['payment_iso_code_2'] ? $order_info['payment_iso_code_2'] : $order_info['shipping_iso_code_2'];
            if (!$country) {
                $country = 'GR';
            }
            $data['iris_md'] = $reference['md'];
            $data['iris_session_url'] = $this->url->link('extension/payment/everypay/createIrisSession', '', true);
            $data['iris_callback_url'] = $this->url->link('extension/payment/everypay/irisCallback', '', true);
            $data['iris_merchant_name'] = $this->config->get('config_name');
            $data['iris_country'] = strtoupper($country);
        }

        return $this->load->view('extension/payment/everypay', $data);
    }


    public function callback()
    {
        $this->load->model('checkout/order');
        if (isset($this->request->request['everypayToken']) && isset($this->request->request['merchant_order_id'])) {
            $everypayToken = $this->request->request['everypayToken'];
            $merchant_order_id = $this->request->request['merchant_order_id'];

            $this->log->write('EVERYPAY: [CallbackReceived] Legacy callback - order_id=' . $merchant_order_id . ', token=' . substr($everypayToken, 0, 12) . '...');

            $order_info = $this->model_checkout_order->getOrder($merchant_order_id);
            $amount = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false) * 100;

            $success = false;
            $error = '';

            try {
                $phone = str_replace(['+', '-', ' '], null, $order_info['telephone']);
                $ch = $this->getCurlHandle($everypayToken, $amount, $order_info['email'], $phone, $merchant_order_id);

                $result = curl_exec($ch);
                $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if ($result === false) {
                    $success = false;
                    $error = 'Curl error: '.curl_error($ch);
                    $this->log->write('EVERYPAY: [PaymentCapture] CURL error - order_id=' . $merchant_order_id . ', error=' . $error);
                } else {
                    $response_array = json_decode($result, true);
                    if ($http_status === 200 and isset($response_array['error']) === false) {
                        $success = true;
                        $this->log->write('EVERYPAY: [PaymentCaptured] Legacy payment captured - order_id=' . $merchant_order_id . ', payment_id=' . (isset($response_array['token']) ? $response_array['token'] : 'unknown'));
                    } else {
                        $success = false;

                        if (!empty($response_array['error']['code'])) {
                            $error = $response_array['error']['code'].':'.$response_array['error']['message'];
                        } else {
                            $error = 'EVERYPAY_ERROR:Invalid Response <br/>'.$result;
                        }
                        $this->log->write('EVERYPAY: [PaymentCapture] Legacy payment failed - order_id=' . $merchant_order_id . ', http_status=' . $http_status . ', error=' . $error);
                    }
                }

                curl_close($ch);
            } catch (Exception $e) {
                $success = false;
                $error = 'OPENCART_ERROR:Request to EveryPay Failed';
                $this->log->write('EVERYPAY: [PaymentCapture] Exception - order_id=' . $merchant_order_id . ', exception=' . $e->getMessage());
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

                $this->log->write('EVERYPAY: [StatusUpdated] Legacy order completed - order_id=' . $merchant_order_id . ', status=' . $this->config->get('payment_everypay_order_status_id'));

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
                $this->log->write('EVERYPAY: [StatusUpdated] Legacy order failed - order_id=' . $merchant_order_id . ', status=10, error=' . $error);
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
            $this->log->write('EVERYPAY: [CallbackError] Legacy callback missing required parameters');
            echo 'An error occured. Contact site administrator, please!';
        }
    }

    public function createIrisSession()
    {
        $this->response->addHeader('Content-Type: application/json');
        if (!$this->config->get('payment_everypay_iris_enabled')) {
            $this->logIris('SessionRequest', 'IRIS disabled');
            $this->response->setOutput(json_encode(array('success' => false, 'message' => 'IRIS disabled')));
            return;
        }
        if ($this->request->server['REQUEST_METHOD'] !== 'POST' || empty($this->session->data['order_id'])) {
            $this->logIris('SessionRequest', 'Invalid request method or missing order ID');
            $this->response->setOutput(json_encode(array('success' => false, 'message' => 'Invalid request')));
            return;
        }
        $order_id = (int)$this->session->data['order_id'];
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);
        if (!$order_info) {
            $this->logIris('SessionRequest', 'Order not found', array('order_id' => $order_id));
            $this->response->setOutput(json_encode(array('success' => false, 'message' => 'Order not found')));
            return;
        }
        $this->ensureIrisSchema();
        $reference = $this->getIrisReference($order_id);
        if (!$reference) {
            $reference = $this->createIrisReference($order_id);
        }
        $country = isset($this->request->post['country']) ? $this->request->post['country'] : ($order_info['payment_iso_code_2'] ? $order_info['payment_iso_code_2'] : $order_info['shipping_iso_code_2']);
        if (!$country) {
            $country = 'GR';
        }
        $amount = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false) * 100;
        $uuid = isset($this->request->post['uuid']) ? $this->request->post['uuid'] : '';
        if (!$uuid && !empty($reference['uuid'])) {
            $uuid = $reference['uuid'];
        }
        if (!$uuid) {
            $uuid = bin2hex(random_bytes(16));
        }
        $payload = array(
            'amount' => $amount,
            'currency' => strtoupper($order_info['currency_code']),
            'country' => strtoupper($country),
            'uuid' => $uuid,
            'callback_url' => $this->url->link('extension/payment/everypay/irisCallback', '', true),
            'md' => $reference['md'],
        );
        $this->logIris('SessionRequest', 'Requesting IRIS session', array(
            'order_id' => $order_id,
            'md' => $reference['md'],
            'amount' => $amount,
            'currency' => strtoupper($order_info['currency_code']),
            'country' => strtoupper($country),
            'uuid' => $uuid
        ));
        $response = $this->requestIrisSession($payload);
        if ($response['success']) {
            $responseData = isset($response['data']) ? $response['data'] : array();
            if (empty($responseData)) {
                $responseData = array(
                    'signature' => isset($response['signature']) ? $response['signature'] : '',
                    'uuid' => isset($response['uuid']) ? $response['uuid'] : $payload['uuid'],
                );
            }
            $this->updateIrisReference($order_id, $reference['md'], isset($responseData['uuid']) ? $responseData['uuid'] : $payload['uuid']);
            $this->logIris('SessionCreated', 'IRIS session created successfully', array(
                'order_id' => $order_id,
                'md' => $reference['md'],
                'uuid' => isset($responseData['uuid']) ? $responseData['uuid'] : $payload['uuid']
            ));
            $this->response->setOutput(json_encode(array('success' => true, 'data' => $responseData)));
        } else {
            $this->logIris('SessionFailed', 'IRIS session creation failed', array(
                'order_id' => $order_id,
                'md' => $reference['md'],
                'error' => $response['message']
            ));
            $this->response->setOutput(json_encode(array('success' => false, 'message' => $response['message'])));
        }
    }

    public function irisCallback()
    {
        $this->language->load('extension/payment/everypay');
        if (!$this->config->get('payment_everypay_iris_enabled')) {
            $this->logIris('CallbackReceived', 'IRIS disabled');
            $this->response->setOutput(json_encode(array('success' => false, 'message' => 'IRIS disabled')));
            return;
        }
        if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
            $this->logIris('CallbackReceived', 'Browser redirect detected');
            $this->handleIrisBrowserRedirect();
            return;
        }
        $rawInput = file_get_contents('php://input');
        $jsonPayload = array();
        if ($rawInput !== false && $rawInput !== '') {
            $decoded = json_decode($rawInput, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $jsonPayload = $decoded;
            }
        }
        $isServerCallback = $this->isServerCallback($rawInput, $jsonPayload);
        $callbackType = $isServerCallback ? 'server' : 'browser';

        $this->logIris('CallbackReceived', 'Callback received', array('type' => $callbackType));

        if ($isServerCallback && !$this->verifyCallbackHash($jsonPayload)) {
            $this->logIris('HashVerification', 'Hash verification failed');
            $this->response->addHeader('HTTP/1.1 401 Unauthorized');
            $this->response->setOutput(json_encode(array('success' => false, 'message' => 'Hash verification failed')));
            return;
        }
        if ($this->isBrowserAgent()) {
            $isServerCallback = false;
        }
        $token = $this->getIrisRequestValue('token', $jsonPayload);
        $md = $this->getIrisRequestValue('md', $jsonPayload);

        $this->logIris('CallbackData', 'Callback data received', array(
            'token' => $token ? substr($token, 0, 12) . '...' : 'missing',
            'md' => $md ? $md : 'missing',
            'type' => $callbackType
        ));

        if (!$token || !$md) {
            $this->logIris('CallbackError', 'Missing token or MD');
            if ($isServerCallback) {
                $this->respondJson(array('success' => false, 'message' => 'Invalid payload'));
            } else {
                $this->handleIrisBrowserRedirect();
            }
            return;
        }
        $this->ensureIrisSchema();
        $this->db->query("START TRANSACTION");
        $reference = null;
        $lookupMethod = '';
        if ($token) {
            $reference = $this->getIrisReferenceByTokenLocked($token);
            if ($reference) {
                $lookupMethod = 'token';
                $this->logIris('OrderLookup', 'Order found by token', array(
                    'token' => substr($token, 0, 12) . '...',
                    'order_id' => $reference['order_id']
                ));
            }
        }
        if (!$reference && $md) {
            $reference = $this->getIrisReferenceByMdLocked($md);
            if ($reference) {
                $lookupMethod = 'md';
                $this->logIris('OrderLookup', 'Order found by MD (token not found)', array(
                    'md' => $md,
                    'order_id' => $reference['order_id']
                ));
            }
        }
        if (!$reference && !$isServerCallback) {
            $session_md = isset($this->session->data['iris_md']) ? $this->session->data['iris_md'] : null;
            if ($session_md) {
                $reference = $this->getIrisReferenceByMdLocked($session_md);
                if ($reference) {
                    $lookupMethod = 'session';
                    $this->logIris('OrderLookup', 'Order found by session (fallback)', array(
                        'md' => $session_md,
                        'order_id' => $reference['order_id']
                    ));
                }
            }
        }
        if (!$reference) {
            $this->logIris('OrderLookup', 'Reference not found', array(
                'token' => $token ? substr($token, 0, 12) . '...' : 'missing',
                'md' => $md ? $md : 'missing'
            ));
            $this->db->query("ROLLBACK");
            $this->response->setOutput(json_encode(array('success' => false, 'message' => 'Reference not found')));
            return;
        }

        $this->logIris('OrderLookup', 'Order reference found', array(
            'order_id' => $reference['order_id'],
            'lookup_method' => $lookupMethod,
            'status' => isset($reference['everypay_iris_status']) ? $reference['everypay_iris_status'] : 'unknown'
        ));

        if (isset($reference['everypay_iris_status']) && $reference['everypay_iris_status'] === 'captured') {
            $this->logIris('PaymentStatus', 'Payment already captured', array('order_id' => $reference['order_id']));
            $this->db->query("COMMIT");
            if ($isServerCallback) {
                $this->respondJson(array('success' => true, 'message' => 'Already processed'));
            } else {
                $this->handleIrisBrowserRedirect(true);
            }
            return;
        }
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($reference['order_id']);
        if (!$order_info) {
            $this->logIris('OrderLookup', 'Order not found in database', array('order_id' => $reference['order_id']));
            $this->db->query("ROLLBACK");
            $this->response->setOutput(json_encode(array('success' => false, 'message' => 'Order not found')));
            return;
        }
        $this->logIris('HashVerified', 'Hash verification successful', array('order_id' => $reference['order_id']));
        $result = $this->captureEverypayPayment($token, $order_info, $reference['order_id']);
        if ($result['success']) {
            if (!$order_info['order_status_id']) {
                $this->model_checkout_order->addOrderHistory(
                    $reference['order_id'],
                    $this->config->get('payment_everypay_order_status_id'),
                    'IRIS Payment Successful. EveryPay Payment Id:'.$result['token']
                );
            } else {
                $this->model_checkout_order->addOrderHistory(
                    $reference['order_id'],
                    $this->config->get('payment_everypay_order_status_id'),
                    'IRIS Payment Successful. EveryPay Payment Id:'.$result['token']
                );
            }
            $uuid = isset($reference['everypay_iris_token']) && $reference['everypay_iris_token'] ? $reference['everypay_iris_token'] : '';
            $this->updateIrisReference($reference['order_id'], $md, $uuid, 'captured');
            $this->logIris('StatusUpdated', 'Order status updated', array(
                'order_id' => $reference['order_id'],
                'status' => 'captured',
                'payment_id' => $result['token']
            ));
            $this->db->query("COMMIT");
            if ($isServerCallback) {
                $this->respondJson(array('success' => true));
            } else {
                $this->handleIrisBrowserRedirect(true);
            }
        } else {
            $this->logIris('PaymentFailed', 'Payment processing failed', array(
                'order_id' => $reference['order_id'],
                'error' => $result['error'] ?: 'Unknown error'
            ));
            $this->db->query("ROLLBACK");
            if ($isServerCallback) {
                $this->respondJson(array('success' => false, 'message' => $result['error'] ?: 'Unknown error'));
            } else {
                $this->handleIrisBrowserRedirect(false, $result['error']);
            }
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

        $ch = curl_init();

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

    private function captureEverypayPayment($token, $order_info, $order_id)
    {
        $amount = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false) * 100;
        $this->logIris('PaymentCapture', 'Starting payment capture', array(
            'order_id' => $order_id,
            'token' => substr($token, 0, 12) . '...',
            'amount' => $amount,
            'currency' => $order_info['currency_code']
        ));
        try {
            $phone = str_replace(['+', '-', ' '], null, $order_info['telephone']);
            $ch = $this->getCurlHandle($token, $amount, $order_info['email'], $phone, $order_id);
            $result = curl_exec($ch);
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($result === false) {
                $error = 'Curl error: '.curl_error($ch);
                $this->logIris('PaymentCapture', 'CURL error', array(
                    'order_id' => $order_id,
                    'error' => $error
                ));
                curl_close($ch);
                return array('success' => false, 'error' => $error);
            }
            $response_array = json_decode($result, true);
            curl_close($ch);
            if ($http_status === 200 && empty($response_array['error'])) {
                $this->logIris('PaymentCaptured', 'Payment captured successfully', array(
                    'order_id' => $order_id,
                    'payment_id' => isset($response_array['token']) ? $response_array['token'] : $token,
                    'http_status' => $http_status
                ));
                return array('success' => true, 'token' => isset($response_array['token']) ? $response_array['token'] : $token);
            }
            if (!empty($response_array['error']['code'])) {
                $errorMsg = $response_array['error']['code'].':'.$response_array['error']['message'];
                $this->logIris('PaymentCapture', 'Payment capture failed', array(
                    'order_id' => $order_id,
                    'http_status' => $http_status,
                    'error_code' => $response_array['error']['code'],
                    'error_message' => $response_array['error']['message']
                ));
                return array('success' => false, 'error' => $errorMsg);
            }
            $this->logIris('PaymentCapture', 'Invalid API response', array(
                'order_id' => $order_id,
                'http_status' => $http_status,
                'response' => substr($result, 0, 200)
            ));
            return array('success' => false, 'error' => 'EVERYPAY_ERROR:Invalid Response '.$result);
        } catch (Exception $e) {
            $this->logIris('PaymentCapture', 'Exception during payment capture', array(
                'order_id' => $order_id,
                'exception' => $e->getMessage()
            ));
            return array('success' => false, 'error' => 'OPENCART_ERROR:Request to EveryPay Failed');
        }
    }

    private function ensureIrisSchema()
    {
        $orderTable = DB_PREFIX.'order';
        $checkQuery = $this->db->query("SHOW COLUMNS FROM `".$orderTable."` LIKE 'everypay_iris_md'");
        if ($checkQuery->num_rows === 0) {
            $this->db->query("ALTER TABLE `".$orderTable."`
                ADD COLUMN `everypay_iris_md` VARCHAR(64) DEFAULT NULL,
                ADD COLUMN `everypay_iris_token` VARCHAR(128) DEFAULT NULL,
                ADD COLUMN `everypay_iris_status` VARCHAR(32) DEFAULT 'pending'");
            $indexCheck = $this->db->query("SHOW INDEX FROM `".$orderTable."` WHERE Key_name = 'everypay_iris_md_idx'");
            if ($indexCheck->num_rows === 0) {
                $this->db->query("ALTER TABLE `".$orderTable."` ADD UNIQUE KEY `everypay_iris_md_idx` (`everypay_iris_md`)");
            }
            $tokenIndexCheck = $this->db->query("SHOW INDEX FROM `".$orderTable."` WHERE Key_name = 'everypay_iris_token_idx'");
            if ($tokenIndexCheck->num_rows === 0) {
                $this->db->query("ALTER TABLE `".$orderTable."` ADD KEY `everypay_iris_token_idx` (`everypay_iris_token`)");
            }
            $legacyTable = DB_PREFIX.'everypay_iris';
            $legacyCheck = $this->db->query("SHOW TABLES LIKE '".$legacyTable."'");
            if ($legacyCheck->num_rows > 0) {
                $legacyData = $this->db->query("SELECT order_id, md, uuid, status FROM `".$legacyTable."`");
                if ($legacyData->num_rows > 0) {
                    foreach ($legacyData->rows as $row) {
                        $this->db->query("UPDATE `".$orderTable."`
                            SET everypay_iris_md = '".$this->db->escape($row['md'])."',
                                everypay_iris_token = '".$this->db->escape($row['uuid'])."',
                                everypay_iris_status = '".$this->db->escape($row['status'])."'
                            WHERE order_id = '".(int)$row['order_id']."'");
                    }
                }
            }
        } else {
            $tokenIndexCheck = $this->db->query("SHOW INDEX FROM `".$orderTable."` WHERE Key_name = 'everypay_iris_token_idx'");
            if ($tokenIndexCheck->num_rows === 0) {
                $this->db->query("ALTER TABLE `".$orderTable."` ADD KEY `everypay_iris_token_idx` (`everypay_iris_token`)");
            }
        }
    }

    private function getIrisReference($order_id)
    {
        $query = $this->db->query("SELECT order_id, everypay_iris_md as md, everypay_iris_token as uuid, everypay_iris_status as status
            FROM `".DB_PREFIX."order`
            WHERE order_id = '".(int)$order_id."'
            AND everypay_iris_md IS NOT NULL
            LIMIT 1");
        if ($query->num_rows) {
            return $query->row;
        }
        return null;
    }

    private function createIrisReference($order_id)
    {
        $md = bin2hex(random_bytes(16));
        $this->db->query("UPDATE `".DB_PREFIX."order`
            SET everypay_iris_md = '".$this->db->escape($md)."',
                everypay_iris_token = NULL,
                everypay_iris_status = 'pending'
            WHERE order_id = '".(int)$order_id."'");
        return array('md' => $md, 'uuid' => '');
    }

    private function updateIrisReference($order_id, $md, $uuid = '', $status = null)
    {
        $sql = "UPDATE `".DB_PREFIX."order` SET ";
        $parts = array();
        if ($uuid !== null && $uuid !== '') {
            $parts[] = "everypay_iris_token = '".$this->db->escape($uuid)."'";
        }
        if ($status !== null) {
            $parts[] = "everypay_iris_status = '".$this->db->escape($status)."'";
        }
        if (empty($parts)) {
            return;
        }
        $sql .= implode(', ', $parts);
        $sql .= " WHERE order_id = '".(int)$order_id."' AND everypay_iris_md = '".$this->db->escape($md)."'";
        $this->db->query($sql);
    }

    private function getIrisReferenceByMd($md)
    {
        $query = $this->db->query("SELECT order_id, everypay_iris_md, everypay_iris_token, everypay_iris_status
            FROM `".DB_PREFIX."order`
            WHERE everypay_iris_md = '".$this->db->escape($md)."'
            LIMIT 1");
        if ($query->num_rows) {
            return $query->row;
        }
        return null;
    }

    private function getIrisReferenceByMdLocked($md)
    {
        $query = $this->db->query("SELECT order_id, everypay_iris_md, everypay_iris_token, everypay_iris_status
            FROM `".DB_PREFIX."order`
            WHERE everypay_iris_md = '".$this->db->escape($md)."'
            LIMIT 1 FOR UPDATE");
        if ($query->num_rows) {
            return $query->row;
        }
        return null;
    }

    private function getIrisReferenceByToken($token)
    {
        if (empty($token)) {
            return null;
        }
        $query = $this->db->query("SELECT order_id, everypay_iris_md, everypay_iris_token, everypay_iris_status
            FROM `".DB_PREFIX."order`
            WHERE everypay_iris_token = '".$this->db->escape($token)."'
            LIMIT 1");
        if ($query->num_rows) {
            return $query->row;
        }
        return null;
    }

    private function getIrisReferenceByTokenLocked($token)
    {
        if (empty($token)) {
            return null;
        }
        $query = $this->db->query("SELECT order_id, everypay_iris_md, everypay_iris_token, everypay_iris_status
            FROM `".DB_PREFIX."order`
            WHERE everypay_iris_token = '".$this->db->escape($token)."'
            LIMIT 1 FOR UPDATE");
        if ($query->num_rows) {
            return $query->row;
        }
        return null;
    }

    private function requestIrisSession($payload)
    {
        $sandbox = $this->config->get('payment_everypay_sandbox');
        $url = $sandbox ? 'https://sandbox-api.everypay.gr/iris/sessions' : 'https://api.everypay.gr/iris/sessions';
        $secret_key = $this->config->get('payment_everypay_secret_key');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERPWD, $secret_key.':');
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $result = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($result === false) {
            $error = 'Curl error: '.curl_error($ch);
            $this->logIris('APIRequest', 'IRIS session API request failed', array(
                'error' => $error,
                'url' => $url
            ));
            curl_close($ch);
            return array('success' => false, 'message' => $error);
        }
        $response = json_decode($result, true);
        curl_close($ch);
        if ($http_status === 200) {
            if (isset($response['data']['signature'])) {
                $this->logIris('APIResponse', 'IRIS session API success', array('http_status' => $http_status));
                return array('success' => true, 'data' => $response['data']);
            }
            if (isset($response['signature'])) {
                $this->logIris('APIResponse', 'IRIS session API success', array('http_status' => $http_status));
                return array('success' => true, 'data' => $response);
            }
        }
        if (isset($response['error']['message'])) {
            $this->logIris('APIResponse', 'IRIS session API error', array(
                'http_status' => $http_status,
                'error' => $response['error']['message']
            ));
            return array('success' => false, 'message' => $response['error']['message']);
        }
        $this->logIris('APIResponse', 'Invalid IRIS session API response', array(
            'http_status' => $http_status,
            'response' => substr($result ?: 'empty', 0, 200)
        ));
        return array('success' => false, 'message' => 'Invalid IRIS response: '.($result ?: 'empty response'));
    }

    private function getIrisRequestValue($key, array $jsonPayload = array())
    {
        if (isset($this->request->post[$key]) && $this->request->post[$key] !== '') {
            return $this->request->post[$key];
        }
        if (isset($jsonPayload[$key]) && $jsonPayload[$key] !== '') {
            return $jsonPayload[$key];
        }
        if (isset($this->request->get[$key]) && $this->request->get[$key] !== '') {
            return $this->request->get[$key];
        }
        return '';
    }

    private function handleIrisBrowserRedirect($forceSuccess = false, $customMessage = null)
    {
        $token = $this->getIrisRequestValue('token');
        $md = $this->getIrisRequestValue('md');
        $failureMessage = $customMessage ? $customMessage : $this->language->get('text_iris_callback_error');
        $failureUrl = $this->url->link('checkout/checkout', '', true);
        $successUrl = $this->url->link('checkout/success', '', true);

        if ($forceSuccess) {
            unset($this->session->data['iris_callback_notice']);
            $this->renderIrisRedirectPage($successUrl);
            return;
        }

        $this->ensureIrisSchema();
        $reference = null;
        if ($token) {
            $reference = $this->getIrisReferenceByToken($token);
        }
        if (!$reference && $md) {
            $reference = $this->getIrisReferenceByMd($md);
        }
        if (!$reference) {
            $session_md = isset($this->session->data['iris_md']) ? $this->session->data['iris_md'] : null;
            if ($session_md) {
                $reference = $this->getIrisReferenceByMd($session_md);
            }
        }
        if ($reference && isset($reference['everypay_iris_status']) && $reference['everypay_iris_status'] === 'captured') {
            unset($this->session->data['iris_callback_notice']);
            $this->renderIrisRedirectPage($successUrl);
            return;
        }
        if ($reference && !empty($reference['order_id'])) {
            $this->load->model('checkout/order');
            $order_info = $this->model_checkout_order->getOrder((int)$reference['order_id']);
            if ($order_info && (int)$order_info['order_status_id'] === (int)$this->config->get('payment_everypay_order_status_id')) {
                unset($this->session->data['iris_callback_notice']);
                $this->renderIrisRedirectPage($successUrl);
                return;
            }
        }
        $this->session->data['iris_callback_notice'] = $failureMessage;
        $this->renderIrisRedirectPage($failureUrl, $failureMessage);
    }

    private function renderIrisRedirectPage($redirectUrl, $message = '')
    {
        $this->response->addHeader('Content-Type: text/html; charset=utf-8');
        $escapedUrl = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
        $output  = "<html>\n<head>\n<meta http-equiv=\"Refresh\" content=\"0; url=".$escapedUrl."\">\n</head>\n<body>\n";
        if ($message) {
            $output .= '<p>'.htmlspecialchars($message, ENT_QUOTES, 'UTF-8')."</p>\n";
        }
        $linkText = $this->language->get('text_iris_return');
        $output .= '<p><a href="'.$escapedUrl.'">'.htmlspecialchars($linkText, ENT_QUOTES, 'UTF-8')."</a></p>\n";
        $output .= "</body>\n</html>";
        $this->response->setOutput($output);
    }

    private function isServerCallback($rawInput, array $jsonPayload)
    {
        if (!empty($jsonPayload)) {
            return true;
        }
        if (!empty($this->request->post['hash'])) {
            return true;
        }
        $method = isset($this->request->server['REQUEST_METHOD']) ? strtoupper($this->request->server['REQUEST_METHOD']) : 'GET';
        if ($method !== 'POST') {
            return false;
        }
        $agent = isset($this->request->server['HTTP_USER_AGENT']) ? strtolower($this->request->server['HTTP_USER_AGENT']) : '';
        if (strpos($agent, 'everypay') !== false || strpos($agent, 'curl') !== false) {
            return true;
        }
        if (!empty($this->request->post['token']) || !empty($this->request->post['md'])) {
            return false;
        }
        if (!empty($rawInput)) {
            return true;
        }
        return false;
    }

    private function respondJson($payload)
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($payload));
    }

    private function isBrowserAgent()
    {
        $agent = isset($this->request->server['HTTP_USER_AGENT']) ? strtolower($this->request->server['HTTP_USER_AGENT']) : '';
        if (!$agent) {
            return false;
        }
        if (strpos($agent, 'mozilla') !== false || strpos($agent, 'chrome') !== false || strpos($agent, 'safari') !== false || strpos($agent, 'edg') !== false) {
            return true;
        }
        return false;
    }

    private function verifyCallbackHash(array $jsonPayload)
    {
        $hashRaw = '';
        if (isset($this->request->post['hash'])) {
            $hashRaw = $this->request->post['hash'];
        } elseif (isset($jsonPayload['hash'])) {
            $hashRaw = $jsonPayload['hash'];
        }

        if (empty($hashRaw)) {
            return false;
        }

        $secretKey = $this->config->get('payment_everypay_secret_key');
        if (empty($secretKey)) {
            return false;
        }

        $decodedHash = base64_decode($hashRaw, true);
        if ($decodedHash === false || strpos($decodedHash, '|') === false) {
            return false;
        }

        $parts = explode('|', $decodedHash, 2);
        if (count($parts) !== 2) {
            return false;
        }

        list($providedHash, $payloadJson) = $parts;

        $calculatedHash = hash_hmac('sha256', $payloadJson, $secretKey);

        return hash_equals($calculatedHash, $providedHash);
    }
}
