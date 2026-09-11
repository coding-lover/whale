<?php

namespace App\Services\Test;

use App\Services\Exchanges\ExchangeManager;

class TestServicesProvider
{
    private ExchangeManager $exchangeManager;

    public function __construct()
    {

    }

    public function test()
    {
        $fmtSymbol = $this->exchangeManager->exchange('binance')->formatSymbol('BTC/USDT:quarter');
        return $fmtSymbol;
    }
}