<?php
/************************************************************************
 *
 * ADOBE CONFIDENTIAL
 * ___________________
 *
 * Copyright 2023 Adobe
 * All Rights Reserved.
 *
 * NOTICE: All information contained herein is, and remains
 * the property of Adobe and its suppliers, if any. The intellectual
 * and technical concepts contained herein are proprietary to Adobe
 * and its suppliers and are protected by all applicable intellectual
 * property laws, including trade secret and copyright laws.
 * Dissemination of this information or reproduction of this material
 * is strictly forbidden unless prior written permission is obtained
 * from Adobe.
 * ************************************************************************
 */

declare(strict_types=1);

namespace Magento\Ups\Model;

use Magento\Framework\App\Cache\Type\Config as Cache;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\AsyncClient\Request;
use Magento\Framework\HTTP\AsyncClientInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\Error;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;

class UpsAuth extends AbstractCarrier
{
    public const CACHE_KEY_PREFIX = 'ups_api_token_';

    /**
     * @var AsyncClientInterface
     */
    private $asyncHttpClient;

    /**
     * @var Cache
     */
    private $cache;

    /**
     * @var ErrorFactory
     */
    public $_rateErrorFactory;

    /**
     * @param AsyncClientInterface|null $asyncHttpClient
     * @param Cache $cacheManager
     * @param ErrorFactory $rateErrorFactory
     */
    public function __construct(
        AsyncClientInterface $asyncHttpClient = null,
        Cache $cacheManager,
        ErrorFactory $rateErrorFactory
    ) {
        $this->asyncHttpClient = $asyncHttpClient ?? ObjectManager::getInstance()->get(AsyncClientInterface::class);
        $this->cache = $cacheManager;
        $this->_rateErrorFactory = $rateErrorFactory;
    }

    /**
     * Token Generation
     *
     * @param String $clientId
     * @param String $clientSecret
     * @return mixed
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getAccessToken($clientId, $clientSecret, $clientUrl)
    {
        $cacheKey = self::CACHE_KEY_PREFIX;
        $result = $this->cache->load($cacheKey);
        if (!$result) {
            $headers = [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'x-merchant-id' => 'string',
                'Authorization' => 'Basic ' . base64_encode("$clientId:$clientSecret"),
            ];
            $authPayload = http_build_query([
                'grant_type' => 'client_credentials',
            ]);
            try {
                $asyncResponse = $this->asyncHttpClient->request(new Request(
                    $clientUrl,
                    Request::METHOD_POST,
                    $headers,
                    $authPayload
                ));
                $responseResult = $asyncResponse->get();
                $responseData = $responseResult->getBody();
                $responseData = json_decode($responseData);
                if (isset($responseData->access_token)) {
                    $result = $responseData->access_token;
                    $this->cache->save($result, $cacheKey, [], $responseData->expires_in ?: 10000);
                } else {
                    $error = $this->_rateErrorFactory->create();
                    $error->setCarrier('ups');
                    $error->setCarrierTitle($this->getConfigData('title'));
                    if ($this->getConfigData('specificerrmsg') !== '') {
                        $errorTitle = $this->getConfigData('specificerrmsg');
                    }
                    if (!isset($errorTitle)) {
                        $errorTitle = __('Cannot retrieve shipping rates');
                    }
                    $error->setErrorMessage($errorTitle);
                }
                return $result;
            } catch (\Magento\Framework\HTTP\AsyncClient\HttpException $e) {
                throw new \Magento\Framework\Exception\LocalizedException(__('Error occurred: %1', $e->getMessage()));
            }
        }
        return $result;
    }

    //phpcs:ignore
    public function collectRates(RateRequest $request)
    {
        // TODO: Implement collectRates() method.
    }
}
