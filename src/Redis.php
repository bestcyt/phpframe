<?php
namespace Fw;
use Fw\Exception\RedisException;

/**
 * https://github.com/phpredis/phpredis/tree/2.2.8
 * php-redis version:2.2.8
 *
 * @method string get($key)
 * @method bool set($key, $value, $timeout = 0)
 * @method bool setex($key, $ttl, $value)
 * @method bool psetex($key, $ttl, $value)
 * @method bool setnx($key, $value)
 * @method int del(array|string $key1, $key2 = null, $keyN = null)
 * @method bool exists($key)
 * @method array mget(array $keys)
 * @method bool mset(array $items)
 * @method bool expire($key, $ttl)
 * @method bool expireAt($key, $timestamp)
 * @method int hSet($key, $hashKey, $value)
 * @method string hGet($key, $hashKey)
 * @method int hLen($key)
 * @method int hDel($key, $hashKey1, $hashKey2 = null, $hashKeyN = null)
 * @method array hKeys($key)
 * @method array hVals($key)
 * @method array hGetAll($key)
 * @method bool hExists($key, $hashKey)
 * @method bool hMset($key, $hashItems)
 * @method array hMGet($key, $hashKeys)
 * @method string lIndex($key, $index)
 * @method string lPop($key)
 * @method int lPush($key, $value1, $value2 = null, $valueN = null)
 * @method array lRange($key, $start, $end)
 * @method array lTrim($key, $start, $stop)
 * @method int lRem($key, $value, $count)
 * @method string rPop($key)
 * @method int rPush($key, $value1, $value2 = null, $valueN = null)
 * @method int lLen($key)
 * @method array blPop(array|string $keys, $timeout)
 * @method array brPop(array|string $keys, $timeout)
 * @method string rpoplpush($srcKey, $dstKey)
 * @method string brpoplpush($srcKey, $dstKey, $timeout)
 * @method int sAdd($key, $value1, $value2 = null, $valueN = null)
 * @method int sCard($key)
 * @method int setBit($key,$offset,$value)
 * @method int getBit($cache, $offset)
 * @method bool sIsMember($key, $value)
 * @method array sMembers($key)
 * @method int sRem($key, $member1, $member2 = null, $memberN = null)
 * @method int zAdd($key, $score1, $value1, $score2 = null, $value2 = null, $scoreN = null, $valueN = null)
 * @method int zCard($key)
 * @method float zIncrBy($key, $value, $member)
 * @method float zRank($key, $value)
 * @method array zRange($key, $start, $end, $withScores = false)
 * @method array zRevRange($key, $start, $end, $withScores = false)
 * @method array zRangeByScore($key, $start, $end, $options = [])
 * @method array zRevRangeByScore($key, $start, $end, $options = [])
 * @method int zRem($key, $member1, $member2 = null, $memberN = null)
 * @method int zRemRangeByScore($key, $start, $end)
 * @method float zScore($key, $member)
 * @method Redis multi($type = Redis::MULTI)
 * @method mixed exec()
 * @method mixed eval($lua,$key,$number)
 * @method string getLastError()
 */
class Redis
{
    use InstanceTrait {
        getInstance as private _getInstance;
    }

    private $masterRedis;
    private $slaveRedis;
    private $masterConfig = [];
    private $slaveConfig = [];
    //把要连接的host转成ip后再连接,默认转成ip,在配置中设置host_to_ip为false才不转
    private $hostToIp = true;
    private $defaultReadTimeout = 3; // 默认读超时时间，设置为3秒

    //读写分离时,读操作的方法名列表,方法名全部用小写字母,便于后续判断
    private $methodsByReadOp = ['get', 'exists', 'mget', 'hget', 'hlen', 'hkeys', 'hvals', 'hgetall', 'hexists',
        'hmget', 'lindex', 'lget', 'llen', 'lsize', 'lrange', 'lgetrange', 'scard', 'ssize', 'sdiff', 'sinter','getbit',
        'sismember', 'scontains', 'smembers', 'sgetmembers', 'srandmember', 'sunion', 'zcard', 'zsize',
        'zcount', 'zrange', 'zrangebyscore', 'zrevrangebyscore', 'zrangebylex', 'zrank', 'zrevrank', 'zrevrange',
        'zscore', 'zunion'];

