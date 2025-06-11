<?php
namespace Fw;

class ReflectionDoc
{
    use InstanceTrait;

    public function parseLines($doc)
    {
        preg_match_all('#^\s*\*(.*)#m', $doc, $matches);
        $lines = $matches[1];
        array_pop($lines);
        return $lines;
    }

    public function parseDoc($doc)
    {
        preg_match_all('#^\s*\*(.*)#m', $doc, $matches);
        $lines = $matches[1];
        array_pop($lines);
        $docArr = [];
        $curKey = '';
        $subIndex = 0;
        foreach ($lines as $idx => $line) {
            $line = trim($line);
            if (!$line) {
                continue;
            }
            if (substr($line, 0, 1) == '@') {
                $keyEnd = strpos($line, ' ');
                $curKey = $keyEnd !== false ? substr($line, 1, $keyEnd - 1) : substr($line, 1);
                if ($curKey) {
                    $curValue = $keyEnd !== false ? substr($line, $keyEnd + 1) : '';
                    if (isset($docArr[$curKey])) {
                        $subIndex++;
                    } else {
                        $subIndex = 0;
                    }
                    $docArr[$curKey][$subIndex] = $curValue;
                    continue;
                }
            }
            if ($curKey && isset($docArr[$curKey][$subIndex])) {
                $docArr[$curKey][$subIndex] .= PHP_EOL . $line;
            }
        }
        return $docArr;
    }

    public function parse($className)
    {
        $app = App::getInstance();
        $ref = new \ReflectionClass($className);
        $shortClassName = $ref->getShortName();
        $classDocArr = $this->parseDoc($ref->getDocComment());
        $refMethods = $ref->getMethods(\ReflectionMethod::IS_PUBLIC);
        $methods = [];
        foreach ($refMethods as $refMethod) {
            $name = $refMethod->getName();
            if ($name[0] != '_') {
                $methods[] = [
                    'name' => $name,
                    '_name' => $app->formatCamelCaseToUnderScore($name),
                    'doc' => $this->parseDoc($refMethod->getDocComment())
                ];
            }
        }
        return [
            'name' => $shortClassName,
            'doc' => $classDocArr,
            'methods' => $methods
        ];
    }
}