<?php
namespace Fw\Cache;

use Fw\InstanceTrait;
use Fw\Memcache;

class MemcacheCache implements CacheInterface
{
    use InstanceTrait {
        getInstance as _getInstance;
    }

    private $cache;

    private function __construct(array $config)
    {
        $this->cache = Memcache::getInstance($config);
    }

    /**
     * @param array $config
     * @param array $options
     * @return self
     */
    public static function getInstance(array $config = [], array $options = [])
    {
        return self::_getInstance($config, $options);
    }

    public function get($key)
    {
        return $this->cache->get($key);
    }

    public function set($key, $value, $ttl = 0)
    {
        return $this->cache->set($key, $value, $ttl);
    }

    public function delete($key)
    {
        return $this->cache->delete($key);
    }

    public function getMulti(array $keys, array $keyMaps = [], &$idsByMiss = [])
    {
        $result = $this->cache->getMulti($keys);
        if (!$result) {
            $result = [];
        }
        $idsByMiss = array_values(array_diff_key($keyMaps, $result));
        return $result;
    }

    public function setMulti(array $items, $ttl = 0)
    {
        return $this->cache->setMulti($items, $ttl);
    }

    public function deleteMulti(array $keys)
    {
        $result = true;
        $deletedRes = $this->cache->deleteMulti($keys);
        if ($deletedRes && is_array($deletedRes)) {
            foreach ($deletedRes as $key => $res) {
                if ($res !== true && $res != \Memcached::RES_NOTFOUND) {
                    $result = false;
                }
            }
        }
        return $result;
    }


}