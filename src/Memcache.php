<?php
namespace Fw;

use Fw\Exception\MemcacheException;

class Memcache
{
    use InstanceTrait {
        getInstance as _getInstance;
    }

    private $config = [];
    private $mc;

    private $isGreaterThanOrEqualV3 = false; //用于解决memcached扩展大于等于v3版本不兼容的函数

    private $resultCodeByNotNeedWriteLog = array(
        \Memcached::RES_SUCCESS, \Memcached::RES_UNKNOWN_READ_FAILURE, \Memcached::RES_DATA_EXISTS,
        \Memcached::RES_NOTSTORED, \Memcached::RES_NOTFOUND, \Memcached::RES_PARTIAL_READ, \Memcached::RES_END,
        \Memcached::RES_BUFFERED, \Memcached::RES_BAD_KEY_PROVIDED
    );

    private static $errorLogCallback;

    private static $beforeExecuteCallback = null;
    private static $afterExecuteCallback = null;

    public $currentMethod; //当前调用的是哪个方法，供callBeforeExecuteCallback和callAfterExecuteCallback用
    public $currentArgs; //当前调用方法传递的是哪些参数，供callBeforeExecuteCallback和callAfterExecuteCallback用

    private function callBeforeExecuteCallback()
    {
        if (self::$beforeExecuteCallback && is_callable(self::$beforeExecuteCallback)) {
            call_user_func_array(self::$beforeExecuteCallback, [$this]);
        }
    }

    private function callAfterExecuteCallback()
    {
        if (self::$afterExecuteCallback && is_callable(self::$afterExecuteCallback)) {
            call_user_func_array(self::$afterExecuteCallback, [$this]);
        }
    }

    private function __construct(array $config)
    {
        //twemproxy memcache不支持二进制协议
        if (isset($config['use_twemproxy']) && $config['use_twemproxy']) {
            $config['binary_protocol'] = false;
        }

        $this->config = $config;
        $servers = [];
        $serverArr = isset($config['servers']) ? $config['servers'] : [];
        foreach ($serverArr as $server) {
            $host = isset($server['host']) ? $server['host'] : '';
            $port = isset($server['port']) ? (int)$server['port'] : 0;
            $weight = isset($server['weight']) ? (int)$server['weight'] : 0;
            $item = [$host, $port];
            if ($weight) {
                $item[] = $weight;
            }
            $servers[] = $item;
        }
        $connectTimeout = isset($config['connect_timeout']) ? (int)$config['connect_timeout'] : 0;
        if (!$this->mc) {
            $options = [
                \Memcached::OPT_DISTRIBUTION => \Memcached::DISTRIBUTION_CONSISTENT,
                \Memcached::OPT_LIBKETAMA_COMPATIBLE => true
            ];
            if (!isset($config['binary_protocol']) || $config['binary_protocol'] == true) {
                $options[\Memcached::OPT_BINARY_PROTOCOL] = true;
            }
            if ($connectTimeout > 0) {
                $options[\Memcached::OPT_CONNECT_TIMEOUT] = $connectTimeout;
            }
            $persistent = isset($config['persistent']) ? (bool)$config['persistent'] : false;
            if ($persistent) {
                $persistentId = md5(serialize($servers));
                $mc = new \Memcached($persistentId);
            } else {
                $mc = new \Memcached();
            }
            if (!$mc->getServerList()) {
                $mc->setOptions($options);
                $mc->addServers($servers);
            }
            $this->mc = $mc;
        }
        $this->isGreaterThanOrEqualV3 = defined('\Memcached::GET_EXTENDED');
    }

    public static function getInstance(array $config)
    {
        return self::_getInstance($config);
    }

    public static function setErrorLog(callable $callback)
    {
        self::$errorLogCallback = $callback;
    }

    private function errorLog(MemcacheException $e)
    {
        if (!self::$errorLogCallback || !is_callable(self::$errorLogCallback)) {
            $logInfo = [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'method' => $e->method,
                'config' => $e->config,
            ];
            App::getInstance()->getLogger()->error($logInfo, $e->logType);
        } else {
            call_user_func_array(self::$errorLogCallback, [$e]);
        }
    }

    private function dealError($method, $params)
    {
        $code = $this->getResultCode();
        if (!in_array($code, $this->resultCodeByNotNeedWriteLog)) {
            $mcException = new MemcacheException($this->getResultMessage(), $code);
            $mcException->method = $method;
            $mcException->params = $params;
            $mcException->config = $this->config;
            $mcException->logType = LogType::MEMCACHE;
            $this->errorLog($mcException);
        }
    }

