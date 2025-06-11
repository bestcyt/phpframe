<?php
namespace Fw\Counter;

use Fw\Memcache;

class MemcacheCnt implements CounterInterface
{
    private static $instances;
    private $counter;

    private function __construct(array $config)
    {
        $this->counter = Memcache::getInstance($config);
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
            if ($this->counter->getResultCode() == \Memcached::RES_NOTFOUND) {
                $result = 0;
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
        return $this->counter->set($key, $count, $ttl);
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
        foreach ($keys as $key) {
            if (empty($result[$key])) {
                $result[$key] = 0;
            }
        }
        return $result;
    }

    /**
     * @param array $items 以key为键名,对应的计数count为键值的数组
     * @param int $ttl
     * @return bool
     */
    public function setMulti(array $items, $ttl = 0)
    {
        return $this->counter->setMulti($items, $ttl);
    }

    /**
     * @param array $keys
     * @return bool 所有key都删除成功才返回true,只要其中有一个key删除失败就返回false
     */
    public function deleteMulti(array $keys)
    {
        $result = true;
        $deletedRes = $this->counter->deleteMulti($keys);
        if ($deletedRes && is_array($deletedRes)) {
            foreach ($deletedRes as $key => $res) {
                if ($res !== true && $res != \Memcached::RES_NOTFOUND) {
                    $result = false;
                }
            }
        }
        return $result;
    }

    /**
     * @param $key
     * @param int $step
     * @param int $ttl
     * @return mixed 操作成功返回当前计数，失败返回false
     */
    public function incr($key, $step = 1, $ttl = 0)
    {
        return $this->counter->increment($key, $step, $ttl);
    }

    /**
     * @param $key
     * @param int $step
     * @param int $ttl
     * @return mixed 操作成功返回当前计数，失败返回false
     */
    public function decr($key, $step = 1, $ttl = 0)
    {
        return $this->counter->decrement($key, $step, $ttl);
    }
}