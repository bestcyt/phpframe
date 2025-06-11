<?php
namespace Fw\Counter;

use Fw\RedisCounter;

class RediscounterCnt implements CounterInterface
{
    private static $instances;
    private $counter;

    private function __construct(array $config)
    {
        $this->counter = RedisCounter::getInstance($config);
    }

    private function __clone()
    {
    }

    /**
     * @param array $config
     * @return self
     */
    public static function getInstance(array $config = [])
    {
        $key = md5(serialize($config));
        if (empty(self::$instances[$key])) {
            self::$instances[$key] = new static($config);
        }
        return self::$instances[$key];
    }

    /**
     * @param $key
     * @return mixed 获取不到计数返回0，获取失败返回false
     */
    public function get($key)
    {
        $result = $this->counter->get($key);
        if ($result === false) {
            if ($this->counter->getErrorCode() == 0) {
                return 0;
            }
        }
        return $result;
    }

    /**
     * @param $key
     * @param $count
     * @param int $ttl
     * @return bool
     */
    public function set($key, $count, $ttl = 0)
    {
        return $this->counter->set($key, $count);
    }

    /**
     * @param $key
     * @return bool
     */
    public function delete($key)
    {
        return $this->counter->delete($key);
    }

    /**
     * @param array $keys 计数key数组
     * @return mixed 返回key数组对应的计数值，按key数组的顺序返回，没有计数的认为计数为0，获取失败返回false
     */
    public function getMulti(array $keys)
    {
        $result = $this->counter->getMulti($keys);
        if (!is_array($result)) {
            return false;
        }
        $data = [];
        foreach ($result as $k => $value) {
            $data[$keys[$k]] = $value ? $value : 0;
        }
        unset($result);
        return $data;
    }

    /**
     * @param array $items 以key为键名,对应的计数count为键值的数组
     * @param int $ttl
     * @return bool
     */
    public function setMulti(array $items, $ttl = 0)
    {
        return $this->counter->setMulti($items);
    }

    /**
     * @param array $keys
     * @return bool 所有key都删除成功才返回true,只要其中有一个key删除失败就返回false
     */
    public function deleteMulti(array $keys)
    {
        $result = $this->counter->deleteMulti($keys);
        if ($result == 0 && $this->counter->getErrorCode() > 0) {
            return false;
        }
        return true;
    }

    /**
     * @param $key
     * @param int $step
     * @param int $ttl
     * @return mixed 操作成功返回当前计数，失败返回false
     */
    public function incr($key, $step = 1, $ttl = 0)
    {
        return $this->counter->incr($key, $step);
    }

    /**
     * @param $key
     * @param int $step
     * @param int $ttl
     * @return mixed 操作成功返回当前计数，失败返回false
     */
    public function decr($key, $step = 1, $ttl = 0)
    {
        return $this->counter->decr($key, $step);
    }

    public function getLastError()
    {
        return $this->counter->getLastError();
    }
}