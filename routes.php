<?php

use Xeor\OctoCart\Models\Order;
use Xeor\TinkoffOctoCart\Classes\Tinkoff;

Route::post('tinkoff/notify', function () {
    $request = Request::getContent();
    $params = (array)json_decode($request);
    $orderId = (int)$params['OrderId'];
    $order = Order::find($orderId);
    $tinkoff = new Tinkoff($order);
    return $tinkoff->notify($params);
});