<?php namespace Xeor\TinkoffOctoCart;

use Log;
use Event;
use Redirect;
use System\Classes\PluginBase;
use Xeor\TinkoffOctoCart\Classes\Tinkoff;

/**
 * TinkoffOctoCart Plugin Information File
 */
class Plugin extends PluginBase
{
    /**
     * @var array Plugin dependencies
     */
    public $require = ['Xeor.OctoCart'];

    /**
     * Returns information about this plugin.
     *
     * @return array
     */
    public function pluginDetails()
    {
        return [
            'name' => 'Тинькофф Банк',
            'description' => 'Проведение платежей через Tinkoff EACQ.',
            'author' => 'Sozonov Alexey',
            'icon' => 'icon-shopping-cart',
            'homepage' => 'https://sozonov-alexey.ru'
        ];
    }

    public function boot()
    {

        Event::listen('xeor.octocart.paymentMethods', function () {
            return [
                'tinkoff' => 'Тинькофф Банк',
            ];
        });

        Event::listen('xeor.octocart.afterOrderSave', function ($order) {
            $tinkoff = new Tinkoff($order);
            return Redirect::to($tinkoff->getPaymentUrl());
        });

    }
}