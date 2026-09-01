<?php
namespace Ypf\Utils;

class ClassUtil
{
    const ALIAS = [
        'allow' => 'method',
        'rest' => 'method',
        'restful' => 'method',
    ];

    public static function parseDoc(string $docBlock): array 
    {
        $parsed = [];
        if (empty($docBlock)) {
            return $parsed;
        }

        $lines = explode("\n", $docBlock);
        foreach ($lines as $line) {
            $pos = strpos($line, '@');
            if ($pos === false) {
                continue;
            }
            $line = trim(substr($line, $pos + 1));
            if (preg_match('/^(.*)\((.*)\)$/', $line, $matches)) {
                // 处理 @func(v1,v2,v3...) 格式
                if (empty($matches[1])) {
                    continue;
                }
                switch (strtolower($matches[1])) {
                    case 'get':
                    case 'post':
                    case 'delete':
                    case 'put':
                        $parsed['method'] = $matches[1];
                        $fields = ['id', 'uri', 'name', 'auth'];
                        break;
                    case 'doc':
                        $fields = ['app', 'id', 'name', 'auth'];
                        break;
                    default:
                        $fields = null;
                        break;
                }
                if (empty($fields)) {
                    continue;
                }
                $values = explode(',', $matches[2]);
                foreach ($fields as $k=>$v) {
                    if (isset($values[$k])) {
                        $parsed[$v] = trim($values[$k]);
                    } else {
                        $parsed[$v] = null;
                    }
                }
            } else {
                // 处理 @key value 格式
                $var = explode(' ', $line, 2);
                if (empty($var[0])) {
                    continue;
                }
                if (!isset($var[1])) {
                    $var[1] = null;
                }
                if (isset(self::ALIAS[$var[0]])) {
                    $var[0] = self::ALIAS[$var[0]];
                }
                $var[0] = strtolower($var[0]);

                if (isset($parsed[$var[0]])) {
                    if (!is_array($parsed[$var[0]])) {
                        $parsed[$var[0]] = [$parsed[$var[0]]];
                    }
                    $parsed[$var[0]][] = $var[1];
                } else {
                    $parsed[$var[0]] = $var[1];
                }
            }
        }
        if (isset($parsed['auth'])) {
            if (defined($parsed['auth'])) {
                $parsed['auth'] = constant($parsed['auth']);
            } else {
                $parsed['auth'] = null;
            }
        }
        
        return $parsed;
    }
}