<?php
namespace Fw;

class Router
{
    use InstanceTrait;
    
    private static $config = [];

    public function add($srcPattern, $dstPattern)
    {
        self::$config[$srcPattern] = $dstPattern;
    }

    public function set($patterns)
    {
        if ($patterns && is_array($patterns)) {
            self::$config = self::$config ? array_merge(self::$config, $patterns) : $patterns;
        }
    }
    
    public function route($pathInfo)
    {
        if (self::$config) {
            //路由重写
            if (isset(self::$config[$pathInfo]) && strpos($pathInfo, '(') === false) {
                $pathInfo = self::$config[$pathInfo];
            } else {
                foreach (self::$config as $key => $val) {
                    $searchArr = ['(:any)', '(:seg)', '(:num)'];
                    $replaceArr = ['(.+)', '([^/]+)', '([0-9]+)'];
                    $key = str_replace($searchArr, $replaceArr, $key);
                    if (preg_match('#^' . $key . '$#', $pathInfo)) {
                        $pathInfo = preg_replace('#^' . $key . '$#', $val, $pathInfo);
                        break;
                    }
                }
            }
        }
        return $pathInfo;
    }
}