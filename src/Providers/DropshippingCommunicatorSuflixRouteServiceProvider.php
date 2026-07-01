<?php

namespace DropshippingCommunicatorSuflix\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;

class DropshippingCommunicatorSuflixRouteServiceProvider extends RouteServiceProvider
{
    public function map(Router $router): void
    {
        $router->get(
            'suflix-export/{orderId}',
            'DropshippingCommunicatorSuflix\Controllers\ExportController@sendOrder'
        );

        $router->post(
            'suflix-export/{orderId}',
            'DropshippingCommunicatorSuflix\Controllers\ExportController@sendOrder'
        );
    }
}
