<?php

class ControllerExtensionPaymentEverypay extends Controller
{
    private $error = array();

    public function index()
    {
       
        $this->load->language('extension/payment/everypay');

        $this->document->setTitle($this->language->get('heading_title'));
        
        $this->load->model('setting/setting');

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('payment_everypay', $this->request->post);

			$this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('marketplace/extension', 'type=payment&user_token=' . $this->session->data['user_token'], true));
		}

        $data['payment_entry_public_key'] = $this->language->get('payment_entry_public_key');
        $data['payment_entry_secret_key'] = $this->language->get('payment_entry_secret_key');
        $data['payment_entry_order_status'] = $this->language->get('payment_entry_order_status');
        $data['payment_entry_status'] = $this->language->get('payment_entry_status');
        $data['payment_entry_sort_order'] = $this->language->get('payment_entry_sort_order');
        $data['payment_entry_sandbox'] = $this->language->get('payment_entry_sandbox');
        $data['payment_entry_iris'] = $this->language->get('payment_entry_iris');

        $data['button_save'] = $this->language->get('button_save');
        $data['button_cancel'] = $this->language->get('button_cancel');

      
        $data['user_token'] = $this->session->data['user_token'];

        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        if (isset($this->error['payment_everypay_public_key'])) {
            $data['payment_error_public_key'] = $this->error['payment_everypay_public_key'];
        } else {
            $data['payment_error_public_key'] = '';
        }

        if (isset($this->error['payment_everypay_key_secret'])) {
            $data['payment_error_secret_key'] = $this->error['payment_everypay_secret_key'];
        } else {
            $data['payment_error_secret_key'] = '';
        }

       
		$data['breadcrumbs'] = array();

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $data['user_token'], true),
            'separator' => false
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_payment'),
            'href' => $this->url->link('marketplace/extension', 'type=payment&user_token=' . $data['user_token'], true),
            'separator' => ' :: '
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('payment/everypay', 'user_token=' . $data['user_token'], true),
            'separator' => ' :: '
        );

		$data['action'] = $this->url->link('extension/payment/everypay', 'user_token=' . $data['user_token'], true);

        $data['cancel'] = $this->url->link('marketplace/extension', 'type=payment&user_token=' . $data['user_token'], true);
        
        if (isset($this->request->post['payment_everypay_public_key'])) {
            $data['payment_everypay_public_key'] = $this->request->post['payment_everypay_public_key'];
        } else {
            $data['payment_everypay_public_key'] = $this->config->get('payment_everypay_public_key');
        }

        if (isset($this->request->post['payment_everypay_secret_key'])) {
            $data['payment_everypay_secret_key'] = $this->request->post['payment_everypay_secret_key'];
        } else {
            $data['payment_everypay_secret_key'] = $this->config->get('payment_everypay_secret_key');
        }

        if (isset($this->request->post['payment_everypay_order_status_id'])) {
            $data['payment_everypay_order_status_id'] = $this->request->post['payment_everypay_order_status_id'];
        } else {
            $data['payment_everypay_order_status_id'] = $this->config->get('payment_everypay_order_status_id');
        }

      
        if (isset($this->request->post['payment_everypay_sandbox'])) {
            $data['payment_everypay_sandbox'] = $this->request->post['payment_everypay_sandbox'];
        } else {
            $data['payment_everypay_sandbox'] = $this->config->get('payment_everypay_sandbox');
        }

        if (isset($this->request->post['payment_everypay_iris_enabled'])) {
            $data['payment_everypay_iris_enabled'] = $this->request->post['payment_everypay_iris_enabled'];
        } else {
            $data['payment_everypay_iris_enabled'] = $this->config->get('payment_everypay_iris_enabled');
        }

        $this->load->model('localisation/order_status');

        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        if (isset($this->request->post['payment_everypay_status'])) {
            $data['payment_everypay_status'] = $this->request->post['payment_everypay_status'];
        } else {
            $data['payment_everypay_status'] = $this->config->get('payment_everypay_status');
        }

        if (isset($this->request->post['payment_everypay_sort_order'])) {
            $data['payment_everypay_sort_order'] = $this->request->post['payment_everypay_sort_order'];
        } else {
            $data['payment_everypay_sort_order'] = $this->config->get('payment_everypay_sort_order');
        }

      
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/everypay', $data));
    }

    protected function validate()
    {
        $this->load->language('extension/payment/everypay');

		if (!$this->user->hasPermission('modify', 'extension/payment/everypay')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}
		
        if (empty($this->request->post['payment_everypay_public_key'])) {
            $this->error['payment_everypay_public_key'] = $this->language->get('payment_error_public_key');
        }

        if (empty($this->request->post['payment_everypay_secret_key'])) {
            $this->error['payment_everypay_secret_key'] = $this->language->get('payment_error_secret_key');
        }

       
        return !$this->error;

    }

}
