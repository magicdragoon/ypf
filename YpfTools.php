<?php
class YpfTools
{
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

    public static function init()
    {
        Context::setRequest(new Request());
        foreach (APPS as $app) {
            if (!empty($app['auth']) && !is_subclass_of($app['auth'], AuthMiddleware::class)) {
                throw new Exception('应用' . $app['name'] . '权限类' . $app['auth'] . '不是权限中间件');
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
            if (!isset(APPS[$app])) {
                throw new Exception('应用' . $app . '不存在：');
            }
            self::$docs[$app] = [
                'id' => $app,
                'name' => APPS[$app]['name'],
                'docs' => $docs,
            ];
        }

        try {
            $envFile = APP_PATH . '/.env.php';
            $envContent = file_get_contents($envFile);

            $routeStart = strpos($envContent, "ROUTES = [\n") + 9;
            $routeEnd = strpos($envContent, "\n];", $routeStart) + 2;
            $envContent = substr_replace($envContent, var_output(self::$route), $routeStart, $routeEnd - $routeStart);

            $envContent = str_replace('const VERSION = \'' . VERSION . '\';', 'const VERSION = \'' . APP_VERSION . '\';', $envContent);

            file_put_contents($envFile, $envContent);
        } catch (Exception) {
        }
        $docs = array_values(self::$docs);
        foreach ($docs as &$app) {
            $app['docs'] = array_values($app['docs']);
        }
        $consts = self::scanConst(APP_PATH . '/app/Const/');
        file_put_contents(APP_PATH . '/storage/docs.php', "<?php\nreturn " . var_output(['api' =>$docs, 'const' =>$consts]) . ";");
    }

    /**
     * 扫描表
     * @param string $dbName
     * @param array $table
     * @param bool $force
     */
    public static function scanTable($dbName, $table, $force = false)
    {
        $sql = "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT, COLUMN_KEY, EXTRA FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = '{$table['TABLE_NAME']}' ORDER BY ORDINAL_POSITION;";
        $fieldStr = '';
        $columnStr = '';
        $timeStr = '';
        $primaryKey = null;
        $createdAt = null;
        $updatedAt = null;
        $unique = [];
        foreach (DB::query($sql) as $column) {
            $columnName = Str::snakeToCamel($column['COLUMN_NAME'], false);
            if ($column['COLUMN_KEY'] === 'PRI') {
                if (empty($primaryKey) || $column['EXTRA'] === 'auto_increment') {
                    $primaryKey = $columnName;
                } else {
                    $unique[] = $columnName;
                }
            }
            if (in_array($column['COLUMN_NAME'], ['created_at', 'create_time', 'createdAt', 'createTime'])) {
                $createdAt = $columnName;
            }
            if (in_array($column['COLUMN_NAME'], ['updated_at', 'update_time', 'updatedAt', 'updateTime'])) {
                $updatedAt = $columnName;
            }
            if (in_array($column['COLUMN_NAME'], ['deleted_at', 'delete_time', 'deletedAt', 'deleteTime'])) {
                continue;
            }
            $type = '';
            $dateFormat = '';
            switch ($column['DATA_TYPE']) {
                case 'json':
                    $type = 'array';
                    break;
                case 'date':
                    $type = 'Date';
                    $dateFormat = ", 'Y-m-d'";
                    break;
                case 'datetime':
                    $type = 'DateTime';
                    $dateFormat = ", 'Y-m-d H:i:s'";
                    break;
                case 'timestamp':
                    $type = 'Time';
                    $dateFormat = ", 'Y-m-d H:i:s'";
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
            $nullable = $column['IS_NULLABLE'] === 'YES';
            $columnStr .= "\n" . ' * @property ' . (($type == 'Time' || $type == 'Date' || $type == 'DateTime') ? '\Time' : $type) . ($nullable ? '|null' : '') . ' $' . $columnName . ' ' . ($column['COLUMN_COMMENT'] ?? '');
            $fieldStr .= "\n";
            $fieldStr .= '        \'' . $columnName . '\' => [\'' . $column['COLUMN_NAME'] . '\', \'' . $column['COLUMN_COMMENT'] . '\', \'' . $type . '\'' . ($nullable ? ', \'nullable\'' : '') . '],';
        }
        $primaryStr = '';
        if (!empty($primaryKey)) {
            // 有主键
            if (!empty($unique)) {
                // 联合唯一索引
                $primaryStr =  "\n" . '    public const PRIMARY = null;';
                $primaryStr .= "\n" . '    public const UNIQUE = [\'' . $primaryKey . '\'';
                foreach ($unique as $col) {
                    $primaryStr .= ', \'' . $col . '\'';
                }
                $primaryStr .= '];';
            } elseif ($primaryKey !== 'id') {
                // 主键不为id
                $primaryStr =  "\n" . '    public const PRIMARY = \'' . $primaryKey . '\';';
            }
        } else {
            // 无主键
            $primaryStr =  "\n" . '    public const PRIMARY = null;';
        }
        if (empty($createdAt)) {
            $timeStr .= '    public const CREATED_AT = null;' . "\n";
        } elseif ($createdAt !== 'createdAt') {
            $timeStr .= '    public const CREATED_AT = \'' . $createdAt . '\';' . "\n";
        }
        if (empty($updatedAt)) {
            $timeStr .= '    public const UPDATED_AT = null;' . "\n";
        } elseif ($updatedAt !== 'updatedAt') {
            $timeStr .= '    public const UPDATED_AT = \'' . $updatedAt . '\';' . "\n";
        }
        $fieldStr .= "\n" . '    ';
        $modelName = Str::snakeToCamelSingular($table['TABLE_NAME']);
        $filePath = APP_PATH . '/app/Model/' . $modelName . '.php';
        if (!file_exists($filePath) || $force) {
            $content = <<<EOF
<?php
namespace App\Model;

use Model;

/**
 * {$table['TABLE_COMMENT']}{$columnStr}
 */
class {$modelName} extends Model
{
    public const TABLE = '{$table['TABLE_NAME']}';{$primaryStr}
{$timeStr}
    public const COLUMNS = [{$fieldStr}];
}

EOF;
            file_put_contents($filePath, $content);
        } else {
            $fileContent = file_get_contents($filePath);

            $pos = strpos($fileContent, "/**");
            if ($pos === false) {
                return;
            }
            $content = substr($fileContent, 0, $pos + 4)  . " * {$table['TABLE_COMMENT']}{$columnStr}\n";

            $posComment = strpos($fileContent, " */", $pos);
            if ($posComment === false) {
                return;
            }

            $posColumns = strpos($fileContent, "    public const COLUMNS = [");
            if ($posColumns === false) {
                return;
            }
            $content .= substr($fileContent, $posComment, ($posColumns - $posComment + 28)) . $fieldStr;

            $posColumnsEnd = strpos($fileContent, "    ];", $posColumns);
            if ($posColumnsEnd === false) {
                return;
            }
            $content .= substr($fileContent, $posColumnsEnd + 4);

            file_put_contents($filePath, $content);
        }
    }

    /**
     * 扫描数据库
     * @param string $dbName
     * @param bool $force
     * @param array|null $ignoreTables
     */
    public static function scanDb($dbName, $force = false, $ignoreTables = null)
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
        foreach (DB::query($sql) as $table) {
            self::scanTable($dbName, $table, $force);
        }
    }

    /**
     * 扫描最大服务id
     * @param string $dir
     * @return int
     */
    public static function scanMaxServiceId($dir)
    {
        $id = 0;
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..' || is_dir($dir . $file)) {
                continue;
            }

            $content = file_get_contents($dir . $file);
            if (preg_match('/public const SERVICE_ID = (\d{5});/', $content, $matches)) {
                if (substr($matches[1], 1) == '99999') {
                    continue;
                }
                if ($matches[1] > $id) {
                    $id = intval($matches[1]);
                }
            }
        }
        return intval($id);
    }

    /**
     * 扫描最大控制器id
     * @param string $dir
     * @return int
     */
    public static function scanMaxControllerId($dir)
    {
        $id = 0;
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..' || is_dir($dir . $file)) {
                continue;
            }

            $content = file_get_contents($dir . $file);
            if (preg_match('/@id (\d{5})00/', $content, $matches)) {
                if (substr($matches[1], 2) == '999') {
                    continue;
                }
                if ($matches[1] > $id) {
                    $id = intval($matches[1]);
                }
            }
        }
        return intval($id);
    }

    /**
     * 扫描控制器id
     * @param string $dir
     * @param int $id
     */
    public static function scanControllerId($dir, $id)
    {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }

            if (is_dir($dir . $file)) {
                self::scanControllerId($dir . $file . '/', $id);
            } elseif ($file != 'Controller.php') {
                $content = file_get_contents($dir . $file);
                if (str_contains($content, "@id {$id}00")) {
                    throw new Exception($dir . $file . "包含id: {$id}");
                }
            }
        }
    }

    /**
     * 扫描控制器
     * @param string $dir
     * @param string $path
     */
    private static function scanController($dir, $path)
    {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }

            if (is_dir($dir . $file)) {
                self::scanController($dir . $file, $path . $file . '\\');
            } elseif ($file != 'Controller.php') {
                $controllerClass = 'App\\Controller\\' . $path . substr($file, 0, -4);
                if (!is_subclass_of($controllerClass, Controller::class)) {
                    throw new Exception($controllerClass . '不是控制器类');
                }
                $reflection = new ReflectionClass($controllerClass);
                $isRestful = is_subclass_of($controllerClass, RestfulController::class);
                $controllerService = null;
                if ($isRestful) {
                    $controllerService = $controllerClass::SERVICE;
                    self::$classes[$controllerService] = self::getRestfulDoc($controllerService);
                }
                $controllerDoc = self::parseDoc($reflection->getDocComment());
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
                $controllerKey = str_replace('\\', '_', Str::camelToSnake($path . substr($file, 0, -14)));
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
                        $methodKey = $controllerKey . '.' . Str::camelToSnake($method->getName());
                        $methodDoc = self::parseDoc($method->getDocComment());
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
                        $dataDoc = [
                            'request' => [],
                            'response' => [],
                        ];
                        if ($isRestful && isset(self::$classes[$controllerService]) &&
                            isset(self::$classes[$controllerService][self::$route[$uri]['method']])) {
                            // Restful接口，从RestfulService获取参数
                            $dataDoc = self::$classes[$controllerService][self::$route[$uri]['method']];
                        } else {
                            // 非Restful接口，解析参数
                            $parameters = $method->getParameters();
                            if (!empty($parameters)) {
                                if (count($parameters) > 1) {
                                    throw new Exception($methodName . '中包含超过一个参数');
                                }
                                $paramType = $parameters[0]->getType()->getName();
                                if (!is_subclass_of($paramType, Data::class)) {
                                    throw new Exception($methodName . '中包含非Data参数：' . $paramType);
                                }
                                self::$route[$uri]['req'] = $paramType;
                                if (!isset(self::$classes[$paramType])) {
                                    self::$classes[$paramType] = self::getDataDoc($paramType);
                                }
                                $dataDoc = self::$classes[$paramType];
                            } else {
                                if (!empty($methodDoc['req'])) {
                                    $dataDoc['request'] = self::getRequestDoc(self::parseCommentDoc($methodDoc['req']));
                                }
                                if (!empty($methodDoc['resp'])) {
                                    $dataDoc['response'] = self::getResponseDoc(self::parseCommentDoc($methodDoc['resp']));
                                }
                            }
                        }
                        if (!empty($dataDoc)) {
                            self::$docs[$app][$methodDoc['id']]['request'] = $dataDoc['request'];
                            self::$docs[$app][$methodDoc['id']]['response'] = $dataDoc['response'];
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

    /**
     * 扫描常量
     * @param string $dir
     * @return array
     */
    public static function scanConst($dir)
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = scandir($dir);
        $consts = [];
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            $constClass = "App\\Const\\" . substr($file, 0, -4);
            $reflection = new ReflectionClass($constClass);
            if (str_contains($reflection->getDocComment(), '@ignore')) {
                continue;
            }
            $classDoc = self::cleanDocComment($reflection->getDocComment());
            $arrConst = [];
            $valueConst = [];
            foreach ($reflection->getReflectionConstants() as $const) {
                if (str_contains($const->getDocComment(), '@ignore')) {
                    continue;
                }
                $k = $const->getName();
                $v = $const->getValue();
                if (is_array($v)) {
                    $arrConst[$k] = [
                        'doc' => self::cleanDocComment($const->getDocComment()),
                        'key' => [],
                        'value' => $v,
                    ];
                } else {
                    $valueConst[$k] = [
                        'doc' => self::cleanDocComment($const->getDocComment()),
                        'value' => $v
                    ];
                }
            }
            foreach ($valueConst as $k=>$v) {
                foreach ($arrConst as $arrK=>&$arrV) {
                    if (str_starts_with($k, $arrK)) {
                        $arrV['key'][$v['value']] = $k;
                        unset($valueConst[$k]);
                    }
                }
            }
            foreach ($arrConst as $k=>$v) {
                $arr = [
                    'name' => $v['doc'],
                    'value' => [],
                ];
                foreach ($v['value'] as $key => $value) {
                    if (empty($v['key'][$key])) {
                        throw new Exception($k . '中包含未定义的常量：' . $key);
                    }
                    $arr['value'][] = [
                        'key' => $v['key'][$key],
                        'value' => $key,
                        'comment' => $value,
                    ];
                }
                $consts[] = $arr;
            }
            if (!empty($valueConst)) {
                $other = [];
                foreach ($valueConst as $k=>$v) {
                    $other[] = [
                        'key' => $k,
                        'value' => $v['value'],
                        'comment' => $v['doc'],
                    ];
                }
                $consts[] = [
                    'name' => $classDoc,
                    'value' => $other,
                ];
            }
        }
        return $consts;
    }

    /**
     * 清理注释
     * @param string $docComment
     * @return string
     */
    public static function cleanDocComment($docComment)
    {
        if (empty($docComment)) {
            return '';
        }

        // 1. 去除整个注释首尾的 /** 和 */，包括它们附近的空白字符
        $text = preg_replace('#^\s*/\*\*\s*|\s*\*/\s*$#', '', $docComment);

        // 2. 去除每一行开头的星号 (*) 及其后面的一个空格
        // 修饰符 m (PCRE_MULTILINE) 让 ^ 匹配每一行的开头
        $text = preg_replace('#^\s*\*\s?#m', '', $text);

        // 3. 去除首尾多余的换行和空格
        return trim($text);
    }

    /**
     * 解析注释
     * @param string $doc
     * @return array
     */
    private static function parseCommentDoc($doc)
    {
        if (!is_array($doc)) {
            $doc = [$doc];
        }
        foreach ($doc as $k=>$v) {
            $tmp = explode(' ', $v);
            if (count($tmp) < 3) {
                continue;
            }
            if (empty($tmp[3])) {
                $tmp[3] = null;
            } elseif ($tmp[3] == '-') {
                $tmp[3] = '';
            }
            $doc[$k] = [$tmp[1], $tmp[2], $tmp[0], $tmp[3]];
        }
        return fcheck($doc);
    }

    /**
     * 获取数据类注释
     * @param string $class
     * @return array
     */
    private static function getDataDoc($class)
    {
        if (!is_subclass_of($class, Data::class)) {
            throw new Exception('Data类必须继承自Ypf\Data');
        }
        if (!isset(self::$classes[$class])) {
            self::$classes[$class] = [
                'request' => self::getRequestDoc(fcheck($class::REQ)),
                'response' => self::getResponseDoc(fcheck($class::RESP)),
            ];
        }
        return self::$classes[$class];
    }

    /**
     * 获取Restful服务注释
     * @param string $class
     * @return array
     */
    private static function getRestfulDoc($class)
    {
        if (!is_subclass_of($class, RestfulService::class)) {
            throw new Exception('RestfulService类必须继承自Ypf\RestfulService');
        }
        if (isset(self::$classes[$class])) {
            return self::$classes[$class];
        }

        $filter = [
            [
                'field' => QUERY_PAGE,
                'name' => '页码',
                'type' => 'int',
                'required' => false,
                'default' => 1,
                'validate' => null,
            ],
            [
                'field' => QUERY_PERPAGE,
                'name' => '每页数量',
                'type' => 'int',
                'required' => false,
                'default' => QUERY_PAGESIZE,
                'validate' => null,
            ],
        ];
        $indexResource = [];
        $showResource = [];
        $indexIgnore = $class::$indexIgnore ?? [];
        $showIgnore = $class::$showIgnore ?? [];

        foreach ($class::$model::COLUMNS as $k => $v) {
            $v[0] = $k;
            if (!in_array($k, $indexIgnore)) {
                $indexResource[] = $v;
            }
            if (!in_array($k, $showIgnore)) {
                $showResource[] = $v;
            }
        }
        foreach ($class::$index as $v) {
            if (!empty($v[3]) && $v[3] == 'between') {
                $filter[] = [
                    'field' => $v[0] . QUERY_FROM,
                    'name' => $v[1] . ' - 开始时间',
                    'type' => $v[2],
                    'required' => false,
                    'default' => null,
                    'validate' => null,
                ];
                $filter[] = [
                    'field' => $v[0] . QUERY_TO,
                    'name' => $v[1] . ' - 结束时间',
                    'type' => $v[2],
                    'required' => false,
                    'default' => null,
                    'validate' => null,
                ];
            } else {
                $filter[] = [
                    'field' => $v[0],
                    'name' => $v[1],
                    'type' => $v[2],
                    'required' => in_array('required', $v),
                    'default' => $v[3] ?? null,
                    'validate' => null,
                ];
            }
        }
        if ($class::$hasPage) {
            $indexResp = [
                [
                    'field' => QUERY_ITEMS,
                    'name' => '列表',
                    'required' => true,
                    'default' => null,
                    'children' => self::getResponseDoc(fcheck(empty($class::$indexResource) ? $indexResource : $class::$indexResource::COLUMNS)),
                ],
                [
                    'field' => QUERY_PAGES,
                    'name' => '分页信息',
                    'required' => true,
                    'default' => '{"perPage": 20, "total": 0, "currentPage": 1, "lastPage": 1}',
                    'children' => [
                        [
                            'field' => 'perPage',
                            'name' => '每页数量',
                            'type' => 'int',
                            'required' => true,
                            'default' => 20,
                            'validate' => null,
                        ],
                        [
                            'field' => 'total',
                            'name' => '总记录数',
                            'type' => 'int',
                            'required' => true,
                            'default' => 0,
                            'validate' => null,
                        ],
                        [
                            'field' => 'currentPage',
                            'name' => '当前页码',
                            'type' => 'int',
                            'required' => true,
                            'default' => 1,
                            'validate' => null,
                        ],
                        [
                            'field' => 'lastPage',
                            'name' => '总页数',
                            'type' => 'int',
                            'required' => true,
                            'default' => 1,
                            'validate' => null,
                        ],
                    ],
                ],
            ];
        } else {
            $indexResp = self::getResponseDoc(fcheck(empty($class::$indexResource) ? $indexResource : $class::$indexResource::COLUMNS));
        }
        self::$classes[$class] = [
            'index' => [
                'request' => $filter,
                'response' => $indexResp,
            ],
            'show' => [
                'request' => [],
                'response' => self::getResponseDoc(fcheck(empty($class::$showResource) ? $showResource : $class::$showResource::COLUMNS)),
            ],
        ];
        if (!empty($class::$store)) {
            self::$classes[$class]['store'] = [
                'request' => self::getRequestDoc(fcheck($class::$store)),
                'response' => [
                    [
                        'field' => $class::$model::PRIMARY,
                        'name' => '编号',
                        'type' => 'int',
                    ]
                ],
            ];
        }
        if (!empty($class::$update)) {
            $update = [];
            foreach ($class::$store as $v) {
                if (!in_array($v[0], $class::$update)) {
                    continue;
                }
                $update[] = $v;
            }
            self::$classes[$class]['update'] = [
                'request' => self::getRequestDoc(fcheck($update)),
                'response' => [
                    [
                        'field' => 'result',
                        'name' => '结果',
                        'type' => 'bool',
                    ]
                ],
            ];
        }
        if ($class::$delete) {
            self::$classes[$class]['destroy'] = [
                'request' => [],
                'response' => [
                    [
                        'field' => 'result',
                        'name' => '结果',
                        'type' => 'bool',
                    ]
                ],
            ];
        }
        return self::$classes[$class];
    }

    /**
     * 获取请求参数注释
     * @param array $req
     * @return array
     */
    private static function getRequestDoc($req)
    {
        $arr = [];
        foreach ($req as $k => $v) {
            if (is_array($v)) {
                $arr[] = [
                    'field' => $k,
                    'name' => $v['name'],
                    'required' => $v['required'],
                    'children' => self::getRequestDoc($v['children']),
                ];
            } elseif ($v instanceof Field) {
                $arr[] = [
                    'field' => $k,
                    'name' => $v->name,
                    'type' => $v->type,
                    'required' => $v->required,
                    'default' => $v->default,
                    'validate' => is_array($v->validate) ? implode(',', $v->validate) : $v->validate,
                ];
            }
        }
        return $arr;
    }

    /**
     * 获取响应参数注释
     * @param array $resp
     * @return array
     */
    private static function getResponseDoc($resp)
    {
        $arr = [];
        foreach ($resp as $k => $v) {
            if (is_array($v)) {
                $arr[] = [
                    'field' => $k,
                    'name' => $v['name'],
                    'children' => self::getResponseDoc($v['children']),
                ];
            } elseif ($v instanceof Field) {
                $arr[] = [
                    'field' => $k,
                    'name' => $v->name,
                    'type' => $v->type,
                    'default' => $v->default,
                ];
            }
        }
        return $arr;
    }

    /**
     * 解析注释
     * @param string $docBlock
     * @return array
     */
    public static function parseDoc($docBlock)
    {
        $parsed = [];
        if (empty($docBlock) || str_contains($docBlock, '@ignore')) {
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
                if (in_array($var[0], ['allow', 'rest', 'restful'])) {
                    $var[0] = 'method';
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
