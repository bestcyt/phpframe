<?php

namespace Fw;

use Fw\Exception\ExitException;
use Fw\Exception\NotFoundException;

class Dispatcher
{
    use InstanceTrait;

    public function dispatch(Request $request)
    {
        $app = App::getInstance();
        $routePath = $request->getRoutePath();
        $modules = $app->getModules();
        $namespace = $app->getControllerNamespace();
        $path = $app->getControllerPath();

        //1.解析module,controller,action
        //seg1是指定支持的module的话,则以/module/controller/action进行匹配action文件,
        //否则以/controller/action进行匹配action文件。
        $uriSegments = explode('/', trim($routePath, '/'));
        $module = '';
        $controller = '';
        $action = '';
        $defaultSeg = 'index';
        $defaultFormattedSeg = 'Index';
        $seg1 = strtolower(trim(array_shift($uriSegments)));
        $seg2 = strtolower(trim(array_shift($uriSegments)));
        $seg3 = strtolower(trim(array_shift($uriSegments)));
        $formattedSeg1 = $seg1 == '' ? $defaultFormattedSeg : $app->formatUnderScoreToStudlyCaps($seg1);
        $formattedSeg2 = $seg2 == '' ? $defaultFormattedSeg : $app->formatUnderScoreToStudlyCaps($seg2);
        $formattedSeg3 = $seg3 == '' ? $defaultFormattedSeg : $app->formatUnderScoreToStudlyCaps($seg3);
        if ($seg1 != '' && in_array($seg1, $modules)) {
            //匹配/module/controller/action
            //下划线开头的action不允许通过url直接访问
            if ($formattedSeg3[0] == '_') {
                throw new NotFoundException('disallowed action.');
            } elseif (is_file($path . '/' . $formattedSeg1 . '/' . $formattedSeg2 . '/' . $formattedSeg3 . '.php')) {
                if ($seg2 != '' && $seg3 != '') {
                    $module = $seg1;
                    $controller = $seg2;
                    $action = $seg3;
                } elseif ($seg2 != '') {
                    $module = $seg1;
                    $controller = $seg2;
                    $action = $defaultSeg;
                } else {
                    $module = $seg1;
                    $controller = $defaultSeg;
                    $action = $defaultSeg;
                }
            }
        } else {
            //匹配/controller/action
            //下划线开头的action不允许通过url直接访问
            if ($formattedSeg2[0] == '_') {
                throw new NotFoundException('disallowed action.');
            } elseif (is_file($path . '/' . $formattedSeg1 . '/' . $formattedSeg2 . '.php')) {
                if ($seg1 != '' && $seg2 != '') {
                    $controller = $seg1;
                    $action = $seg2;
                } elseif ($seg1 != '') {
                    $controller = $seg1;
                    $action = $defaultSeg;
                } else {
                    $controller = $defaultSeg;
                    $action = $defaultSeg;
                }
                $seg3 == '' || array_unshift($uriSegments, $seg3);
            }
        }

        //特殊处理
        if (preg_match("/^\/merchant\d+\/tech\d+\/.*$/", $routePath)) {
            preg_match_all("/\/merchant(\d+)\/tech(\d+)\/(.*)/", $routePath, $matches);
            $_GET["b"] = $matches[1][0];
            $_GET["l"] = $matches[2][0];
            $module = $seg1;
            $controller = $seg2;
            $action = $seg3;
        } else {
            //module允许为空,但controller和action不允许为空
            if ($controller == '' || $action == '') {
                throw new NotFoundException('action not exists.');
            }
        }
        $request->setModule($module);
        $request->setController($controller);
        $request->setAction($action);
        $request->setParams($uriSegments);

        //引入action文件
        $moduleNamespace = '';
        if ($module) {
            $moduleNamespace = $app->formatUnderScoreToStudlyCaps($module) . '\\';
        }
        $className = $app->formatUnderScoreToStudlyCaps($controller) . '\\' . $app->formatUnderScoreToStudlyCaps($action);
        $fullClassName = $namespace . $moduleNamespace . $className;
        if (preg_match("/^\/merchant\d+\/tech\d+\/.*$/", $routePath)) {
            $fullClassName=$namespace."Merchant\\TechCommon\\".$app->formatUnderScoreToStudlyCaps($action);
        }

        if ($controller && $action && class_exists($fullClassName)) {
            $obj = new $fullClassName();
            if (method_exists($obj, 'main') && is_callable([$obj, 'main'])) {
                if (method_exists($obj, 'before')) {
                    $obj->before();
                }
                $obj->main();
                if (method_exists($obj, 'after')) {
                    //标识after()函数已调用,主要用于Response类的stop()函数中做判断
                    $obj->calledAfterFunction = true;
                    $obj->after();
                }
            } else {
                throw new NotFoundException('action has no main().');
            }
        } else {
            throw new NotFoundException('action not exists.');
        }

    }


}