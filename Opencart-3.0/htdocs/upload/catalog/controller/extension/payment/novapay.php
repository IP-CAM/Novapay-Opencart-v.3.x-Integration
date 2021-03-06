<?php

use Novapay\Payment\SDK\Schema\Callback;
use Novapay\Payment\SDK\Schema\Client;
use Novapay\Payment\SDK\Schema\Metadata;
use Novapay\Payment\SDK\Schema\Product;
use Novapay\Payment\SDK\Model\Model;
use Novapay\Payment\SDK\Model\Session;
use Novapay\Payment\SDK\Model\Payment;
use Novapay\Payment\SDK\Logger;
use Novapay\Payment\SDK\Model\Postback;
use Novapay\Payment\SDK\Schema\Delivery as DeliverySchema;
use Novapay\Payment\SDK\Model\Delivery;
use Novapay\Payment\SDK\Model\Delivery\Cities;
use Novapay\Payment\SDK\Model\Delivery\Warehouses;
use Novapay\Payment\SDK\Model\Delivery\WarehouseTypes;

require_once $_SERVER["DOCUMENT_ROOT"] . '/novapay-sdk/bootstrap.php';

function jsonExit($data)
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

class ControllerExtensionPaymentNovapay extends Controller
{
    const API_VERSION = 2;

    public function updateOrder()
    {
        if($this->session->data['user_token'] == $_POST['token']) {
            $this->load->language('extension/payment/novapay');
            $butt = strval($_POST['button_name']);
            $orderId = intval($_POST['order_id']);
            $orders = $this->db->query("SELECT * FROM " . DB_PREFIX . "novapay WHERE order_id = '" . $orderId . "' LIMIT 1");

            $order_statuses = $this->db->query("SELECT * FROM " . DB_PREFIX . "order WHERE order_id = '" . $orderId . "'");
            $order_status = $order_statuses->row['order_status_id'];

            $merchant_id = $this->config->get('payment_novapay_merchantid');

            $this->load->model('checkout/order');
            $error = '';

            $this->initPaymentModel();

            $session = new Session();
            $session->id = $orders->row['session_id'];

        if ($butt == 'cancel') {
            $order_oc = $this->db->query("SELECT * FROM " . DB_PREFIX . "order WHERE order_id = '" . $orderId . "' LIMIT 1");
            $dt = new DateTime($order_oc->row['date_added']);
            $dt2 = new DateTime();
            $this->model_checkout_order->addOrderHistory($orderId, $this->config->get('payment_novapay_processing_void_status_id'));
            $payment = new Payment();
            $ok = $payment->cancel($merchant_id, $session->id);
            if (!$ok) {
                $error = $payment->getResponse()->message ? $payment->getResponse()->message : $this->language->get['cancel_payment'];
            }
        } else if ($butt == 'update') {
            $ok = $session->status($merchant_id);
            try {
                $status = $this->setChose($session->getResponse()->status);
            } catch(Exception $e) {
                return;
            }
            if(empty($status)) {
                return;
            }
            if ($order_status != $status) {
                $this->model_checkout_order->addOrderHistory($orderId, $status);
            }
        } else if ($butt == 'confirm') {
            if ($orders->row['payment_type'] == '2') {
                $payment = new Payment();
                $ok = $payment->complete(
                    $merchant_id,
                    $session->id,
                    floatval($_POST['amount'])
                );
                if (!$ok) {
                    $error = $payment->getResponse()->message ? $payment->getResponse()->message : $this->language->get['confirm_hold_failed'];
                } else if ($order_status != 5) {
                    $this->model_checkout_order->addOrderHistory($orderId, $this->config->get('payment_novapay_processing_hold_completion_status_id'));
                }
            } else {
                $error = $this->language->get('payment_type_error');
            }
        } else if ($butt == 'hold_pdf') {
            $delivery = new Delivery();
            $res = $delivery->confirm($orders->row['session_id']);
            if ($res) {
                $resp = $delivery->getResponse();
                $this->db->query("UPDATE " . DB_PREFIX . "novapay SET invoice = '" . $resp->express_waybill . "' WHERE session_id = '" . $session->id . "'");
            }
        } else {
            $delivery = new Delivery();
            $resWaybill = $delivery->waybill($orders->row['session_id']);
            if ($resWaybill) {
                header('Content-Type: application/pdf');
                echo $delivery->pdfContent;
                exit;
            } else $error = $this->language->get('pdf_type_error');
        }
        if ($error !== '') {
            $this->response->redirect($_SERVER["HTTP_REFERER"]  . '&error=' . urlencode($error));
        } else {
            $this->response->redirect($_SERVER["HTTP_REFERER"]);
        }
      }
    }

