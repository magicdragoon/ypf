<?php

use Ypf\Utils\ClassUtil;
use Ypf\Utils\StrUtil;

class YpfTools
{
    const NAMESPACE_PREFIX = 'App\\Controller\\';

    private static $docs = [];
    private static $route = [];
    private static $classes = [];
    private static $auth = [];

    public static function getDocs()
    {
        return self::$docs;
    }
    
    public static function getRoute()
    {
        return self::$route;
    }
    
    public static function getAuth()
    {
        return self::$auth;
    }

    public static function init(array $config) : array
    {
        $apps = $config['apps'];
        foreach ($apps as $app) {
            if (!empty($app['auth']) && !is_subclass_of($app['auth'], YpfAuth::class)) {
                throw new Exception('应用' . $app['name'] . '权限类' . $app['auth'] . '不是Auth类');
            }
        }
        self::scanController(APP_PATH . '/app/Controller/', '');
        // 补充父级权限
        foreach (self::$auth as $id=>$auth) {
            if (!empty($auth['parent']) && !isset(self::$docs[$auth['parent']])) {
                if (!isset(self::$docs[$auth['app']][$auth['parent']])) {
                    throw new Exception('接口' . $id .'父级' . $auth['parent'] . '不存在：');
                }
                $parent = self::$docs[$auth['app']][$auth['parent']];
                self::$auth[$auth['parent']] = [
                    'id' => $auth['parent'],
                    'app' => $auth['app'],
                    'auth' => $auth['auth'],
                    'parent' => $parent['parent'],
                    'key' => $parent['key'],
                    'name' => $parent['name'],
                    'method' => $parent['method'],
                    'is_leaf' => false,
                ];
            }
        }
        foreach (self::$docs as $app=>$docs) {
            if (!isset($apps[$app])) {
                throw new Exception('应用' . $app . '不存在：');
            }
            self::$docs[$app] = [
                'id' => $app,
                'name' => $apps[$app]['name'],
                'docs' => $docs,
            ];
        }
        $config['version'] = APP_VERSION;
        $config['routes'] = self::$route;

        file_put_contents(APP_PATH . '/.env', "<?php\nreturn " . var_output($config) . ";");

        $docs = array_values(self::$docs);
        foreach ($docs as &$app) {
            $app['docs'] = array_values($app['docs']);
        }
        file_put_contents(APP_PATH . '/storage/docs.php', "<?php\nreturn " . var_output($docs) . ";");
        return $config;
    }

