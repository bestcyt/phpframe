<?php
namespace Fw\Counter;

interface CounterInterface
{
    public static function getInstance(array $config = []);

    /**
     * @param $key
     * @return mixed 获取不到计数返回0，获取失败返回false
     */
    public function get($key);

    /**
     * @param $key
     * @param $count
     * @param int $ttl
     * @return bool
     */
    public function set($key, $count, $ttl = 0);

    /**
     * @param $key
     * @return bool
     */
    public function delete($key);

    /**
     * @param array $keys 计数key数组
     * @return mixed 返回key数组对应的计数值，按key数组的顺序返回，没有计数的认为计数为0，获取失败返回false
     */
    public function getMulti(array $keys);

    /**
     * @param array $items 以key为键名,对应的计数count为键值的数组
     * @param int $ttl
     * @return bool
     */
    public function setMulti(array $items, $ttl = 0);

    /**
     * @param array $keys
     * @return bool 所有key都删除成功才返回true,只要其中有一个key删除失败就返回false
     */
    public function deleteMulti(array $keys);

    /**
     * @param $key
     * @param int $step
     * @param int $ttl
     * @return mixed 操作成功返回当前计数，失败返回false
     */
    public function incr($key, $step = 1, $ttl = 0);

    /**
     * @param $key
     * @param int $step
     * @param int $ttl
     * @return mixed 操作成功返回当前计数，失败返回false
     */
    public function decr($key, $step = 1, $ttl = 0);
}