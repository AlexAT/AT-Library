<?php

namespace ATL;

interface ICURL
{
    # abstracted options that should be passed without explicitly passing CURL specific options
    const HTTP_METHOD = 0;
        const METHOD_GET = 0;
        const METHOD_HEAD = 1;
        const METHOD_POST = 2;
        const METHOD_PUT = 3;
        const METHOD_DELETE = 4;
        const METHOD_OPTIONS = 5;
        const METHOD_PATCH = 6;
    const HTTP_CONTENT_TYPE = 1;
    const HTTP_CONTENT = 2;
    const HTTP_VERIFY_SSL_CERTIFICATE = 3;
    const HTTP_VERIFY_SSL_CERTIFICATE_HOST = 4;
    const HTTP_MAX_REDIRECTS = 5;
    const HTTP_CONNECT_TIMEOUT_SECONDS = 6;
    const HTTP_DNS_CACHE_TIMEOUT_SECONDS = 7;
    const HTTP_TIMEOUT_SECONDS = 8;
    const HTTP_USER_AGENT = 9;

    # these are defaults if user does not supply them
    const baseOptions = [
        CURLOPT_FRESH_CONNECT => true, # this is alas a necessity as CURL tends to segfault in some configurations otherwise
        CURLOPT_FORBID_REUSE => true, # this is alas a necessity as CURL tends to segfault in some configurations otherwise
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_DNS_CACHE_TIMEOUT => 1,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'PHP-ATL-CURL/1.0',
    ];

    # these override any user-supplied parameters and are needed for proper class inner workings
    const baseLateOptions = [
        CURLOPT_HEADER => true,
        CURLINFO_HEADER_OUT => true,
        CURLOPT_RETURNTRANSFER => true,
    ];

    # normally there should have been no default headers but alas we need to workaround some common issues, use header => null to remove them if necessary
    const baseHeaders = [
        'accept-language' => 'en', # this is needed to always add Accept-Language header that is absolutely required by some servers
        'expect' => "", # this is needed to prevent weird CURL behavior of sending Expect header where it is not needed and may be not supported
    ];
}

trait TCURL
{
    protected $requestURL;
    protected $curlOptions;
    protected $curlRequest;

    protected $errorCode;
    protected $errorText;
    protected $responseCode;
    protected $responseInfo;
    protected $responseHeaders;
    protected $responseContent;

    public $appData; # anything associated with the request can go here