    public function get($key, callable $cacheCallback = null, &$casToken = null)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        //fix:当指定了$cacheCallback或$casToken参数时，memcached扩展就会发送gets命令
        //为了避免redis-uuid不支持gets命令而导致无法正常返回的问题，进行参数个数判断，再决定调用形式。
        if (func_num_args() == 1) {
            $result = $this->mc->get($key);
        } else {
            if ($this->isGreaterThanOrEqualV3) {
                $flags = \Memcached::GET_EXTENDED;
                $res = $this->mc->get($key, $cacheCallback, $flags);
                $result = false;
                if ($res) {
                    $result = isset($res['value']) ? $res['value'] : false;
                    $casToken = isset($res['cas']) ? $res['cas'] : null;
                }
            } else {
                $result = $this->mc->get($key, $cacheCallback, $casToken);
            }
        }
        if ($result === false) {
            $this->dealError($this->currentMethod, $this->currentArgs);
        }
        $this->callAfterExecuteCallback();
        return $result;
    }

    public function getMulti(array $keys, array &$casTokens = null)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        if ($this->isGreaterThanOrEqualV3) {
            if (func_num_args() == 1) {
                $result = $this->mc->getMulti($keys);
            } else {
                $flags = \Memcached::GET_EXTENDED;
                $res = $this->mc->getMulti($keys, $flags);
                $result = [];
                if ($res) {
                    $casTokens = [];
                    foreach ($res as $key => $item) {
                        $result[$key] = isset($item['value']) ? $item['value'] : null;
                        if (isset($item['cas'])) {
                            $casTokens[$key] = $item['cas'];
                        }
                    }
                }
            }
        } else {
            $result = $this->mc->getMulti($keys, $casTokens);
        }
        if ($result === false) {
            $this->dealError($this->currentMethod, $this->currentArgs);
        }
        $this->callAfterExecuteCallback();
        return $result;
    }

    public function set($key, $value, $expiration = 0)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        $result = $this->mc->set($key, $value, $expiration);
        if ($result === false) {
            $this->dealError($this->currentMethod, $this->currentArgs);
        }
        $this->callAfterExecuteCallback();
        return $result;
    }

    public function setMulti(array $items, $expiration = 0)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        $result = $this->mc->setMulti($items, $expiration);
        if ($result === false) {
            $this->dealError($this->currentMethod, $this->currentArgs);
        }
        $this->callAfterExecuteCallback();
        return $result;
    }

    public function add($key, $value, $expiration = 0)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        $result = $this->mc->add($key, $value, $expiration);
        if ($result === false) {
            $this->dealError($this->currentMethod, $this->currentArgs);
        }
        $this->callAfterExecuteCallback();
        return $result;
    }

    public function cas($casToken, $key, $value, $expiration = 0)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        $result = $this->mc->cas($casToken, $key, $value, $expiration);
        if ($result === false) {
            $this->errorLog($this->currentMethod, $this->currentArgs);
        }
        $this->callAfterExecuteCallback();
        return $result;
    }

    public function delete($key, $time = 0)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        $result = $this->mc->delete($key, $time);
        if ($result === false) {
            $this->dealError($this->currentMethod, $this->currentArgs);
        }
        $this->callAfterExecuteCallback();
        return $result;
    }

    public function deleteMulti(array $keys, $time = 0)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        $result = $this->mc->deleteMulti($keys, $time);
        if ($result === false) {
            $this->dealError($this->currentMethod, $this->currentArgs);
        }
        $this->callAfterExecuteCallback();
        return $result;
    }

    public function increment($key, $step = 1, $expiration = 0)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        $useBinaryProtocol = $this->mc->getOption(\Memcached::OPT_BINARY_PROTOCOL);
        $initialValue = $step;
        if ($useBinaryProtocol) {
            $result = $this->mc->increment($key, $step, $initialValue, $expiration);
        } else {
            $result = $this->mc->increment($key, $step);
            if ($result === false && $this->mc->getResultCode() == \Memcached::RES_NOTFOUND) {
                $res1 = $this->mc->add($key, $initialValue, $expiration);
                if ($res1 == true) {
                    $result = $initialValue;
                } elseif ($this->mc->getResultCode() == \Memcached::RES_NOTSTORED) {
                    $result = $this->mc->increment($key, $step);
                }
            }
        }
        if ($result === false) {
            $this->dealError($this->currentMethod, $this->currentArgs);
        }
        $this->callAfterExecuteCallback();
        return $result;
    }

    public function decrement($key, $step = 1, $expiration = 0)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        $useBinaryProtocol = $this->mc->getOption(\Memcached::OPT_BINARY_PROTOCOL);
        $initialValue = 0;
        if ($useBinaryProtocol) {
            $result = $this->mc->decrement($key, $step, $initialValue, $expiration);
        } else {
            $result = $this->mc->decrement($key, $step);
            if ($result === false && $this->mc->getResultCode() == \Memcached::RES_NOTFOUND) {
                $res1 = $this->mc->add($key, $initialValue, $expiration);
                if ($res1 == true) {
                    $result = $initialValue;
                } elseif ($this->mc->getResultCode() == \Memcached::RES_NOTSTORED) {
                    $result = $this->mc->decrement($key, $step);
                }
            }
        }
        if ($result === false) {
            $this->dealError($this->currentMethod, $this->currentArgs);
        }
        $this->callBeforeExecuteCallback();
        return $result;
    }

    public function replace($key, $value, $expiration = 0)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        $result = $this->mc->replace($key, $value, $expiration);
        if ($result === false) {
            $this->dealError($this->currentMethod, $this->currentArgs);
        }
        $this->callBeforeExecuteCallback();
        return $result;
    }

    public function touch($key, $expiration)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        $result = $this->mc->touch($key, $expiration);
        if ($result === false) {
            $this->dealError($this->currentMethod, $this->currentArgs);
        }
        $this->callBeforeExecuteCallback();
        return $result;
    }


    public function getResultCode()
    {
        return $this->mc->getResultCode();
    }

    public function getResultMessage()
    {
        return $this->mc->getResultMessage();
    }

    public static function setBeforeExecuteCallback(callable $callback)
    {
        self::$beforeExecuteCallback = $callback;
    }

    public static function setAfterExecuteCallback(callable $callback)
    {
        self::$afterExecuteCallback = $callback;
    }

    public function getMcInstance()
    {
        return $this->mc;
    }

}