    public function setChose($status)
    {
        $statuses = array(
            'created' => $this->config->get('payment_novapay_created_status_id'),
            'expired' => $this->config->get('payment_novapay_expired_status_id'),
            'processing' => $this->config->get('payment_novapay_processing_status_id'),
            'holded' => $this->config->get('payment_novapay_holded_status_id'),
            'hold_confirmed' => $this->config->get('payment_novapay_hold_confirmed_status_id'),
            'processing_hold_completion' => $this->config->get('payment_novapay_processing_hold_completion_status_id'),
            'paid' => $this->config->get('payment_novapay_paid_status_id'),
            'failed' => $this->config->get('payment_novapay_failed_status_id'),
            'processing_void' => $this->config->get('payment_novapay_processing_void_status_id'),
            'voided' => $this->config->get('payment_novapay_voided_status_id'),
        );
        if(isset($statuses[$status])) {
            return $statuses[$status];
        }
        return null;
    }

    public function setChoseRev($status)
    {
        $statuses = array(
            $this->config->get('payment_novapay_created_status_id') => 'created',
            $this->config->get('payment_novapay_expired_status_id') => 'expired',
            $this->config->get('payment_novapay_processing_status_id') => 'processing',
            $this->config->get('payment_novapay_holded_status_id') => 'holded',
            $this->config->get('payment_novapay_hold_confirmed_status_id') => 'hold_confirmed',
            $this->config->get('payment_novapay_processing_hold_completion_status_id') => 'processing_hold_completion',
            $this->config->get('payment_novapay_paid_status_id') => 'paid',
            $this->config->get('payment_novapay_failed_status_id') => 'failed',
            $this->config->get('payment_novapay_processing_void_status_id') => 'processing_void',
            $this->config->get('payment_novapay_voided_status_id') => 'voided',
        );
        if(isset($statuses[$status])) {
            return $statuses[$status];
        }
        return null;
    }

    public function index()
    {
        $this->language->load('extension/payment/novapay');

        //$data['action'] = $this->getRealLink('checkout/success', '', 'SSL');
        $data['action'] = $this->getRealLink('extension/payment/novapay/createOrder', '', 'SSL');
        $data['token'] = 'test';

        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['token_error'] = $this->language->get('token_error');
        $data['order_id'] = $this->session->data['order_id'];

        return $this->load->view('extension/payment/novapay', $data);
    }

    /**
     * Returns array of allowing shipping actions used in shippingPlace() method.
     *
     * @return string[]
     */
    protected function getAllowedShippingActions()
    {
        return [
            'citiesAction',
            'warehouseAction',
            'resAction'
        ];
    }

    /**
     * Prints out the cities response from the API.
     *
     * @return string
     */
    protected function citiesAction()
    {
        $cities = new Cities(2);
        $cities->search(urldecode($_POST['city']));
        echo json_encode($cities->items);
        exit;
    }

    /**
     * Prints out the warehouses response from the API.
     *
     * @return string
     */
    protected function warehouseAction()
    {
        $types = new WarehouseTypes();
        $result = $types->getByCityRef(urldecode($_POST['city']));
        if (!$result) {
            // @todo handle error message
            echo '';
            exit;
        }
        Warehouses::setTypes(urldecode($_POST['city']), $types->items);
        $houses = new Warehouses();
        $result = $houses->all(urldecode($_POST['city']));
        if (!$result) {
            // @todo handle error message
            echo '';
            exit;
        }
        echo json_encode($houses->items);
        exit;
    }