    private static $errorLogCallback;

    const MULTI = \Redis::MULTI;
    const PIPELINE = \Redis::PIPELINE;

    const RW_TYPE_MASTER = 'm';
    const RW_TYPE_SLAVE = 's';

    private $inTrans = false;
    private $forceMaster = false;
    private $errorCode = 0;

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
        if (isset($config['master'])) {
            //支持读写分离的主从配置
            $this->masterConfig = $config['master'];
            if (!empty($config['slaves']) && is_array($config['slaves'])) {
                $randKey = array_rand($config['slaves']);
                $this->slaveConfig = $config['slaves'][$randKey];
            }
        } else {
            //单例配置
            $this->masterConfig = $config;
        }
        if (isset($config['host_to_ip']) && $config['host_to_ip'] == false) {
            $this->hostToIp = false;
        }
    }

    private function getRwType($rwType)
    {
        if ($rwType != self::RW_TYPE_SLAVE || $this->inTrans || $this->forceMaster || !$this->slaveConfig) {
            return self::RW_TYPE_MASTER;
        } else {
            return self::RW_TYPE_SLAVE;
        }
    }

    public static function setErrorLog(callable $callback)
    {
        self::$errorLogCallback = $callback;
    }

    private function errorLog(RedisException $e)
    {
        if (!self::$errorLogCallback || !is_callable(self::$errorLogCallback)) {
            if (isset($e->config['password'])) {
                unset($e->config['password']);
            }
            $logInfo = [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'method' => $e->method,
//                'params' => $e->params, //默认不把参数记录到错误日志
                'config' => $e->config,
                'rw_type' => $e->rwType
            ];
            App::getInstance()->getLogger()->error($logInfo, $e->logType);
        } else {
            call_user_func_array(self::$errorLogCallback, [$e]);
        }
    }

    private function dealError(\Exception $e, $method, $params, $rwType = null)
    {
        if ($this->getRwType($rwType) == self::RW_TYPE_MASTER) {
            $redisConfig = $this->masterConfig;

            //fix:修复抛出异常后的后续操作还是继续使用异常连接的问题
            //为了能够在抛出异常后的下一次操作中使用新的redis连接(达到重连的效果)
            $this->masterRedis = null;
        } else {
            $redisConfig = $this->slaveConfig;

            //fix:修复抛出异常后的后续操作还是继续使用异常连接的问题
            //为了能够在抛出异常后的下一次操作中使用新的redis连接(达到重连的效果)
            $this->slaveRedis = null;
        }

        $redisException = new RedisException($e->getMessage(), $e->getCode());
        $redisException->logType = LogType::REDIS;
        $redisException->method = $method;
        $redisException->params = $params;
        $redisException->config = $redisConfig;
        $redisException->rwType = $rwType;
        $this->errorLog($redisException);
    }

    private function getRedisConnect(array &$redisConfig)
    {
        $host = isset($redisConfig['host']) ? $redisConfig['host'] : '';
        if ($this->hostToIp) {
            $host = gethostbyname($host);
            $redisConfig['host_ip'] = $host;
        }
        $port = isset($redisConfig['port']) ? (int)$redisConfig['port'] : 0;
        $timeout = isset($redisConfig['timeout']) ? (float)$redisConfig['timeout'] : 0;
        $pconnect = isset($redisConfig['pconnect']) ? (bool)$redisConfig['pconnect'] : false;
        $password = isset($redisConfig['password']) ? (string)$redisConfig['password'] : '';
        $readTimeout = isset($redisConfig['read_timeout']) ? (int)$redisConfig['read_timeout'] : $this->defaultReadTimeout;
        $redis = new \Redis();
        if ($pconnect) {
            $connectResult = $redis->pconnect($host, $port, $timeout);
        } else {
            $connectResult = $redis->connect($host, $port, $timeout);
        }
        if ($connectResult && $password) {
            $redis->auth($password);
        }
        if($connectResult && $readTimeout) {
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, $readTimeout);
        }
        return $redis;
    }

    private function getMasterConnect()
    {
        if (!$this->masterRedis) {
            $this->masterRedis = $this->getRedisConnect($this->masterConfig);
        }
        return $this->masterRedis;
    }

    private function getSlaveConnect()
    {
        if (!$this->slaveRedis) {
            $this->slaveRedis = $this->getRedisConnect($this->slaveConfig);
        }
        return $this->slaveRedis;
    }

    private function connect($rwType = null)
    {
        if ($this->getRwType($rwType) == self::RW_TYPE_MASTER) {
            return $this->getMasterConnect();
        } else {
            return $this->getSlaveConnect();
        }
    }

    /**
     * @param array $config
     * @return static
     */
    public static function getInstance(array $config)
    {
        return self::_getInstance($config);
    }

    public function __call($method, $params)
    {
        $this->currentMethod = $method;
        $this->currentArgs = $params;
        $this->callBeforeExecuteCallback();
        $methodToLower = strtolower($method);
        $rwType = null;
        if ($this->slaveConfig) {
            //有从库配置的时候才进行读写分离
            $rwType = in_array($methodToLower, $this->methodsByReadOp) ? self::RW_TYPE_SLAVE : self::RW_TYPE_MASTER;
            if ($methodToLower == 'multi') {
                $this->inTrans = true;
            } elseif ($methodToLower == 'exec') {
                $this->inTrans = false;
            }
        }
        $redis = $this->connect($rwType);
        $result = false;
        $this->errorCode = 0;
        try {
            $result = call_user_func_array([$redis, $method], $params);
        } catch (\Exception $e) {
            $this->errorCode = $e->getCode();
            $this->dealError($e, $method, $params, $rwType);
        }
        $this->callAfterExecuteCallback();
        $this->forceMaster = false;
        return $result;
    }

    public function incr($key, $step = 1)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        $result = false;
        $rwType = null;
        $this->errorCode = 0;
        try {
            //写操作,单库还是有主从库配置的时候都操作主库
            $connect = $this->connect(self::RW_TYPE_MASTER);
            if ($step === 1) {
                $result = $connect->incr($key);
            } elseif (is_float($step)) {
                $result = $connect->incrByFloat($key, $step);
            } else {
                $result = $connect->incrBy($key, $step);
            }
        } catch (\Exception $e) {
            $this->errorCode = $e->getCode();
            $this->dealError($e, $this->currentMethod, $this->currentArgs, $rwType);
        }
        $this->callAfterExecuteCallback();
        $this->forceMaster = false;
        return $result;
    }

    public function decr($key, $step = 1)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        $result = false;
        $rwType = null;
        $this->errorCode = 0;
        try {
            //写操作,单库还是有主从库配置的时候都操作主库
            $connect = $this->connect(self::RW_TYPE_MASTER);
            if ($step === 1) {
                $result = $connect->decr($key);
            } elseif (is_float($step)) {
                $result = $connect->incrByFloat($key, $step * -1);
            } else {
                $result = $connect->decrBy($key, $step);
            }
        } catch (\Exception $e) {
            $this->errorCode = $e->getCode();
            $this->dealError($e, $this->currentMethod, $this->currentArgs, $rwType);
        }
        $this->callAfterExecuteCallback();
        $this->forceMaster = false;
        return $result;
    }

    public function hIncr($key, $hashKey, $step = 1)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        $result = false;
        $rwType = null;
        $this->errorCode = 0;
        try {
            //写操作,单库还是有主从库配置的时候都操作主库
            $connect = $this->connect(self::RW_TYPE_MASTER);
            if (is_float($step)) {
                $result = $connect->hIncrByFloat($key, $hashKey, $step);
            } else {
                $result = $connect->hIncrBy($key, $hashKey, $step);
            }
        } catch (\Exception $e) {
            $this->errorCode = $e->getCode();
            $this->dealError($e, $this->currentMethod, $this->currentArgs, $rwType);
        }
        $this->callAfterExecuteCallback();
        $this->forceMaster = false;
        return $result;
    }

    public function hDecr($key, $hashKey, $step = 1)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        $result = false;
        $rwType = null;
        $this->errorCode = 0;
        try {
            //写操作,单库还是有主从库配置的时候都操作主库
            $connect = $this->connect(self::RW_TYPE_MASTER);
            if (is_float($step)) {
                $result = $connect->hIncrByFloat($key, $hashKey, $step * -1);
            } else {
                $result = $connect->hIncrBy($key, $hashKey, $step * -1);
            }
        } catch (\Exception $e) {
            $this->errorCode = $e->getCode();
            $this->dealError($e, $this->currentMethod, $this->currentArgs, $rwType);
        }
        $this->callAfterExecuteCallback();
        $this->forceMaster = false;
        return $result;
    }

    public function scan(&$iterator, $pattern = null, $count = 0)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        $result = false;
        $rwType = null;
        $this->errorCode = 0;
        try {
            if ($this->slaveConfig) {
                //有从库配置的时候才进行读写分离
                $rwType = self::RW_TYPE_SLAVE;
            }
            $connect = $this->connect($rwType);
            $result = $connect->scan($iterator, $pattern, $count);
        } catch (\Exception $e) {
            $this->errorCode = $e->getCode();
            $this->dealError($e, $this->currentMethod, $this->currentArgs, $rwType);
        }
        $this->callAfterExecuteCallback();
        $this->forceMaster = false;
        return $result;
    }

    public function hScan($key, &$iterator, $pattern = null, $count = 0)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        $result = false;
        $rwType = null;
        $this->errorCode = 0;
        try {
            if ($this->slaveConfig) {
                //有从库配置的时候才进行读写分离
                $rwType = self::RW_TYPE_SLAVE;
            }
            $connect = $this->connect($rwType);
            $result = $connect->hScan($key, $iterator, $pattern, $count);
        } catch (\Exception $e) {
            $this->errorCode = $e->getCode();
            $this->dealError($e, $this->currentMethod, $this->currentArgs, $rwType);
        }
        $this->callAfterExecuteCallback();
        $this->forceMaster = false;
        return $result;
    }

    public function sScan($key, &$iterator, $pattern = null, $count = 0)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        $result = false;
        $rwType = null;
        $this->errorCode = 0;
        try {
            if ($this->slaveConfig) {
                //有从库配置的时候才进行读写分离
                $rwType = self::RW_TYPE_SLAVE;
            }
            $connect = $this->connect($rwType);
            $result = $connect->sScan($key, $iterator, $pattern, $count);
        } catch (\Exception $e) {
            $this->errorCode = $e->getCode();
            $this->dealError($e, $this->currentMethod, $this->currentArgs, $rwType);
        }
        $this->callAfterExecuteCallback();
        $this->forceMaster = false;
        return $result;
    }

    public function zScan($key, &$iterator, $pattern = null, $count = 0)
    {
        $this->currentMethod = __METHOD__;
        $this->currentArgs = func_get_args();
        $this->callBeforeExecuteCallback();
        $result = false;
        $rwType = null;
        $this->errorCode = 0;
        try {
            if ($this->slaveConfig) {
                //有从库配置的时候才进行读写分离
                $rwType = self::RW_TYPE_SLAVE;
            }
            $connect = $this->connect($rwType);
            $result = $connect->zScan($key, $iterator, $pattern, $count);
        } catch (\Exception $e) {
            $this->errorCode = $e->getCode();
            $this->dealError($e, $this->currentMethod, $this->currentArgs, $rwType);
        }
        $this->callAfterExecuteCallback();
        $this->forceMaster = false;
        return $result;
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function forceMaster()
    {
        $this->forceMaster = true;
        return $this;
    }

    public static function setBeforeExecuteCallback(callable $callback)
    {
        self::$beforeExecuteCallback = $callback;
    }

    public static function setAfterExecuteCallback(callable $callback)
    {
        self::$afterExecuteCallback = $callback;
    }

}