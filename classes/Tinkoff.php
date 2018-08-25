<?php namespace Xeor\TinkoffOctoCart\Classes;

use Log;
use Config;
use Redirect;
use Carbon\Carbon;
use Xeor\OctoCart\Models\Product;
use Xeor\OctoCart\Models\Settings;

/**
 * Class Tinkoff
 */
class Tinkoff
{

    private $order;
    private $merchantId;
    private $secretKey;

    /**
     * Constructor
     *
     * @param string $terminalKey Your Terminal name
     * @param string $secretKey   Secret key for terminal
     * @param string $api_url     Url for API
     */
    public function __construct($order)
    {
        $this->order = $order;
        $this->merchantId = Config::get('xeor.tinkoffoctocart::merchantId', '');
        $this->secretKey = Config::get('xeor.tinkoffoctocart::secretKey', '');
    }

    public function getPaymentUrl()
    {
        $arrFields = $this->getReceipt();

        $Tinkoff = new TinkoffMerchantAPI($this->merchantId, $this->secretKey);

        $request = $Tinkoff->buildQuery('Init', $arrFields);
        $request = json_decode($request);

        $redirectUrl = isset($request->PaymentURL) ? $request->PaymentURL : $this->getErrorURL();
        return $redirectUrl;
    }

    /**
     * @return array
     */
    protected function getReceipt()
    {

        if (!$this->order)
            return [];

        $description = $this->getDescription();

        $arrFields = [
            'OrderId' => $this->order->id,
            'Amount' => round($this->order->getTotal() * 100),
            'Description' => $description,
            'DATA' => ['Email' => $this->order->getBillingEmail(), 'Connection_type' => 'octocart',],
        ];

        $checkDataTax = Config::get('xeor.tinkoffoctocart::checkDataTax', 0);

        if ($checkDataTax) {
            $taxation = Config::get('xeor.tinkoffoctocart::taxation', 'error');
            $arrFields['Receipt'] = [
                'Email' => $this->order->getBillingEmail(),
                'Phone' => $this->order->getBillingPhone(),
                'Taxation' => $taxation,
                'Items' => $this->getReceiptItems(),
            ];
        }

        return $arrFields;
    }

    /**
     * @return string
     */
    protected function getDescription()
    {
        return '';
    }

    /**
     * @return array
     */
    protected function getReceiptItems()
    {
        $receiptItems = [];

        $items = $this->order->getItems();
        if ($items && is_array($items) && !empty($items)) {
            foreach ($items as $item) {

                $productId = $item['product'];
                $product = Product::find($productId);
                $quantity = $item['quantity'];
                $price = $item['price'];

                $settings = Settings::instance();
                $vatIsEnabled = $settings->vat_state;
                $vat = $vatIsEnabled ? (float)$settings->vat_value : 'none';

                $newReceiptItem = [
                    'Name' => mb_substr($product->title, 0, 64),
                    'Price' => round($price * 100),
                    'Quantity' => round($quantity, 2),
                    'Amount' => round($price * $quantity * 100),
                    'Tax' => $vat,
                ];

                array_push($receiptItems, $newReceiptItem);
            }
        }

        $shippingPrice = $this->order->getShippingTotal();
        $isShipping = false;
        if ($shippingPrice > 0) {
            $shippingPriceTax = round($this->order->getShippingTax() * 100);
            $shippingPrice = round($shippingPrice * 100);
            $shippingPriceTax += $shippingPrice;

            $shippingTax = 'none'; //TODO Add shipping tax

            $shippingItem = array(
                'Name' => mb_substr($this->order->getShippingMethodName(), 0, 64),
                'Price' => $shippingPriceTax,
                'Quantity' => 1,
                'Amount' => $shippingPriceTax,
                'Tax' => $shippingTax,
            );
            array_push($items, $shippingItem);
            $isShipping = true;
        }

        $amount = round($this->order->getTotal() * 100);

        return $this->balanceAmount($isShipping, $receiptItems, $amount);
    }

    /**
     * @return array
     */
    protected function balanceAmount($isShipping, $items, $amount)
    {
        $itemsWithoutShipping = $items;

        if ($isShipping) {
            $shipping = array_pop($itemsWithoutShipping);
        }

        $sum = 0;

        foreach ($itemsWithoutShipping as $item) {
            $sum += $item['Amount'];
        }

        if (isset($shipping)) {
            $sum += $shipping['Amount'];
        }

        if ($sum != $amount) {
            $sumAmountNew = 0;
            $difference = $amount - $sum;
            $amountNews = [];

            foreach ($itemsWithoutShipping as $key => $item) {
                $itemsAmountNew = $item['Amount'] + floor($difference * $item['Amount'] / $sum);
                $amountNews[$key] = $itemsAmountNew;
                $sumAmountNew += $itemsAmountNew;
            }

            if (isset($shipping)) {
                $sumAmountNew += $shipping['Amount'];
            }

            if ($sumAmountNew != $amount) {
                $max_key = array_keys($amountNews, max($amountNews))[0];    // ключ макс значения
                $amountNews[$max_key] = max($amountNews) + ($amount - $sumAmountNew);
            }

            foreach ($amountNews as $key => $item) {
                $items[$key]['Amount'] = $amountNews[$key];
            }
        }
        return $items;
    }

    /**
     * @return string
     */
    public function notify($params)
    {
        $result = 'NOTOK';
        $params['Password'] = $this->secretKey;
        ksort($params);

        $paymentResponse = json_encode($params);
        $paymentId = isset($params['PaymentId']) ? $params['PaymentId'] : '';

        $original_token = $params['Token'];
        unset($params['Token']);

        $params['Success'] = $params['Success'] === true ? 'true' : 'false';

        $values = '';
        foreach ($params as $key => $val) {
            $values .= $val;
        }
        $token = hash('sha256', $values);

        if ($token == $original_token) {
            $orderStatus = $this->order->status;
            if ($params['Status'] == 'AUTHORIZED' && $orderStatus == 'pending') {
                $orderStatus = 'on-hold';
            }
            switch ($params['Status']) {
                case 'AUTHORIZED':
                    $orderStatus = 'on-hold';
                    break; /*Деньги на карте захолдированы. Корзина очищается.*/
                case 'CONFIRMED':
                    $orderStatus = 'processing';
                    break; /*Платеж подтвержден.*/
                case 'CANCELED':
                    $orderStatus = 'cancelled';
                    break; /*Платеж отменен*/
                case 'REJECTED':
                    $orderStatus = 'failed';
                    break; /*Платеж отклонен.*/
                case 'REVERSED':
                    $orderStatus = 'cancelled';
                    break; /*Платеж отменен*/
                case 'REFUNDED':
                    $orderStatus = 'refunded';
                    break; /*Произведен возврат денег клиенту*/
            }
            if ($params['Status'] === 'CONFIRMED') {
                $orderStatus = 'completed';
            }

            $this->order->transaction_id = $paymentId;
            $this->order->status = $orderStatus;
            $this->order->payment_response = $paymentResponse;
            $this->order->date_paid = Carbon::now();
            $this->order->save();

            $result = 'OK';
        }

        return $result;
    }
}