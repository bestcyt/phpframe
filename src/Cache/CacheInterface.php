<?php
namespace Fw\Cache;

interface CacheInterface
{
    public static function getInstance(array $config = [], array $options = []);

    /**
     * @param $key
     * @return mixed 获取不到缓存返回false
     */
    public function get($key);

    /**
     * @param $key
     * @param $data
     * @param int $ttl
     * @return bool
     */
    public function set($key, $data, $ttl = 0);

    /**
     * @param $key
     * @return bool
     */
    public function delete($key);

    /**
     * @param array $keys 缓存key数组
     * @param array $keyMaps 业务ID与实际缓存key的映射,键名为实际缓存key,键值为业务ID
     * @param array $idsByMiss 未命中缓存的业务ID
     * @return array 返回有获取到值的key的数据,获取不到对应值的key忽略
     */
    public function getMulti(array $keys, array $keyMaps = [], &$idsByMiss = []);

    /**
     * @param array $items 以缓存key为键名,对应的缓存value为键值的数组
     * @param int $ttl
     * @return bool
     */
    public function setMulti(array $items, $ttl = 0);

    /**
     * @param array $keys
     * @return bool 所有key都删除成功才返回true,只要其中有一个key删除失败就返回false
     */
    public function deleteMulti(array $keys);
}