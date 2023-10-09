<?php

namespace Igor\resources;

use Exception;
use JsonException;

class Curler {

//////////////////////////
/// MAKING CURL HANDLE ///
//////////////////////////

    /**
     * Shortcut to makeCurl(...) and call it immediately;
     *
     * @param string $type
     * @param string $url
     * @param array|string|null $body
     * @param array $headers
     * @param array $options
     * @param int $timeoutMilliSecs
     * @return array|string|null
     * @throws JsonException|Exception
     */
    public function callCurl(
        string $type,
        string $url,
        $body = null,
        array $headers = [],
        array $options = [],
        int $timeoutMilliSecs = 30000
    ) {
        return $this->curlCall(
            $this->makeCurl($type, $url, $body, $headers, $options, $timeoutMilliSecs)
        );
    }

    /**
     * Calls the Curl single request
     *
     * @param resource $curlCall
     * @return array|string|null
     * @throws Exception
     */
    public function curlCall($curlCall) {
        // execute the call
        $response = curl_exec($curlCall);
        if (!in_array($errNo = curl_errno($curlCall), [0, 28])) { // 28 = timeout
            $errMsg = curl_error($curlCall);
            curl_close($curlCall);
            throw new Exception($errMsg, $errNo);
        }
        curl_close($curlCall);

        // parse the response
        try {
            return $response ? json_decode($response, true, 512, JSON_THROW_ON_ERROR) : $response;
        } catch (JsonException $e) {
            return $response;
        }
    }

/////////////////////
/// CALLING CURLS ///
/////////////////////

    /**
     * Creates new Curl single call handler
     *
     * @param string $type
     * @param string $url
     * @param array|string|null $body
     * @param array $customHeaders
     * @param array $additionalOptions
     * @param int $timeoutMilliSecs
     * @return resource
     * @throws JsonException|Exception
     */
    public function makeCurl(
        string $type,
        string $url,
        $body = null,
        array $customHeaders = [],
        array $additionalOptions = [],
        int $timeoutMilliSecs = 0
    ) {
        // init curl handle
        if (!$curlCall = curl_init($url)) {
            throw new Exception("Curler: makeCurl: Couldn't init CURL");
        }

        // set default headers
        $headers['Accept'] = 'application/json; charset=utf-8';
        if ($body) {
            if (!is_string($body)) {
                $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
            $headers['Content-Type']   = 'application/json; charset=utf-8';
            $headers['Content-Length'] = strlen($body);
        }

        // add/overwrite with custom headers
        $headers = array_merge($headers, $customHeaders);

        // filter null headers and reformat to complete strings
        foreach ($headers as $key => &$header) {
            if (is_null($header)) {
                unset($headers[$key]);
            } else {
                $header = "$key: $header";
            }
        }

        // set default options
        curl_setopt($curlCall, CURLOPT_URL, $url);
        curl_setopt($curlCall, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curlCall, CURLOPT_HEADER, 0);
        curl_setopt($curlCall, CURLOPT_CUSTOMREQUEST, $type);
        if ($body) {
            curl_setopt($curlCall, CURLOPT_POSTFIELDS, $body);
        }
        if ($timeoutMilliSecs > 0) {
            curl_setopt($curlCall, CURLOPT_TIMEOUT_MS, $timeoutMilliSecs);
        }
        curl_setopt($curlCall, CURLOPT_RETURNTRANSFER, true);

        // add/overwrite with custom options
        curl_setopt_array($curlCall, $additionalOptions);

        return $curlCall;
    }

    /**
     * Calls the Curl multi request (or rather multiple requests within one multi handler)
     * // didn't ever found some example of catching errors right, so I'm trying multiple approaches
     *
     * @param bool $throwFirstError
     * @param int $timeoutMilliSecs
     * @param ...$curls
     * @return array
     * @throws Exception
     */
    public function curlMultiCall(bool $throwFirstError, int $timeoutMilliSecs, ...$curls): array {
        // init part
        $curlMultiHandler = curl_multi_init();
        foreach ($curls as $curl) {
            curl_multi_add_handle($curlMultiHandler, $curl);
        }

        // call (and wait) part
        $stillRunning = null;
        $startTime    = microtime(true);
        do {
            $status = curl_multi_exec($curlMultiHandler, $stillRunning);
        } while (
            $stillRunning // wait only if any single curl is still running
            && (!$throwFirstError || $status === CURLM_OK) // and when all cURLs are OK, or we are waiting for all to finish
            && (
                !$timeoutMilliSecs // if !$timeoutMilliSecs, wait "forever" (handled by timeouts of individual $curls)
                || $timeoutMilliSecs > (microtime(true) - $startTime / 1000) // wait for specific time (timeout > realTime)
            )
        );

        if ($throwFirstError && $status !== CURLM_OK) {
            $errNo = curl_multi_errno($curlMultiHandler);
            throw new Exception(curl_multi_strerror($errNo), $errNo);
        }

        // process responses part
        $responses = [];
        foreach ($curls as $curl) {
            if (!$stillRunning) { // it should be $stillRunning even if no waiting at all ($timeoutMilliSecs == 0)
                $responses[] = curl_multi_getcontent($curl);
            }
            curl_multi_remove_handle($curlMultiHandler, $curl);
        }

        // closing part
        curl_multi_close($curlMultiHandler);
        foreach ($curls as $key => $curl) {
            if ($errNo = curl_errno($curl)) {
                $responses[$key] = new Exception(curl_error($curl), $errNo); // if a single cURL failed, its response is Exception
            }
            curl_close($curl);
        }

        // return part
        return $responses;
    }
}
