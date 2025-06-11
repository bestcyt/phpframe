<?php

namespace Fw;

class View
{
    use InstanceTrait;

    protected static $vars;

    private $viewExt = 'php';

    public function assign($key, $value)
    {
        self::$vars[$key] = $value;
        return $this;
    }

    /**
     * @param array $parameters
     * @param null $view
     */
    public function display(array $parameters=[], $view=null){
        foreach($parameters as $key=>$val){
            $this->assign($key, $val);
        }

        $this->render($view);
    }

    /**
     * @param null $view
     * @param bool $isReturn 是否返回内容不进行渲染
     * @return bool|string
     */
    public function render($view = null, $isReturn = false)
    {
        $request = Request::getInstance();
        if (!$view) {
            $module = $request->getModule();
            if ($module) {
                $view = $module . '/';
            }
            $view .= $request->getController() . '/' . $request->getAction();
        }
        $viewFile = $this->getViewFile($view);
        self::$vars && extract(self::$vars);
        if ($isReturn) {
            ob_start();
            include $viewFile;
            self::$vars = null;
            $content = ob_get_contents();
            ob_end_clean();
            return $content;
        }
        include $viewFile;
        self::$vars = null;
        return true;
    }

    public function __get($name)
    {
        if(isset(self::$vars[$name])){
            return self::$vars[$name];
        }
        return null;
    }

    public function setViewExt($ext)
    {
        $this->viewExt = $ext;
    }

    public function getViewFile($view)
    {
        $view = trim($view, '/\\');
        $appPath = App::getInstance()->getCurrentAppPath();
        return $appPath . '/view/' . $view . '.' . $this->viewExt;
    }

}