    public static function scanDb(string $dbName, bool $force = false, ?array $ignoreTables = null) 
    {
        $sql = "SELECT TABLE_NAME, TABLE_COMMENT FROM information_schema.TABLES 
            WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA = '{$dbName}'";
        if (!empty($ignoreTables)) {
            $sql .= " AND TABLE_NAME NOT IN (''";
            foreach ($ignoreTables as $table) {
                $sql .= ",'{$table}'";
            }
            $sql .= ')';
        }
        $hasAuth = false;
        foreach (DB::query($sql) as $table) {
            if ($table['TABLE_NAME'] === 'auths' || $table['TABLE_NAME'] === 'auth') {
                $hasAuth = true;
            }
            $sql = "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT, COLUMN_KEY, EXTRA FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = '{$table['TABLE_NAME']}' ORDER BY ORDINAL_POSITION;";
            $fieldStr = '';
            $columnStr = '';
            $primaryKey = null;
            foreach (DB::query($sql) as $column) {
                $columnName = StrUtil::snakeToCamel($column['COLUMN_NAME'], false);
                $columnStr .= PHP_EOL;
                if (!empty($column['COLUMN_COMMENT'])) {
                    $columnStr .= '    /**' . PHP_EOL;
                    $columnStr .= '     * ' . $column['COLUMN_COMMENT'] . PHP_EOL;
                    $columnStr .= '     */' . PHP_EOL;
                } else {
                    $column['COLUMN_COMMENT'] = '';
                }
                if ($column['COLUMN_KEY'] === 'PRI') {
                    if (empty($primaryKey) || $column['EXTRA'] === 'auto_increment') {
                        $primaryKey = $column['COLUMN_NAME'];
                    }
                }
                $columnStr .= '    public ';
                $type = '';
                switch ($column['DATA_TYPE']) {
                    case 'json':
                        $type = 'array';
                        break;
                    case 'datetime':
                    case 'date':
                        $type = 'string';
                        break;
                    case 'timestamp':
                        $type = 'int';
                        break;
                    case 'float':
                    case 'double':
                        $type = 'float';
                        break;
                    default:
                        if (str_ends_with($column['DATA_TYPE'], 'int')) {
                            $type = 'int';
                        } else {
                            $type = 'string';
                        }
                        break;
                }
                $columnStr .= $type . ' $' . $columnName;
                $columnStr .= ';' . PHP_EOL;
                $fieldStr .= PHP_EOL;
                $fieldStr .= '            \'' . $column['COLUMN_NAME'] . '\' => f(\'' . $columnName . '\', \'' . $column['COLUMN_COMMENT'] . '\', \'' . $type . '\'),';
            }
            if (!empty($primaryKey) && $primaryKey != 'id') {
                $primaryStr = PHP_EOL . '    protected string $primaryKey = \'' . $primaryKey . '\';' . PHP_EOL;
            } else {
                $primaryStr = '';
            }
            $fieldStr .= PHP_EOL . '        ';
            $modelName = StrUtil::snakeToCamelSingular($table['TABLE_NAME']);
            $filePath = APP_PATH . '/app/Model/' . $modelName . '.php';
            if (!file_exists($filePath) || $force) {
                $content = <<<EOF
    <?php
    namespace App\Model;

    use Model;

    /**
     * {$table['TABLE_COMMENT']}
     */
    class {$modelName} extends Model
    {
        protected string \$table = '{$table['TABLE_NAME']}';
        ###generated###{$primaryStr}
    {$columnStr}
        protected function columns()
        {
            \$this->columns = [{$fieldStr}];
        }
        ###generated###
    }

    EOF;
                file_put_contents($filePath, $content);
            } else {
                $fileContent = file_get_contents($filePath);
                $content = <<<EOF
    ###generated###{$primaryStr}
    {$columnStr}
    protected function columns()
    {
        \$this->columns = [{$fieldStr}];
    }
    ###generated###
EOF;
                $pos = stripos($fileContent, '###generated###');
                if ($pos === false) {
                    continue;
                }
                $start = substr($fileContent, 0, $pos - 4);
                $pos = strrpos($fileContent, '###generated###');
                if ($pos === false) {
                    continue;
                }
                $end = substr($fileContent, $pos + 15);
                file_put_contents($filePath, $start . $content . $end);
            }
        }

        return $hasAuth;
    }

