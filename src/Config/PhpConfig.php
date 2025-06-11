<?php
namespace Fw\Config;

use Fw\InstanceTrait;

class PhpConfig implements ConfigInterface
{
    use InstanceTrait {
        getInstance as _getInstance;
    }

    private $configs = [];
    private $fileExt = '.php';
    private $path;

    private function __construct($path)
    {
        $this->path = rtrim($path, '/\\');
    }

    public static function getInstance($path)
    {
        return self::_getInstance($path);
    }

    public function get($key)
    {
        $keySegments = explode('.', $key);
        $firstItem = array_shift($keySegments);
        if (!isset($this->configs[$firstItem])) {
            $file = $this->path . '/' . $firstItem . $this->fileExt;
            if (!is_file($file)) {
                return null;
            }
            $this->configs[$firstItem] = include $file;
        }
        $data = $this->configs[$firstItem];
        foreach ($keySegments as $item) {
            if (!isset($data[$item])) {
                return null;
            }
            $data = $data[$item];
        }
        return $data;
    }
}