    /**
     * Prints out the calculation response from the API.
     *
     * @return string
     */
    protected function resAction()
    {
        $deliveryArray = $this->getDeliveryInfo();

        if (empty($deliveryArray['weight']) || empty($deliveryArray['volume'])) {
            // @todo handle the error
            echo json_encode('empty');
            return;
        }

        $delivery = new Delivery();
        $resDelivery = $delivery->price(
            $deliveryArray['total'],
            $deliveryArray['volume'],
            $deliveryArray['weight'],
            $_POST['city_ref'],
            $_POST['house_ref']
        );
        $this->session->data['city_ref'] = $_POST['city_ref'];
        $this->session->data['house_ref'] = $_POST['house_ref'];
        if (!$resDelivery) {
            // @todo handle the error
            echo json_encode($delivery->getResponse());
            exit;
        }

        $response = $delivery->getResponse();
        if (empty($response->delivery_price)) {
            echo json_encode(['error' => $this->language->get('depart_error')]);
            exit;
        }

        $this->session->data['price_shipping_novapay'] = $response->delivery_price;
        echo json_encode($response);
        exit;
    }

    /**
     * Prints out the result of the search/calculate shipping cost.
     *
     * @return string
     */
    public function shippingPlace()
    {
        $this->load->language('extension/payment/novapay');

        $this->initPaymentModel();

        $method = $_POST['type'] . 'Action';
        if (in_array($method, $this->getAllowedShippingActions())) {
            return call_user_func([$this, $method]);
        }
        if (!empty($this->session->data['price_shipping_novapay'])) {
            $this->session->data['shipping_method']['cost'] = $this->session->data['price_shipping_novapay'];
        }
        echo $this->session->data['shipping_method']['cost'];

    }

    /**
     * Returns shipping delivery info {weight, volume, total}
     *
     * @return array
     */
    private function getDeliveryInfo()
    {
        $this->load->model('extension/shipping/novapay');

        return $this->model_extension_shipping_novapay->getShippingInfo();
    }

    private function transliterateen($input)
    {
        $gost = array(
            "a" => "а", "b" => "б", "v" => "в", "g" => "г", "d" => "д", "e" => "е", "yo" => "ё",
            "j" => "ж", "z" => "з", "i" => "и", "i" => "й", "k" => "к",
            "l" => "л", "m" => "м", "n" => "н", "o" => "о", "p" => "п", "r" => "р", "s" => "с", "t" => "т",
            "y" => "у", "f" => "ф", "h" => "х", "c" => "ц",
            "ch" => "ч", "sh" => "ш", "sh" => "щ", "i" => "ы", "e" => "е", "u" => "у", "ya" => "я", "A" => "А", "B" => "Б",
            "V" => "В", "G" => "Г", "D" => "Д", "E" => "Е", "Yo" => "Ё", "J" => "Ж", "Z" => "З", "I" => "И", "I" => "Й", "K" => "К", "L" => "Л", "M" => "М",
            "N" => "Н", "O" => "О", "P" => "П",
            "R" => "Р", "S" => "С", "T" => "Т", "Y" => "Ю", "F" => "Ф", "H" => "Х", "C" => "Ц", "Ch" => "Ч", "Sh" => "Ш",
            "Sh" => "Щ", "I" => "Ы", "E" => "Е", "U" => "У", "Ya" => "Я", "'" => "ь", "'" => "Ь", "''" => "ъ", "''" => "Ъ", "j" => "ї", "i" => "и", "g" => "ґ",
            "ye" => "є", "J" => "Ї", "I" => "І",
            "G" => "Ґ", "YE" => "Є"
        );
        return strtr($input, $gost);
    }