    private static function scanController(string $dir, string $path)
    {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            
            if (is_dir($dir . $file)) {
                self::scanController($dir . $file, $path . $file . '\\');
            } elseif ($file != 'Controller.php') {
                $controllerClass = self::NAMESPACE_PREFIX . $path . substr($file, 0, -4);
                if (!is_subclass_of($controllerClass, YpfController::class)) {
                    throw new Exception($controllerClass . '不是控制器类');
                }
                $reflection = new ReflectionClass($controllerClass);
                $controllerDoc = ClassUtil::parseDoc($reflection->getDocComment());
                if (empty($controllerDoc) || !isset($controllerDoc['app'])) {
                    continue;
                }

                if (!isset($controllerDoc['name'])) {
                    throw new Exception('错误的控制器文档：' . $controllerClass);
                }

                $app = intval($controllerDoc['app']);
                if (!isset(self::$docs[$app])) {
                    self::$docs[$app] = [];
                }
                if (!isset($controllerDoc['auth'])) {
                    $controllerDoc['auth'] = ACL_AUTH;
                }
                $actionParent = 0;
                $controllerKey = str_replace('\\', '_', StrUtil::camelToSnake($path . substr($file, 0, -14)));
                if (!empty($controllerDoc['id'])) {
                    $actionParent = intval($controllerDoc['id']);
                    self::$docs[$app][$controllerDoc['id']] = [
                        'id' => $controllerDoc['id'],
                        'parent' => intval($controllerDoc['parent'] ?? 0),
                        'name' => $controllerDoc['name'],
                        'key' => $controllerKey,
                        'method' => null,
                        'uri' => null,
                    ];
                }

                $methods = $reflection->getMethods();
                if (!empty($methods)) {
                    foreach ($methods as $method) {
                        $methodName = $controllerClass . '@' . $method->getName();
                        $methodKey = $controllerKey . '.' . StrUtil::camelToSnake($method->getName());
                        $methodDoc = ClassUtil::parseDoc($method->getDocComment());
                        if (empty($methodDoc)) {
                            continue;
                        }
                        if (!isset($methodDoc['id']) || !isset($methodDoc['name'])) {
                            throw new Exception('错误的接口文档：' . $methodName);
                        }

                        $parent = intval($methodDoc['parent'] ?? $actionParent);
                        if (empty($methodDoc['uri'])) {
                            // 无路由，直接添加到文档作为父级
                            self::$docs[$app][$methodDoc['id']] = [
                                'id' => $methodDoc['id'],
                                'parent' => $parent,
                                'name' => $methodDoc['name'],
                                'key' => $methodKey,
                                'method' => null,
                                'uri' => null,
                            ];
                            continue;
                        }

                        $parameters = $method->getParameters();
                        $paramType = null;
                        if (!empty($parameters)) {
                            if (count($parameters) > 1) {
                                throw new Exception($methodName . '中包含超过一个参数');
                            }
                            $paramType = $parameters[0]->getType()->getName();
                            if (!is_subclass_of($paramType, Data::class)) {
                                throw new Exception($methodName . '中包含非Data参数：' . $paramType);
                            }
                            if (!isset(self::$classes[$paramType])) {
                                $tmp = new $paramType();
                                self::$classes[$paramType] = $tmp->getDoc();
                            }
                        }

                        if (!isset($methodDoc['method'])) {
                            $methodDoc['method'] = 'post';
                        } else {
                            $methodDoc['method'] = strtolower($methodDoc['method']);
                        }
                        $uri = $methodDoc['uri'] . '@' . $methodDoc['method'];
                        if (isset(self::$route[$uri])) {
                            throw new Exception($methodName . '中包含重复路由：' . $uri);
                        }
                        if (isset(self::$auth[$methodDoc['id']])) {
                            throw new Exception($methodName . '中包含重复ID：' . $methodDoc['id']);
                        }
                        if (!isset($methodDoc['auth']) || $methodDoc['auth'] === null) {
                            $auth = $controllerDoc['auth'];
                        } else {
                            $auth = intval($methodDoc['auth']);
                        }
                        self::$route[$uri] = [
                            'class' => $controllerClass,
                            'method' => $method->getName(),
                            'id' => $methodDoc['id'],
                            'app' => $app,
                            'auth' => $auth,
                        ];
                        self::$docs[$app][$methodDoc['id']] = [
                            'id' => $methodDoc['id'],
                            'parent' => $parent,
                            'name' => $methodDoc['name'],
                            'key' => $methodKey,
                            'method' => $methodDoc['method'],
                            'uri' => $methodDoc['uri'],
                        ];
                        if (!empty($paramType)) {
                            self::$route[$uri]['req'] = $paramType;
                            self::$docs[$app][$methodDoc['id']]['request'] = self::$classes[$paramType]['request'];
                            self::$docs[$app][$methodDoc['id']]['response'] = self::$classes[$paramType]['response'];
                        }
                        if ($auth == ACL_AUTH) {
                            self::$auth[$methodDoc['id']] = [
                                'app' => $app,
                                'parent' => $parent,
                                'auth' => $auth,
                                'key' => $methodKey,
                                'name' => $methodDoc['name'],
                                'method' => $methodDoc['method'],
                                'is_leaf' => true,
                            ];
                        }
                    }
                }
            }
        }
    }
}
