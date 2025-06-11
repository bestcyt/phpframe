<?php

namespace Fw;

class Request
{
    use InstanceTrait;

    private $pathInfo;
    private $originPathInfo;
    private $params = [];
    private $routePath;
    private $module;
    private $controller;
    private $action;
    private $suffix = '';

    private $clientIp;
    private $serverIp;
    private $reqId;

    public function getPathInfo()
    {
        if ($this->pathInfo === null) {
            $pathInfo = $this->getOriginPathInfo();
            $supportedSuffixes = App::getInstance()->getSupportedSuffixes();
            if ($supportedSuffixes) {
                $lastPos = strrpos($pathInfo, '.');
                if ($lastPos !== false) {
                    $suffix = strtolower(substr($pathInfo, $lastPos + 1));
                    if (in_array($suffix, $supportedSuffixes)) {
                        $this->suffix = $suffix;
                        $pathInfo = substr($pathInfo, 0, $lastPos);
                    }
                }
            }
            $this->pathInfo = $pathInfo;
        }
        return $this->pathInfo;
    }

    public function getOriginPathInfo()
    {
        if ($this->originPathInfo === null) {
            if (PHP_SAPI == 'cli') {
                $args = getopt('', array('uri:', 'get::', 'post::'));
                $requestUri = !empty($args['uri']) ? $args['uri'] : '';

                //填充$_GET/$_POST参数
                if (!empty($args['get'])) {
                    parse_str($args['get'], $_GET);
                }
                if (!empty($args['post'])) {
                    parse_str($args['post'], $_POST);
                }
            } else {
                $requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
            }
            $pathInfo = '';
            if ($requestUri) {
                $pos = strpos($requestUri, '?');
                if ($pos === false) {
                    $pathInfo = $requestUri;
                } elseif ($pos > 0) {
                    $pathInfo = substr($requestUri, 0, $pos);
                }
            }
            $this->originPathInfo = $pathInfo;
        }
        return $this->originPathInfo;
    }

    public function getRoutePath()
    {
        return $this->routePath !== null ? $this->routePath : $this->getPathInfo();
    }

    public function setRoutePath($routePath)
    {
        $this->routePath = $routePath;
    }

    public function get($key = null, $default = null, $format = "htmlspecialchars|trim|strip_tags")
    {
        if ($key !== null) {
            $value = isset($_GET[$key]) ? $_GET[$key] : $default;
            return $this->format($value, $format);
        }
        return $_GET;
    }

    public function post($key = null, $default = null, $format = "htmlspecialchars|trim|strip_tags")
    {
        if ($key !== null) {
            $value = isset($_POST[$key]) ? $_POST[$key] : $default;
            return $this->format($value, $format);
        }
        return $_POST;
    }

    public function input($key = null, $default = null, $format = "htmlspecialchars|trim|strip_tags")
    {
        if ($key !== null) {
            $value = isset($_REQUEST[$key]) ? $_REQUEST[$key] : $default;
            return $this->format($value, $format);
        }
        return $_REQUEST;
    }

    public function getParams()
    {
        return $this->params;
    }

    public function param($index)
    {
        return isset($this->params[$index]) ? $this->params[$index] : null;
    }

    public function setParams($params)
    {
        $this->params = $params;
    }

    public function __set($name, $value)
    {
        $this->params[$name] = $value;
    }

    public function __get($name)
    {
        return isset($this->params[$name]) ? $this->params[$name] : null;
    }

    public function setModule($module)
    {
        $this->module = $module;
    }

    public function getModule()
    {
        return $this->module;
    }

    public function setController($controller)
    {
        $this->controller = $controller;
    }

    public function getController()
    {
        return $this->controller;
    }

    public function setAction($action)
    {
        $this->action = $action;
    }

    public function getAction()
    {
        return $this->action;
    }

    public function getSuffix()
    {
        return $this->suffix;
    }

    public function getClientIp()
    {
        //IP V4
        if (!$this->clientIp) {
            $ip = '';
            $unknown = 'unknown';
            if (!$ip && !empty($_SERVER['HTTP_X_FORWARDED_FOR']) && strcasecmp($_SERVER['HTTP_X_FORWARDED_FOR'], $unknown)) {
                $ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $clientIp = trim(current($ipList));
                if (ip2long($clientIp) !== false) {
                    $ip = $clientIp;
                }
            }
            if (!$ip && !empty($_SERVER['REMOTE_ADDR']) && strcasecmp($_SERVER['REMOTE_ADDR'], $unknown)) {
                $ip = trim($_SERVER['REMOTE_ADDR']);
            }
            $this->clientIp = $ip;
        }
        return $this->clientIp;
    }

    public function getServerIp()
    {
        //IP V4
        if (!$this->serverIp) {
            if (!empty($_SERVER['SERVER_ADDR'])) {
                $this->serverIp = $_SERVER['SERVER_ADDR'];
            } else {
                $this->serverIp = gethostbyname(gethostname());
            }
        }
        return $this->serverIp;
    }

    public function getReqId()
    {
        if (!$this->reqId) {
            $this->reqId = md5(uniqid(gethostname(), true));
        }
        return $this->reqId;
    }

    /**
     * 设置全局唯一id,用来跟踪整个请求链路,一般不用外部传入,直接getReqId()自动生成
     * 有时候需要外部传入，用来跟踪从请求到nginx到fpm等
     * @param $reqId
     */
    public function setReqId($reqId)
    {
        if (!$this->reqId) {
            $this->reqId = $reqId;
        }
    }

    public function isAjax()
    {
        if (isset($_REQUEST['is_ajax'])) {
            return $_REQUEST['is_ajax'] != 0;
        }
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest';
    }

    /**
     * @param string $headerKey 多个单词可由连接符(-)拼接而成,如:X-Sig,X-Access-Token
     * @param $default
     * @param $format
     * @return null
     */
    public function header($headerKey, $default = null, $format = "htmlspecialchars|trim|strip_tags")
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerKey));
        $value = isset($_SERVER[$serverKey]) ? $_SERVER[$serverKey] : $default;
        return $this->format($value, $format);
    }

    /**
     * @param string $key 设置的key中若包含句点(.)或空格( ),则会被转换为下划线(_)
     * @return null
     */
    public function cookie($key)
    {
        $key = str_replace(['.', ' '], '_', $key);
        return isset($_COOKIE[$key]) ? $_COOKIE[$key] : null;
    }

    public function getMethod()
    {
        return isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
    }

    public function isPost()
    {
        return strtoupper($this->getMethod()) == "POST" ? true : false;
    }

    protected function format($value, $format = "htmlspecialchars|trim|strip_tags")
    {
        if (is_array($value) || is_object($value) || is_null($value)) {
            return $value;
        }
        $format_arr = explode("|", $format);
        foreach ($format_arr as $func) {
            $func = trim($func);
            $value = call_user_func($func, $value);
        }
        return $value;
    }
}