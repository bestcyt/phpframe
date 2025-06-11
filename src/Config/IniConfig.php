<?php
namespace Fw\Config;

use Fw\InstanceTrait;

class IniConfig implements ConfigInterface
{
    use InstanceTrait {
        getInstance as _getInstance;
    }

    private $configs = [];
    private $fileExt = '.ini';
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
            $result = parse_ini_file($file, true);
            if ($result === false) {
                return null;
            }
            $this->configs[$firstItem] = $result;
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