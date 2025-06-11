<?php
namespace Fw;

use Fw\Config\ConfigInterface;
use Fw\Config\IniConfig;
use Fw\Config\PhpConfig;

class Config
{
    const TYPE_PHP = 'php';
    const TYPE_INI = 'ini';

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    /**
     * @param $type
     * @param $path
     * @return bool|null|ConfigInterface
     * @throws Exception
     */
    public static function getProvider($type, $path)
    {
        $config = null;
        switch ($type) {
            case self::TYPE_PHP:
                $config = PhpConfig::getInstance($path);
                break;
            case self::TYPE_INI:
                $config = IniConfig::getInstance($path);
                break;
            default:
                throw new Exception('invalid config type');
                break;
        }
        return $config;
    }
}