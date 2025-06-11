<?php
namespace Fw;

use Fw\Counter\CounterInterface;
use Fw\Counter\MemcacheCnt;
use Fw\Counter\RedisCnt;
use Fw\Counter\RediscounterCnt;

class Counter
{
    const COUNTER_TYPE_MEMCACHE = 'memcache';
    const COUNTER_TYPE_REDIS = 'redis';
    const COUNTER_TYPE_REDISCOUNTER = 'rediscounter';

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    /**
     * @param string $counterType
     * @param array $config
     * @return CounterInterface
     * @throws Exception
     */
    public static function getProvider($counterType, array $config = [])
    {
        $counter = null;
        switch ($counterType) {
            case self::COUNTER_TYPE_MEMCACHE:
                $counter = MemcacheCnt::getInstance($config);
                break;
            case self::COUNTER_TYPE_REDIS:
                $counter = RedisCnt::getInstance($config);
                break;
            case self::COUNTER_TYPE_REDISCOUNTER:
                $counter = RediscounterCnt::getInstance($config);
                break;
            default:
                throw new Exception('invalid counter type');
                break;
        }
        return $counter;
    }
}