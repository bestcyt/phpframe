<?php

namespace Fw;

/**
 * 对curl进行封装的http操作类
 */
class Http
{
    const METHOD_GET = 'get';
    const METHOD_POST = 'post';

    const LOG_TYPE_HTTP = LogType::HTTP;
    const LOG_TYPE_HTTP_CONNECT = LogType::HTTP_CONNECT;
    const LOG_TYPE_HTTP_CLIENT = LogType::HTTP_CLIENT;
    const LOG_TYPE_HTTP_SERVER = LogType::HTTP_SERVER;

    private $curlOptions = [];
    private $ch;
    private $method;
    private $result;
    private $curlInfo;
    private $url;
    private $params;
    private $headers = [];
    private $postByMultiPart = false;
    private $logConfig = [];
    private $reuse = 0;//是否复用完全相同的请求(复用次数)

    private $slowThreshold = 0;
    private $enableErrorLog = false;
    //不区分具体是哪种http错误则配置一个LOG_TYPE_HTTP即可,
    //若要区分则可设置其他想记录日志的更具体的LOG_TYPE
    private $errorLogTypes = [self::LOG_TYPE_HTTP];

    //默认参数
    private $defaultConnectTime = 10;
    private $defaultTimeout = 30;

    public function __construct($url, $params = [])
    {
        $this->url = $url;
        $this->params = $params;
    }

    private function curlExec()
    {
        static $Http = [];
        if ($this->method == self::METHOD_POST) {
            $this->curlOptions[CURLOPT_POST] = 1;
            if ($this->params) {
                $this->curlOptions[CURLOPT_POSTFIELDS] = $this->postByMultiPart ? $this->params : http_build_query($this->params);
            }
        } elseif ($this->method == self::METHOD_GET) {
            if ($this->params) {
                $queryString = http_build_query($this->params);
                if (strpos($this->url, '?') === false) {
                    $this->url .= '?' . $queryString;
                } else {
                    $this->url .= '&' . $queryString;
                }
            }
        }
        $this->curlOptions[CURLOPT_URL] = $this->url;
        if (!isset($this->curlOptions[CURLOPT_SSL_VERIFYPEER])) {
            $this->curlOptions[CURLOPT_SSL_VERIFYPEER] = 0;
        }
        if (!isset($this->curlOptions[CURLOPT_RETURNTRANSFER])) {
            $this->curlOptions[CURLOPT_RETURNTRANSFER] = 1;
        }
        if (!isset($this->curlOptions[CURLOPT_FOLLOWLOCATION])) {
            $this->curlOptions[CURLOPT_FOLLOWLOCATION] = 1;
        }
        if ($this->headers) {
            $this->curlOptions[CURLOPT_HTTPHEADER] = $this->headers;
        }
        if (!isset($this->curlOptions[CURLOPT_CONNECTTIMEOUT]) && !isset($this->curlOptions[CURLOPT_CONNECTTIMEOUT_MS])) {
            $this->curlOptions[CURLOPT_CONNECTTIMEOUT] = $this->defaultConnectTime;
        }
        if (!isset($this->curlOptions[CURLOPT_TIMEOUT]) && !isset($this->curlOptions[CURLOPT_TIMEOUT_MS])) {
            $this->curlOptions[CURLOPT_TIMEOUT] = $this->defaultTimeout;
        }

        if ($this->reuse && is_numeric($this->reuse) && $this->reuse > 0) {
            //复用完全相同的请求
            $cache_key = md5(json_encode($this->curlOptions));
            if (empty($Http[$cache_key]) || $Http[$cache_key]["count"] > $this->reuse) {
                if (!empty($Http[$cache_key])) {
                    curl_close($Http[$cache_key]["http"]);
                }
                $ch = curl_init();
                if (!curl_setopt_array($ch, $this->curlOptions)) {
                    return false;
                }
                $Http[$cache_key] = [
                    "http" => &$ch,
                    "count" => 0,
                ];
            }
            $Http[$cache_key]["count"]++;
            $this->ch = $Http[$cache_key]["http"];
        } else {
            $this->ch = curl_init();
            if (!curl_setopt_array($this->ch, $this->curlOptions)) {
                return false;
            }
        }

        $this->result = curl_exec($this->ch);
        $this->curlInfo = curl_getinfo($this->ch);
        curl_close($this->ch);

        //是否属于慢请求
        if ($this->slowThreshold > 0 && isset($this->curlInfo['total_time'])
            && $this->curlInfo['total_time'] >= $this->slowThreshold) {
            //记录慢请求日志
            $logger = App::getInstance()->getLogger();
            $logInfo = [
                'curl_info' => $this->curlInfo
            ];

            // 记录请求参数
            $logParams = $this->getParamsLog();
            if (!empty($logParams)) {
                $logInfo['params'] = $logParams;
            }
            $logger->error($logInfo, LogType::HTTP_SLOW);
        }

        //是否开启错误日志记录,开启记录的是哪些错误类型
        if ($this->enableErrorLog && isset($this->curlInfo['http_code']) && $this->curlInfo['http_code'] != 200) {
            $logger = App::getInstance()->getLogger();
            $httpCode = $this->curlInfo['http_code'];
            $logInfo = [
                'curl_info' => $this->curlInfo,
                'result' => $this->result
            ];


            // 记录请求参数
            $logParams = $this->getParamsLog();
            if (!empty($logParams)) {
                $logInfo['params'] = $logParams;
            }

            if (in_array(self::LOG_TYPE_HTTP, $this->errorLogTypes)) {
                $logger->error($logInfo, LogType::HTTP);
            } elseif ($httpCode == 0 && in_array(self::LOG_TYPE_HTTP_CONNECT, $this->errorLogTypes)) {
                $logger->error($logInfo, LogType::HTTP_CONNECT);
            } elseif ($httpCode >= 400 && $httpCode <= 499 && in_array(self::LOG_TYPE_HTTP_CLIENT, $this->errorLogTypes)) {
                $logger->error($logInfo, LogType::HTTP_CLIENT);
            } elseif ($httpCode >= 500 && $httpCode <= 599 && in_array(self::LOG_TYPE_HTTP_SERVER, $this->errorLogTypes)) {
                $logger->error($logInfo, LogType::HTTP_SERVER);
            }
        }

        return true;
    }

