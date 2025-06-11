<?php
namespace Fw;

/**
 * 通过redis改造的计数器
 */
class RedisCounter
{
    use InstanceTrait {
        getInstance as private _getInstance;
    }

    /**
     * @var Redis
     */
    private $redisInstance;
    private $keyLength = 15; //rediscounter.conf文件中keylength减去1，默认15

    private function __construct(array $config)
    {
        if (isset($config['keylength'])) {
            $this->keyLength = $config['keylength'] - 1;
            unset($config['keylength']);
        }
        $this->redisInstance = Redis::getInstance($config);
    }

    private function isValidKey($key)
    {
        return strlen($key) <= $this->keyLength;
    }

    public static function getInstance(array $config)
    {
        return self::_getInstance($config);
    }

    public function get($key)
    {
        if (!$this->isValidKey($key)) {
            trigger_error('rediscounter invalid key(' . $key . ')', E_USER_WARNING);
            return false;
        }
        return $this->redisInstance->get($key);
    }

    public function set($key, $count)
    {
        if (!$this->isValidKey($key)) {
            trigger_error('rediscounter invalid key(' . $key . ')', E_USER_WARNING);
            return false;
        }
        $count = intval($count);
        return $this->redisInstance->set($key, $count);
    }

    public function incr($key, $step = 1)
    {
        if (!$this->isValidKey($key)) {
            trigger_error('rediscounter invalid key(' . $key . ')', E_USER_WARNING);
            return false;
        }
        $step = intval($step);
        return $this->redisInstance->incr($key, $step);
    }

    public function decr($key, $step = 1)
    {
        if (!$this->isValidKey($key)) {
            trigger_error('rediscounter invalid key(' . $key . ')', E_USER_WARNING);
            return false;
        }
        $step = intval($step);
        return $this->redisInstance->decr($key, $step);
    }

    public function getMulti(array $keys)
    {
        foreach ($keys as $key) {
            if (!$this->isValidKey($key)) {
                trigger_error('rediscounter invalid key(' . $key . ')', E_USER_WARNING);
                return false;
            }
        }
        return $this->redisInstance->mget($keys);
    }

    public function setMulti(array $items)
    {
        foreach ($items as $key => $value) {
            if (!$this->isValidKey($key)) {
                trigger_error('rediscounter invalid key(' . $key . ')', E_USER_WARNING);
                return false;
            }
            $items[$key] = intval($value);
        }
        $this->redisInstance->multi(Redis::MULTI);
        foreach ($items as $key => $value) {
            $this->redisInstance->set($key, $value);
        }
        $ret = $this->redisInstance->exec();
        return count($items) == array_sum($ret);
    }

    public function delete($key)
    {
        if (!$this->isValidKey($key)) {
            trigger_error('rediscounter invalid key(' . $key . ')', E_USER_WARNING);
            return false;
        }
        return $this->redisInstance->del($key);
    }

    public function deleteMulti(array $keys)
    {
        foreach ($keys as $key) {
            if (!$this->isValidKey($key)) {
                trigger_error('rediscounter invalid key(' . $key . ')', E_USER_WARNING);
                return false;
            }
        }
        return $this->redisInstance->del($keys);
    }

    public function getErrorCode()
    {
        return $this->redisInstance->getErrorCode();
    }

    public function getLastError()
    {
        return $this->redisInstance->getLastError();
    }
}