    public function __construct($url, $options = [], $headers = [], $curlOptions = [])
    {
        $this->requestURL = $url;

        # build real headers and options
        array_change_key_case($headers, CASE_LOWER);
        $realHeaders = $this::baseHeaders;
        $this->curlOptions = $this::baseOptions;

        # merge user-defined CURL headers and options
        foreach ($headers as $k => $v)
            $realHeaders[$k] = $v;
        foreach ($curlOptions as $k => $v)
            $this->curlOptions[$k] = $v;

        # add request method based elements
        switch ($options[$this::HTTP_METHOD] ?? $this::METHOD_GET) {
            case $this::METHOD_POST:
            case $this::METHOD_PUT:
            case $this::METHOD_PATCH:
            # POST, PUT and PATCH must have content, and calculating content length is also good thing to do
            if (isset($options[$this::HTTP_CONTENT_TYPE]))
                $realHeaders['content-type'] = $options[$this::HTTP_CONTENT_TYPE];
            switch ($options[$this::HTTP_METHOD]) {
                case $this::METHOD_POST: $this->curlOptions[CURLOPT_POST] = true; break;
                case $this::METHOD_PUT: $this->curlOptions[CURLOPT_CUSTOMREQUEST] = 'PUT'; break;
                case $this::METHOD_PATCH: $this->curlOptions[CURLOPT_CUSTOMREQUEST] = 'PATCH'; break;
            }
            if (isset($options[$this::HTTP_CONTENT]))
                $this->curlOptions[CURLOPT_POSTFIELDS] = $options[$this::HTTP_CONTENT];
            if (!array_key_exists('content-length', $realHeaders) && isset($this->curlOptions[CURLOPT_POSTFIELDS]) && !is_array($this->curlOptions[CURLOPT_POSTFIELDS]))
                $realHeaders['content-length'] = strlen($this->curlOptions[CURLOPT_POSTFIELDS]);
            break;

            case $this::METHOD_GET: break;
            case $this::METHOD_HEAD: $this->curlOptions[CURLOPT_NOBODY] = true; break;
            case $this::METHOD_DELETE: $this->curlOptions[CURLOPT_CUSTOMREQUEST] = 'DELETE'; break;
            case $this::METHOD_OPTIONS: $this->curlOptions[CURLOPT_CUSTOMREQUEST] = 'OPTIONS'; break;

            default:
            $this->curlOptions[CURLOPT_CUSTOMREQUEST] = $options[$this::HTTP_METHOD];
            break;
        }

        # convert abstract options to CURL options
        if (isset($options[$this::HTTP_VERIFY_SSL_CERTIFICATE])) {
            $this->curlOptions[CURLOPT_SSL_VERIFYPEER] = $options[$this::HTTP_VERIFY_SSL_CERTIFICATE] ? true : false;
            if (defined('CURLOPT_SSL_VERIFYSTATUS'))
                $this->curlOptions[CURLOPT_SSL_VERIFYSTATUS] = $options[$this::HTTP_VERIFY_SSL_CERTIFICATE] ? true : false;
        }
        if (isset($options[$this::HTTP_VERIFY_SSL_CERTIFICATE_HOST]))
            $this->curlOptions[CURLOPT_SSL_VERIFYHOST] = $options[$this::HTTP_VERIFY_SSL_CERTIFICATE_HOST] ? 2 : 0;
        if (isset($options[$this::HTTP_CONNECT_TIMEOUT_SECONDS]))
            $this->curlOptions[CURLOPT_CONNECTTIMEOUT] = floor($options[$this::HTTP_CONNECT_TIMEOUT_SECONDS]);
        if (isset($options[$this::HTTP_DNS_CACHE_TIMEOUT_SECONDS]))
            $this->curlOptions[CURLOPT_DNS_CACHE_TIMEOUT] = floor($options[$this::HTTP_DNS_CACHE_TIMEOUT_SECONDS]);
        if (isset($options[$this::HTTP_TIMEOUT_SECONDS]))
            $this->curlOptions[CURLOPT_TIMEOUT] = floor($options[$this::HTTP_TIMEOUT_SECONDS]);
        if (isset($options[$this::HTTP_USER_AGENT]))
            $this->curlOptions[CURLOPT_USERAGENT] = $options[$this::HTTP_USER_AGENT];

        # merge late base options that override everything
        foreach ($this::baseLateOptions as $k => $v)
            $this->curlOptions[$k] = $v;

        # filter out null values from headers and options
        $this->curlOptions = array_filter($this->curlOptions, function ($v) { return ($v !== null); });
        $realHeaders = array_filter($realHeaders, function ($v) { return ($v !== null); });

        # convert headers to be CURL-compatible
        if (!isset($this->curlOptions[CURLOPT_HTTPHEADER]) || !is_array($this->curlOptions[CURLOPT_HTTPHEADER]))
            $this->curlOptions[CURLOPT_HTTPHEADER] = [];
        foreach ($realHeaders as $k => $v) {
            if (!is_array($v)) {
                $this->curlOptions[CURLOPT_HTTPHEADER][] = "{$k}: {$v}";
            } else {
                foreach ($v as $vv)
                    $this->curlOptions[CURLOPT_HTTPHEADER][] = "{$k}: {$vv}";
            }
        }

        $this->initRequest();
    }

    public function initRequest()
    {
        $this->curlRequest = curl_init($this->requestURL);
        curl_setopt_array($this->curlRequest, $this->curlOptions);
        $this->errorCode = $this->errorText = $this->responseCode = $this->responseInfo = $this->responseHeaders = $this->responseContent = null;
        return $this->curlRequest;
    }

    public function doRequest()
    {
        if ($this->curlRequest === null) $this->initRequest();
        $result = $this->reapRequest($this->curlRequest, @curl_exec($this->curlRequest));
        $this->closeRequest();
        return $result;
    }

    public function reapRequest($curlRequest, $curlResponseContent)
    {
        $this->errorCode = $this->errorText = $this->responseCode = $this->responseInfo = $this->responseHeaders = $this->responseContent = null;
        if ($curlRequest !== null) {
            $this->responseCode = curl_getinfo($curlRequest, CURLINFO_HTTP_CODE);
            $this->responseInfo = curl_getinfo($curlRequest);
            $this->errorCode = curl_errno($curlRequest);
            $this->errorText = curl_error($curlRequest);
            if (($curlResponseContent === false) || ($curlResponseContent === null)) return false; # nothing to do anymore, no response
            $hSize = curl_getinfo($curlRequest, CURLINFO_HEADER_SIZE);
            $this->responseHeaders = substr($curlResponseContent, 0, $hSize);
            $this->responseContent = substr($curlResponseContent, $hSize);
            return true;
        }
        return false;
    }

