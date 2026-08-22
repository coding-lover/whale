<?php

namespace App\Services\Exchanges;

/**
 * 交易所异常
 *
 * 所有交易所请求和响应处理中的异常都使用此类，
 * 便于上层统一捕获和处理
 */
class ExchangeException extends \Exception
{
    /**
     * 原始响应数据（可选，用于调试）
     *
     * @var array|null
     */
    protected ?array $responseData = null;

    /**
     * 构造方法
     *
     * @param string $message 错误信息
     * @param int $code 错误码（HTTP 状态码或交易所业务错误码）
     * @param array|null $responseData 原始响应数据
     */
    public function __construct(string $message, int $code = 0, ?array $responseData = null)
    {
        parent::__construct($message, $code);
        $this->responseData = $responseData;
    }

    /**
     * 获取原始响应数据
     *
     * @return array|null
     */
    public function getResponseData(): ?array
    {
        return $this->responseData;
    }
}