    public static function getInstance($url, $params = [])
    {
        return new static($url, $params);
    }

    public function setReuse($reuse = 10)
    {
        if ($reuse === true) {
            $reuse = 10;
        } elseif ($reuse === false) {
            $reuse = 0;
        } elseif (!is_numeric($reuse)) {
            $reuse = 0;
        }
        $this->reuse = $reuse;
        return $this;
    }

    public function setCurlOption($option, $value)
    {
        $this->curlOptions[$option] = $value;
        return $this;
    }

    public function setCurlOptions(array $options)
    {
        if ($options) {
            foreach ($options as $key => $value) {
                $this->curlOptions[$key] = $value;
            }
        }
        return $this;
    }

    public function setHeader($header)
    {
        $this->headers[] = $header;
        return $this;
    }

    public function setHeaders(array $headers)
    {
        if ($headers) {
            foreach ($headers as $header) {
                $this->headers[] = $header;
            }
        }
        return $this;
    }

    public function setConnectTimeout($connectTimeout)
    {
        $this->curlOptions[CURLOPT_CONNECTTIMEOUT] = $connectTimeout;
        return $this;
    }

    public function setConnectTimeoutMs($connectTimeoutMs)
    {
        $this->curlOptions[CURLOPT_CONNECTTIMEOUT_MS] = $connectTimeoutMs;
        return $this;
    }

    public function setTimeout($timeout)
    {
        $this->curlOptions[CURLOPT_TIMEOUT] = $timeout;
        return $this;
    }

    public function setTimeoutMs($timeoutMs)
    {
        $this->curlOptions[CURLOPT_TIMEOUT_MS] = $timeoutMs;
        return $this;
    }

    public function setUploadFile($key, $filename, $mimeType = '', $postName = '')
    {
        $this->params[$key] = new \CURLFile($filename, $mimeType, $postName);
        $this->postByMultiPart = true;
        return $this;
    }


    public function setSlowThreshold($seconds)
    {
        $this->slowThreshold = $seconds;
        return $this;
    }

    public function enableErrorLog()
    {
        $this->enableErrorLog = true;
        return $this;
    }

    public function setErrorLogTypes(array $logTypes)
    {
        $this->errorLogTypes = $logTypes;
        return $this;
    }


    public function get()
    {
        $this->method = self::METHOD_GET;
        $this->curlExec();
        return $this;
    }

    public function post()
    {
        $this->method = self::METHOD_POST;
        $this->curlExec();
        return $this;
    }

    public function postByMultiPart()
    {
        $this->method = self::METHOD_POST;
        $this->postByMultiPart = true;
        $this->curlExec();
        return $this;
    }


    public function getResult()
    {
        return $this->result;
    }

    public function getJsonResult()
    {
        return $this->result ? json_decode($this->result, true) : null;
    }

    public function getCurlInfo()
    {
        return $this->curlInfo;
    }

    public function getHttpCode()
    {
        return isset($this->curlInfo['http_code']) ? $this->curlInfo['http_code'] : null;
    }


    public function setLogParams($params = [])
    {
        $this->logConfig['params'] = $params;
        return $this;
    }

    // 增加返回的参数
    private function getParamsLog()
    {
        if (empty($this->logConfig['params']) || empty($this->params)) {
            return false;
        }

        $data = [];

        foreach ($this->logConfig['params'] as $_param) {

            if (isset($this->params[$_param])) {
                $data[$_param] = $this->params[$_param];
            }
        }

        return $data;
    }

}

