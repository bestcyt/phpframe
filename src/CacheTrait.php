<?php
namespace Fw;

trait CacheTrait
{
    use InstanceTrait;

    private $cache;
    private $levelOneCache;

    //需要在具体的类中赋值的属性
    protected $cacheType;
    protected $cacheConfig;
    protected $ttl = 0;
    protected $prefixKey;
    protected $useLevelOneCache = false;

    //一级缓存
    protected $levelOneCacheType = Cache::CACHE_TYPE_ARRAY;
    protected $levelOneCacheConfig = ['max_item_count' => 1000];
    protected $levelOneCacheTtl = 0;

    //当使用的cache引擎本身不支持序列化时生效，所以目前仅对RedisCache生效
    protected $useSerialize = false;
    protected $serializeType = Cache::SERIALIZE_TYPE_PHP;

    protected function getCache()
    {
        if (!$this->cache) {
            if (!$this->cacheConfig) {
                $this->cacheConfig = [];
            }
            $options = [];
            if ($this->useSerialize && $this->cacheType == Cache::CACHE_TYPE_REDIS) {
                $options['use_serialize'] = true;
                $options['serialize_type'] = $this->serializeType;
            }
            $this->cache = Cache::getProvider($this->cacheType, $this->cacheConfig, $options);

        }
        return $this->cache;
    }

    protected function getLevelOneCache()
    {
        if (!$this->levelOneCache) {
            if (!$this->levelOneCacheConfig) {
                $this->levelOneCacheConfig = [];
            }
            $options = [];
            if ($this->useSerialize && $this->levelOneCacheType == Cache::CACHE_TYPE_REDIS) {
                $options['use_serialize'] = true;
                $options['serialize_type'] = $this->serializeType;
            }
            $this->levelOneCache = Cache::getProvider($this->levelOneCacheType, $this->levelOneCacheConfig, $options);
        }
        return $this->levelOneCache;
    }

    protected function useLevelOneCache()
    {
        //在cli模式下不启用一级缓存
        return $this->useLevelOneCache && App::getInstance()->getAppMode() != App::MODE_CONSOLE;
    }

    public function key($id)
    {
        return $this->prefixKey . $id;
    }

    public function get($id)
    {
        $result = false;
        if ($this->useLevelOneCache()) {
            $result = $this->getLevelOneCache()->get($this->key($id));
        }
        if ($result === false) {
            $result = $this->getCache()->get($this->key($id));
            if ($this->useLevelOneCache() && $result !== false) {
                $this->getLevelOneCache()->set($this->key($id), $result, $this->levelOneCacheTtl);
            }
        }
        return $result;
    }

    public function set($id, $value, $ttl = 0)
    {
        if ($ttl == 0) {
            $ttl = $this->ttl;
        }
        $result = $this->getCache()->set($this->key($id), $value, $ttl);
        if ($this->useLevelOneCache() && $result) {
            $this->getLevelOneCache()->set($this->key($id), $value, $this->levelOneCacheTtl);
        }
        return $result;
    }

    public function delete($id)
    {
        $result = $this->getCache()->delete($this->key($id));
        if ($this->useLevelOneCache() && $result) {
            $this->getLevelOneCache()->delete($this->key($id));
        }
        return $result;
    }

    public function getMulti(array $idArr, array &$idsByMiss = [], $returnIdToKey = false)
    {
        $keyMaps = [];
        $keys = [];
        foreach ($idArr as $id) {
            $key = $this->key($id);
            $keyMaps[$key] = $id;
            $keys[] = $key;
        }
        $oriKeyMaps = $keyMaps;

        $resultPart1 = [];
        if ($this->useLevelOneCache()) {
            $resultPart1 = $this->getLevelOneCache()->getMulti($keys, $keyMaps, $idsByMiss);
            $keyMaps = [];
            $keys = [];
            foreach ($idsByMiss as $id) {
                $key = $this->key($id);
                $keyMaps[$key] = $id;
                $keys[] = $key;
            }
        }

        $resultPart2 = [];
        if ($keys) {
            $resultPart2 = $this->getCache()->getMulti($keys, $keyMaps, $idsByMiss);
        }

        //避免结果中的索引是数字索引时被重置，采用"+"进行两个数组的合并
        $result = $resultPart1 + $resultPart2;
        if ($returnIdToKey) {
            $resultByIdToKey = [];
            foreach ($oriKeyMaps as $key => $id) {
                if (isset($result[$key])) {
                    $resultByIdToKey[$id] = $result[$key];
                }
            }
            $result = $resultByIdToKey;
        }
        return $result;
    }

    public function getMultiByIdToKey(array $idArr, array &$idsByMiss = [])
    {
        return $this->getMulti($idArr, $idsByMiss, true);
    }

    public function setMulti(array $items, $ttl = 0)
    {
        if ($ttl == 0) {
            $ttl = $this->ttl;
        }
        $itemsByFullCacheKey = [];
        foreach ($items as $id => $value) {
            $key = $this->key($id);
            $itemsByFullCacheKey[$key] = $value;
        }
        $result = $this->getCache()->setMulti($itemsByFullCacheKey, $ttl);
        if ($this->useLevelOneCache() && $result) {
            $this->getLevelOneCache()->setMulti($itemsByFullCacheKey, $this->levelOneCacheTtl);
        }
        return $result;
    }

    public function deleteMulti(array $idArr)
    {
        $keys = [];
        foreach ($idArr as $id) {
            $keys[] = $this->key($id);
        }
        $result = $this->getCache()->deleteMulti($keys);
        if ($this->useLevelOneCache() && $result) {
            $this->getLevelOneCache()->deleteMulti($keys);
        }
        return $result;
    }
}