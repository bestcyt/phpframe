<?php
namespace Fw\Cache;

use Fw\Cache;
use Fw\Exception;
use Fw\InstanceTrait;
use Fw\Redis;

class RedisCache implements CacheInterface
{
    use InstanceTrait {
        getInstance as _getInstance;
    }

    private $cache;
    private $useSerialize = false;
    private $serializeType;

    private function __construct(array $config, array $options = [])
    {
        $this->cache = Redis::getInstance($config);
        if (!empty($options['use_serialize'])) {
            $this->useSerialize = true;
            if (isset($options['serialize_type'])) {
                $this->serializeType = $options['serialize_type'];
            }
        }
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
        $result = $this->cache->get($key);
        if ($this->useSerialize) {
            $result = $this->unserialize($result);
        }
        return $result;
    }

    public function set($key, $value, $ttl = 0)
    {
        if ($this->useSerialize) {
            $value = $this->serialize($value);
        }
        if ($ttl > 0) {
            return $this->cache->set($key, $value, $ttl);
        } else {
            return $this->cache->set($key, $value);
        }
    }

    public function delete($key)
    {
        $result = $this->cache->del($key);
        return $result == 1;
    }

    public function getMulti(array $keys, array $keyMaps = [], &$idsByMiss = [])
    {
        $result = [];
        $res = $this->cache->mget($keys);
        if ($res && is_array($res)) {
            foreach ($keys as $idx => $key) {
                if (isset($res[$idx]) && $res[$idx] !== false) {
                    $value = $res[$idx];
                    if ($this->useSerialize) {
                        $value = $this->unserialize($value);
                    }
                    $result[$key] = $value;
                }
            }
        }
        $idsByMiss = array_values(array_diff_key($keyMaps, $result));
        return $result;
    }

    public function setMulti(array $items, $ttl = 0)
    {
        foreach ($items as $key => $value) {
            if ($this->useSerialize) {
                $items[$key] = $this->serialize($value);
            }
        }
        if ($ttl > 0) {
            $this->cache->multi(Redis::PIPELINE);
            foreach ($items as $key => $value) {
                $this->cache->set($key, $value, $ttl);
            }
            $result = $this->cache->exec();
            return array_sum($result) == count($items);
        } else {
            return $this->cache->mset($items);
        }
    }

    public function deleteMulti(array $keys)
    {
        $result = $this->cache->del($keys);
        return count($keys) == $result;
    }



    private function serialize($value)
    {
        switch ($this->serializeType) {
            case Cache::SERIALIZE_TYPE_JSON:
                $value = json_encode($value, JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR);
                break;
            case Cache::SERIALIZE_TYPE_MSGPACK:
                if (function_exists('msgpack_pack')) {
                    $value = msgpack_pack($value);
                } else {
                    throw new Exception('msgpack_pack() not exists');
                }
                break;
            default:
                $value = serialize($value);
                break;
        }
        return $value;
    }

    private function unserialize($value)
    {
        switch ($this->serializeType) {
            case Cache::SERIALIZE_TYPE_JSON:
                $value = json_decode($value, true);
                break;
            case Cache::SERIALIZE_TYPE_MSGPACK:
                if (function_exists('msgpack_unpack')) {
                    $value = msgpack_unpack($value);
                } else {
                    throw new Exception('msgpack_unpack() not exists');
                }
                break;
            default:
                $value = unserialize($value);
                break;
        }
        return $value;
    }


}