    public function closeRequest()
    {
        if ($this->curlRequest !== null) {
            curl_close($this->curlRequest);
            $this->curlRequest = null;
        }
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function getErrorText()
    {
        return $this->errorText;
    }

    public function getResponseCode()
    {
        return $this->responseCode;
    }

    public function getRequestURL()
    {
        return $this->requestURL;
    }

    public function getResponseInfo()
    {
        return $this->responseInfo;
    }

    public function getResponseHeaders()
    {
        return $this->responseHeaders;
    }

    public function getResponseContent()
    {
        return $this->responseContent;
    }

    public function getCURLResource()
    {
        return $this->curlRequest;
    }

    public function getCURLOptions()
    {
        return $this->curlOptions;
    }
}

class CURL implements ICURL { use TCURL; }

class MultiCURL
{
    /** @var \ATL\CURL[] */ protected $requests = [];
    protected $requestsIdMap = [];
    protected $requestNumber = 0;

    protected $lastErrorCode;

    protected $mCURL;

    public function __construct($curlObjects = [])
    {
        $this->mCURL = curl_multi_init();
        if (is_array($curlObjects) && !empty($curlObjects)) {
            foreach ($curlObjects as $curlObject)
                $this->addRequest($curlObject);
        } elseif ($curlObjects instanceof \ATL\CURL) {
            $this->addRequest($curlObjects);
        }
    }

    public function addRequest(/** @var \ATL\CURL */ $curlObject)
    {
        $curlRequest = $curlObject->getCURLResource() ?? $curlObject->initRequest();
        curl_setopt($curlRequest, CURLOPT_PRIVATE, $this->requestNumber);
        curl_multi_add_handle($this->mCURL, $curlRequest);
        $this->requests[$this->requestNumber] = $curlObject;
        $this->requestsIdMap[spl_object_id($curlObject)] = $this->requestNumber;
        $this->requestNumber = ($this->requestNumber + 1) & 0x3FFFFFFF; # wrap request number to fit into signed int32
    }

    public function removeRequest(/** @var \ATL\CURL */ $curlObject)
    {
        if (isset($this->requestsIdMap[$splID = spl_object_id($curlObject)])) {
            $reqNo = $this->requestsIdMap[$splID];
            /** @var \ATL\CURL */ $curlObject = $this->requests[$reqNo];
            curl_multi_remove_handle($this->mCURL, $curlObject->getCURLResource());
            $curlObject->closeRequest();
            unset($this->requests[$reqNo], $this->requestsIdMap[spl_object_id($curlObject)]);
        }
    }

    public function processRequests($wait = null)
    {
        if (count($this->requests) == 0) return []; # nothing to process anymore
        if ($this->lastErrorCode !== null) return false; # we failed somewhere

        # poll CURL exec
        $active = 0;
        $status = curl_multi_exec($this->mCURL, $active);
        if ($status != CURLM_OK) {
            $this->lastErrorCode = $status;
            return false;
        }

        # if we are active and may wait, wait
        if ($active && $wait)
            curl_multi_select($this->mCURL, $wait);

        # reap and remove finished requests
        $finishedRequests = [];
        while (($info = curl_multi_info_read($this->mCURL)) !== false) {
            $reqNo = curl_getinfo($info['handle'], CURLINFO_PRIVATE);
            if (isset($this->requests[$reqNo])) {
                /** @var \ATL\CURL */ $curlObject = $this->requests[$reqNo];
                $curlObject->reapRequest($info['handle'], curl_multi_getcontent($info['handle']));
                curl_multi_remove_handle($this->mCURL, $info['handle']);
                $curlObject->closeRequest();
                unset($this->requests[$reqNo], $this->requestsIdMap[spl_object_id($curlObject)]);
                $finishedRequests[] = $curlObject;
            }
        }
        return $finishedRequests;
    }

    public function close()
    {
        foreach ($this->requests as $curlObject)
            $this->removeRequest($curlObject);
        curl_multi_close($this->mCURL);
    }

    public function getRequestCount()
    {
        return count($this->requests);
    }

    public function getLastError()
    {
        return $this->lastError;
    }
}