    public function createOrder()
    {
        $this->load->language('extension/payment/novapay');

        $orderId = $this->session->data['order_id'];

        $this->load->model('checkout/order');

        $meta = new Metadata(
            [
                'order_id' => $orderId,
            ]
        );
        $postback = urldecode($this->getRealLink('extension/payment/novapay/postBack', '', 'SSL'));
        $success_url = $this->config->get('payment_novapay_successurl') ? $this->config->get('payment_novapay_successurl') : ($_SERVER['HTTPS'] ? 'https://' : 'http://') . $_SERVER['SERVER_NAME'] . '/index.php?route=extension/payment/novapay/success';
        $fail_url = $this->config->get('payment_novapay_failurl') ? $this->config->get('payment_novapay_failurl') : ($_SERVER['HTTPS'] ? 'https://' : 'http://') . $_SERVER['SERVER_NAME'] .  '/index.php?route=extension/payment/novapay/failed';
        $merchant_id = $this->config->get('payment_novapay_merchantid');
        $payment_type = $this->config->get('payment_novapay_payment_type_value');

        $order_info = $this->model_checkout_order->getOrder($orderId);

        if (strpos($order_info['telephone'], '+380') === false) {
            $this->session->data['error'] = $this->language->get('phone_error');
            header("Location: " . $_SERVER["HTTP_REFERER"] . '&error=phone');
            return;
        }
        if ($order_info['payment_iso_code_2'] !== 'UA') {
            $this->session->data['error'] = $this->language->get('country_error');
            header("Location: " . $_SERVER["HTTP_REFERER"] . '&error=country');
            return;
        }

        $this->load->model('account/order');
        $products = $this->model_account_order->getOrderProducts($orderId);

        // Totals
        $this->load->model('setting/extension');

        $totals = array();
        $taxes = $this->cart->getTaxes();
        $total = 0;

        // Because __call can not keep var references so we put them into an array.
        $total_data = array(
            'totals' => &$totals,
            'taxes'  => &$taxes,
            'total'  => &$total
        );

        $results = $this->model_setting_extension->getExtensions('total');

        foreach ($results as $key => $value) {
            $sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
        }

        array_multisort($sort_order, SORT_ASC, $results);

        foreach ($results as $result) {
            if ($this->config->get('total_' . $result['code'] . '_status')) {
                $this->load->model('extension/total/' . $result['code']);

                // We have to put the totals in an array so that they pass by reference.
                $this->{'model_extension_total_' . $result['code']}->getTotal($total_data);
            }
        }

        $items = [];
        $weight = 0;
        $amount = 0;
        foreach ($products as $item) {
            $items[] = new Product($item['name'], $item['price'], intval($item['total']) / intval($item['price']));
            $amount += $item['total'];
            $weight += 1;
        }

        foreach ($total_data['totals'] as $total) {
            if (floatval($total['value']) > 0 && stripos($total['title'], 'Total') === false) {
                if ($order_info['shipping_method'] !== 'Novapay Shipping' && $total['code'] == 'shipping') {
                    $items[] = new Product($total['title'], floatval($total['value']), 1);
                } else if ($total['code'] != 'shipping') {
                    $items[] = new Product($total['title'], floatval($total['value']), 1);
                }
            }
        }

        $client = new Client(
            strlen($order_info['payment_firstname']) > 0 ? $this->transliterateen($order_info['payment_firstname']) : null,
            strlen($order_info['payment_lastname']) > 0 ? $this->transliterateen($order_info['payment_lastname']) : null,
            '',
            strlen($order_info['telephone']) > 0 ? $order_info['telephone'] : null,
            strlen($order_info['email']) > 0 ? $order_info['email'] : null
        );

        $this->initPaymentModel();

        $callback = new Callback($postback, $success_url, $fail_url);

        $session = new Session();

        $ok = $session->create($merchant_id, $client, $meta, $callback);
        if (!$ok) {
            $this->session->data['error'] = $session->getResponse()->message;
            header("Location: " . $_SERVER["HTTP_REFERER"]);
            return;
        }

        $this->model_checkout_order->addOrderHistory(
            $orderId,
            $this->config->get('payment_novapay_created_status_id')
        );
        $this->db->query("INSERT INTO " . DB_PREFIX . "novapay SET order_id = '" . $orderId . "', session_id = '" . $session->id . "', payment_type = '" . $payment_type . "'");

        $delivery = null;
        if ($this->isShippingNovapay($order_info)) {
            $deliveryArray = $this->getDeliveryInfo();
            $delivery = new DeliverySchema(
                $deliveryArray['weight'],
                $deliveryArray['volume'],
                $this->session->data['city_ref'],
                $this->session->data['house_ref']
            );
            $order_info['total'] -= $this->session->data['price_shipping_novapay'];
        }

        // MUST follow after $this->getDeliveryInfo()
        $this->cart->clear();

        $payment = new Payment();
        $ok = $payment->create(
            $merchant_id,
            $session->id,
            $this->_getOrderRowsForApi($products, $order_info),
            round($order_info['total'], 2),
            $payment_type == 2,
            $orderId,
            $delivery
        );

        if ($ok) {
            header("Location: " . $payment->url);
        } else {
            $this->session->data['error'] = $payment->getResponse()->message ? $payment->getResponse()->message : $this->language->get['cancel_payment'];
            header("Location: " . $_SERVER["HTTP_REFERER"]);
            return;
        }
    }

