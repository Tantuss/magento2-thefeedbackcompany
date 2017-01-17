<?php
/**
 * Copyright © 2016 Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magmodules\TheFeedbackCompany\Helper;

use Magmodules\TheFeedbackCompany\Helper\General as GeneralHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\App\Cache\TypeListInterface;

class Reviews extends AbstractHelper
{

    const XML_PATH_REVIEWS_ENABLED = 'magmodules_thefeedbackcompany/reviews/enabled';
    const XML_PATH_REVIEWS_CLIENT_ID = 'magmodules_thefeedbackcompany/api/client_id';
    const XML_PATH_REVIEWS_CLIENT_SECRET = 'magmodules_thefeedbackcompany/api/client_secret';
    const XML_PATH_REVIEWS_CLIENT_TOKEN = 'magmodules_thefeedbackcompany/api/client_token';
    const XML_PATH_REVIEWS_RESULT = 'magmodules_thefeedbackcompany/reviews/result';
    const XML_PATH_REVIEWS_LAST_IMPORT = 'magmodules_thefeedbackcompany/reviews/last_import';
    const REVIEWS_URL = 'https://beoordelingen.feedbackcompany.nl/api/v1/review/all/';

    protected $datetime;
    protected $timezone;
    protected $storeManager;
    protected $general;
    protected $config;
    protected $cacheTypeList;

    /**
     * Reviews constructor.
     * @param Context $context
     * @param StoreManagerInterface $storeManager
     * @param DateTime $datetime
     * @param TimezoneInterface $timezone
     * @param General $generalHelper
     * @param TypeListInterface $cacheTypeList
     * @internal param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        DateTime $datetime,
        TimezoneInterface $timezone,
        GeneralHelper $generalHelper,
        TypeListInterface $cacheTypeList
    ) {
        $this->datetime = $datetime;
        $this->timezone = $timezone;
        $this->storeManager = $storeManager;
        $this->general = $generalHelper;
        $this->cacheTypeList = $cacheTypeList;
        parent::__construct($context);
    }

    /**
     * Returns array of unique oauth data
     * @return array
     */
    public function getUniqueOauthData()
    {
        $oauth_data = [];
        $stores = $this->storeManager->getStores();
        foreach ($stores as $store) {
            if ($oauth = $this->getOauthData($store->getId())) {
                $oauth_data[$oauth['client_id']] = $oauth;
            }
        }

        return $oauth_data;
    }

    public function getOauthData($storeId = 0, $websiteId = null)
    {
        $oauth_data = [];

        if ($websiteId) {
            $enabled = $this->general->getWebsiteValue(self::XML_PATH_REVIEWS_ENABLED, $websiteId);
            $client_id = $this->general->getWebsiteValue(self::XML_PATH_REVIEWS_CLIENT_ID, $websiteId);
            $client_secret = $this->general->getWebsiteValue(self::XML_PATH_REVIEWS_CLIENT_SECRET, $websiteId);
            $client_token = $this->general->getWebsiteValue(self::XML_PATH_REVIEWS_CLIENT_TOKEN, $websiteId);
        } else {
            $enabled = $this->general->getStoreValue(self::XML_PATH_REVIEWS_ENABLED, $storeId);
            $client_id = $this->general->getStoreValue(self::XML_PATH_REVIEWS_CLIENT_ID, $storeId);
            $client_secret = $this->general->getStoreValue(self::XML_PATH_REVIEWS_CLIENT_SECRET, $storeId);
            $client_token = $this->general->getStoreValue(self::XML_PATH_REVIEWS_CLIENT_TOKEN, $storeId);
        }

        if ($enabled && $client_id && $client_secret) {
            $oauth_data = [
                'store_id' => $storeId,
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'client_token' => $client_token
            ];
        }

        return $oauth_data;
    }

    /**
     * Saves review result data a array by client_id
     * @param $result
     * @param string $type
     * @return array
     */
    public function saveReviewResult($result, $type = 'cron')
    {
        $fbc_data = [];
        foreach ($result as $key => $row) {
            $status = $row['status'];
            if ($status == 'success') {
                $fbc_data[$key]['status'] = $status;
                $fbc_data[$key]['type'] = $type;
                $fbc_data[$key]['name'] = $row['shop']['name'];
                $fbc_data[$key]['link'] = $row['shop']['review_url'];
                $fbc_data[$key]['total_reviews'] = $row['review_summary']['total_merchant_reviews'];
                $fbc_data[$key]['score'] = $row['review_summary']['merchant_score'];
                $fbc_data[$key]['score_max'] = $row['review_summary']['max_score'];
                $fbc_data[$key]['percentage'] = ($row['review_summary']['merchant_score'] * 10) . '%';
            } else {
                $fbc_data[$key]['status'] = $status;
                $fbc_data[$key]['msg'] = $row['msg'];
            }
        }
        $update_msg = $this->datetime->gmtDate() . ' (' . $type . ').';
        $this->general->setConfigData(json_encode($fbc_data), self::XML_PATH_REVIEWS_RESULT);
        $this->general->setConfigData($update_msg, self::XML_PATH_REVIEWS_LAST_IMPORT);

        return $fbc_data;
    }

    /**
     * Unset all Client Tokens.
     * Function is called on before save in config, when client_id is changed.
     * All Client Tokens will be reset.
     */
    public function resetAllClientTokens()
    {
        $stores = $this->storeManager->getStores();
        foreach ($stores as $store) {
            $this->setClientToken('', $store->getId(), false);
        }
        $this->cacheTypeList->cleanType('config');
    }

    /**
     * Save client token to config
     * @param $token
     * @param int $storeId
     * @param bool $flushCache
     */
    public function setClientToken($token, $storeId, $flushCache = true)
    {
        $this->general->setConfigData($token, self::XML_PATH_REVIEWS_CLIENT_TOKEN, $storeId);
        if ($flushCache) {
            $this->cacheTypeList->cleanType('config');
        }
    }

    /**
     * Summay data getter for block usage
     * @param int $storeId
     * @param null $websiteId
     * @return mixed
     */
    public function getSummaryData($storeId = 0, $websiteId = null)
    {
        $data = $this->getAllSummaryData();
        if ($websiteId) {
            $client_id = $this->general->getWebsiteValue(self::XML_PATH_REVIEWS_CLIENT_ID, $websiteId);
        } else {
            $client_id = $this->general->getStoreValue(self::XML_PATH_REVIEWS_CLIENT_ID, $storeId);
        }

        if (!empty($client_id)) {
            if (!empty($data[$client_id]['status'])) {
                if ($data[$client_id]['status'] == 'success') {
                    return $data[$client_id];
                }
            }
        }

        return false;
    }

    /**
     * Array of all stored summay data
     * @return mixed
     */
    public function getAllSummaryData()
    {
        return json_decode($this->general->getStoreValue(self::XML_PATH_REVIEWS_RESULT), true);
    }

    /**
     * Last imported date
     * @return mixed
     */
    public function getLastImported()
    {
        $last_imported = $this->general->getStoreValue(self::XML_PATH_REVIEWS_LAST_IMPORT);

        return $last_imported;
    }
}