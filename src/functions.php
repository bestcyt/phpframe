<?php
function escape_html($html)
{
    return htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
}

function get_view($template)
{
    $view = \Fw\View::getInstance();
    return $view->getViewFile($template);
}

function app_env($key)
{
    return \Fw\App::getInstance()->env($key);
}

function app_config($key)
{
    return \Fw\App::getInstance()->config($key);
}

function app_time()
{
    return \Fw\App::getCurrentTime();
}

function app_microtime()
{
    return \Fw\App::getCurrentMicrotime();
}

function app_logger()
{
    return \Fw\App::getInstance()->getLogger();
}

function app_root_path()
{
    return \Fw\App::getInstance()->getRootPath();
}
function app_env_label($label)
{
    return \Fw\App::getInstance()->getEnvLabel($label);
}