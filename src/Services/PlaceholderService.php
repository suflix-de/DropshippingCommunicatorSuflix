<?php

namespace DropshippingCommunicatorSuflix\Services;

class PlaceholderService
{
    public function replace(string $template, $order): string
    {
        $orderId = (string)($order->id ?? '');
        return str_replace('[OrderId]', $orderId, $template);
    }
}