    protected function isShippingNovapay($order_info)
    {
        return $order_info['shipping_method'] == 'Novapay Shipping';
    }

    /**
     * Initializes SDK model to work with the payment.
     *
     * @return void
     */
    protected function initPaymentModel() {
        if (intval($this->config->get('payment_novapay_test_mode'))) {
            Model::disableLiveMode();
        } else {
            Model::enableLiveMode();
        }
        Model::setPassword($this->config->get('payment_novapay_passprivate'));
        Model::setPrivateKey($this->config->get('payment_novapay_privatekey'));
        Model::setPublicKey($this->config->get('payment_novapay_publickey'));
        Model::setMerchantId($this->config->get('payment_novapay_merchantid'));

        $this->logRequest("Test mode: " . intval($this->config->get('payment_novapay_test_mode')));
    }

    public function postBack()
    {
        $this->initPaymentModel();

        $postback = new Postback(
            file_get_contents('php://input'), // data
            apache_request_headers(),         // headers
            isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET',
            isset($_SERVER['REDIRECT_STATUS']) ? $_SERVER['REDIRECT_STATUS'] : 200,
            isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1'
        );

        $postbackPostRequest = $postback->getRequest();

        $this->logRequest($postbackPostRequest);

        if (!$postback->verify()) {
            $this->load->model('checkout/order');
            return false;
        }

        $orderId = $postbackPostRequest->metadata->order_id;

        $order_statuses = $this->db->query("SELECT * FROM " . DB_PREFIX . "order WHERE order_id = '" . $orderId . "'");
        $order_status = $order_statuses->row['order_status_id'];
        $status = $this->setChoseRev($order_status);

        if ($postbackPostRequest->status != $status) {
            $this->load->model('checkout/order');
            $this->model_checkout_order->addOrderHistory($orderId, $this->setChose($postbackPostRequest->status));
        }
    }

    /**
     * Logs requests (postback from the begining).
     *
     * @param mixed $request Request object.
     *
     * @return void
     */
    protected function logRequest($request)
    {
        $log = new Log('novapay-postback.log');
        $log->write(json_encode($request) . "\n\n");
    }

    private function _language($lang_id)
    {
        $lang = substr($lang_id, 0, 2);
        $lang = strtolower($lang);
        return $lang;
    }

    public function success()
    {
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');
        $this->response->setOutput($this->load->view('extension/payment/novapay_success', $data));
    }

    public function failed()
    {
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');
        $this->response->setOutput($this->load->view('extension/payment/novapay_failed', $data));
    }

    /**
     * Returns proper URL for controllers.
     *
     * @param string $route  Route URI.
     * @param string $args   Query arguments.
     * @param bool   $secure Is secure?
     *
     * @return string        The URL.
     */
    protected function getRealLink($route, $args = '', $secure = false)
    {
        return urldecode($this->url->link($route, $args, $secure));
    }

    /**
     * Returns rows required for Payment API.
     *
     * @param array $products Array of products in order.
     *
     * @return array
     */
    private function _getOrderRowsForApi($products, $order_info)
    {
        $items = [];
        if (is_array($products)) {
            foreach ($products as $item) {
                if (floatval($item['price']) <= 0) {
                    continue;
                }
                $quantity = intval($item['total']) / intval($item['price']);
                $product = new Product(
                    $item['name'],
                    $item['price'],
                    $quantity
                );
                if ($product->isZero()) {
                    continue;
                }
                $items[] = $product;
            }
        }

        if (!$this->isShippingNovapay($order_info)) {
            $shipping = new Product(
                $this->session->data['shipping_method']['title'],
                $this->session->data['shipping_method']['cost'],
                1
            );
            if (!$shipping->isZero()) {
                $items[] = $shipping;
            }
        }

        return $items;
    }
}
