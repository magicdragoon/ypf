<?php

use Ypf\Utils\StrUtil;

const APP_PATH = __DIR__;

class Ypf
{
    private static array $config = [];

    public static function app() : void
    {
        self::init();
        // 非命令行，开发环境下或版本号不匹配或未配置路由时，初始化
        if (APP_DEV || empty(self::$config['version']) || self::$config['version'] != APP_VERSION || empty(self::$config['routes'])) {
            include APP_PATH . '/YpfTools.php';
            self::$config = YpfTools::init(self::$config);
        }

        $request = new YpfRequest(self::$config['headers'] ?? []);
        $controller = 'App\\Controller';
        $action = null;
        $routeKey = trim($_SERVER['PATH_INFO'] ?? '', '\/');
        if (empty($routeKey)) {
            $routeKey = '/';
        } else {
            // 匹配id
            $routeKey = preg_replace_callback('/\/\d+/', function($matches) use ($request) {
                $request->setId(intval(substr($matches[0], 1)));
                return '/:id';
            }, $routeKey);
            // 匹配no
            if (!empty(self::$config['url_no'])) {
                $routeKey = preg_replace_callback('/\/[A-Z]{2}-[A-Za-z0-9]{32}/', function($matches) use ($request) {
                    $request->setNo(substr($matches[0], 1));
                    return '/:id';
                }, $routeKey);
            }
        }
        $routeKey .= '@' . $request->method();
        if (!isset(self::$config['routes'][$routeKey])) {
            throw new AppException(Consts::CODE_ACCESS_DENIED, 'Access denied!');
        }
        $route = self::$config['routes'][$routeKey];
        $app = self::$config['apps'][$route['app']];
        if (empty($app)) {
            throw new AppException(9999990001, '应用' . $route['app'] . '不存在');
        }
        $controller = new $route['class']($request);
        // 有id需要登录
        if (!empty($app['auth']) && $route['auth'] != ACL_NON) {
            $auth = new $app['auth']($request, $route['app']);
            $auth->checkLogin();

            // 需要权限
            if ($route['auth'] == ACL_AUTH) {
                if (!$auth->checkAuth($route['id'])) {
                    throw new AppException(Consts::CODE_ACCESS_DENIED, 'Access denied!');
                }
            }
        }
        $action = $route['method'];
        if (empty($route['req'])) {
            $resp = $controller->$action();
        } else {
            try {
                $param = new $route['req']();
                $param->init($request);
            } catch (Throwable $e) {
                throw new AppException(9999990000, $e->getMessage());
            }
            $resp = $controller->$action($param);
        }
        if (!empty($resp)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json([
                'code' => 200,
                'data' => $resp,
                'message' => '',
            ]);
        }
    }

    public static function cli() : void
    {
        self::init(true);
        $argv = $_SERVER['argv'];
        $argCnt = count($argv);
        if ($argCnt < 2) {
            echo '请输入命令' . PHP_EOL;
            exit;
        }

        if (str_contains($argv[1], '_')) {
            $command = StrUtil::snakeToCamel($argv[1]);
        } elseif (str_contains($argv[1], '-')) {
            $command = StrUtil::kebabToCamel($argv[1]);
        } else {
            $command = ucfirst($argv[1]);
        }

        $command = 'App\Command\\' . $command . 'Command';
        if (!class_exists($command)) {
            echo '命令' . $argv[1] . '不存在' . PHP_EOL;
            exit;
        }

        $args = [];
        for ($i = 2; $i < $argCnt; $i++) {
            // 确保字符串是以 '--' 开头，并且包含 '='
            if (str_starts_with($argv[$i], '--') && str_contains($argv[$i], '=')) {
                $parts = explode('=', substr($argv[$i], 2), 2);
                if (count($parts) === 2) {
                    $args[$parts[0]] = $parts[1];
                } else {
                    $args[$parts[0]] = true;
                }
            }
        }

        $command = new $command($args);
        $command->handle();
    }

    /**
     * @throws Exception
     */
    private static function init(bool $isCli = false) : void
    {
        set_error_handler('Ypf::errorHandle');

        set_exception_handler('Ypf::exceptionHandle');

        spl_autoload_register('Ypf::autoload');

        $config = include APP_PATH . '/.env';
        define('APP_DEV', !empty($config['dev']));

        // 初始化数据库
        DB::init($config['db'] ?? null);

        if (!empty($config['cors'])) {
            header('Access-Control-Allow-Origin: ' . ($config['cors']['origin'] ?? '*'));
            header('Access-Control-Allow-Methods: ' . ($config['cors']['methods'] ?? 'GET, POST, PUT, DELETE, OPTIONS'));
            header('Access-Control-Allow-Headers: ' . ($config['cors']['headers'] ?? '*'));
        }

        self::$config = $config;
    }

    /**
     * 获取设置
     *
     * @param $key
     * @return ?mixed
     */
    public static function getConfig(?string $key = null) : mixed
    {
        if (empty($key)) {
            return self::$config;
        }
        if (!isset(self::$config[$key])) {
            return null;
        }
        return self::$config[$key];
    }

    /**
     * 错误处理
     *
     * @param $no
     * @param $message
     * @param $file
     * @param $line
     * @return void
     */
    public static function errorHandle(int $no, string $message, string $file, int $line): void
    {
        if (APP_DEV) {
            echo '{"code": ' . $no . ', "message": "' . $message . '", "file": "' . $file . '", "line": ' . $line . '}';
        } else {
            echo '{"code": ' . $no . ', "message": "系统异常"}';
        }
    }

    /**
     * 异常处理
     *
     * @param Throwable $e
     * @return void
     */
    public static function exceptionHandle(Throwable $e): void
    {
        if (APP_DEV) {
            echo '{"code": ' . $e->getCode() . ', "message": "' . $e->getMessage() . '", "file": "' . $e->getFile() . '", "line": ' . $e->getLine() . ', "trace": "' . $e->getTraceAsString() . '"}';
        } else {
            echo '{"code": ' . $e->getCode() . ', "message": "系统异常"}';
        }
    }

    /**
     * 自动加载
     *
     * @throws Exception
     */
    public static function autoload(string $classname): void
    {
        $namespaces = explode('\\', $classname);
        if (empty($namespaces)) {
            throw new Exception("class {$classname} not found");
        }
        if ($namespaces[0] == 'App') {
            $path = APP_PATH . '/app';
            unset($namespaces[0]);
        } else {
            $path = APP_PATH . '/lib';
        }
        foreach ($namespaces as $v) {
            $path .= '/' . $v;
        }
        $path .= '.php';
        if (!file_exists($path)) {
            throw new Exception("class {$classname} not found" . $path);
        }
        try {
            include $path;
        } catch (Exception $ex) {
            echo $path;
        }
    }

    /**
     * 加载第三方组件
     *
     * @return void
     */
    public static function loadVendor(): void
    {
        include APP_PATH . '/vendor/autoload.php';
    }
}

class AppException extends Exception
{
    public function __construct(int $code, string $message = '系统异常')
    {
        parent::__construct($message, $code);
    }
}

class DB
{
    private static PDO|bool $link = false;

    private static array $config;

    /**
     * @throws Exception
     */
    public static function init(?array $config = null) : void
    {
        if (!isset($config['host']) || !isset($config['dbname']) || !isset($config['username']) || !isset($config['password'])) {
            throw new Exception("db config error");
        }
        self::$config = $config;
    }

    /**
     * @throws Exception
     */
    private static function connect() : void
    {
        if (!self::$link) {
            $dsn = 'mysql:host=' . self::$config['host'] . ';dbname=' . self::$config['dbname'] . ';charset=';
            $dsn .= self::$config['charset'] ?? 'utf8mb4';
            self::$link = new PDO($dsn, self::$config['username'], self::$config['password'], self::$config['options'] ?? null);
            if (!self::$link) {
                throw new Exception("db connect error");
            }
        }
    }

    /**
     * @throws Exception
     */
    public static function prepare(string $sql) : false|PDOStatement
    {
        self::connect();
        return self::$link->prepare($sql);
    }

    /**
     * @throws Exception
     */
    public static function execute(string $sql, ?array $params = null) : false|PDOStatement
    {
        $statement = self::prepare($sql);
        if (!empty($params)) {
            if (isset($params[0])) {
                foreach ($params as $key => $value) {
                    $statement->bindValue($key + 1, $value);
                }
            } else {
                foreach ($params as $key => $value) {
                    $statement->bindValue($key, $value);
                }
            }
        }
        $statement->execute();
        return $statement;
    }

    /**
     * @throws Exception
     */
    public static function lastInsertId() : int
    {
        self::connect();
        return self::$link->lastInsertId();
    }

    /**
     * @throws Exception
     */
    public static function query(string $sql, array $params = [], bool $all = true) : array|false
    {
        return $all ? self::queryAll($sql, $params) : self::queryOne($sql, $params);
    }

    public static function queryFields(string $sql, array $params, string $field) : array|false
    {
        $result = [];
        $statement = self::execute($sql, $params);
        if (!empty($statement)) {
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $result[] = $row[$field];
            }
        }
        return $result;
    }

    /**
     * @throws Exception
     */
    public static function queryOne(string $sql, array $params = []) : array|false
    {
        $statement = self::execute($sql, $params);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * @throws Exception
     */
    public static function queryAll(string $sql, array $params = [], ?string $key = null) : array|false
    {
        $statement = self::execute($sql, $params);
        if ($key === null) {
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        $result = [];
        if (!empty($statement)) {
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $result[$row[$key]] = $row;
            }
        }
        return $result;
    }

    /**
     * @throws Exception
     */
    public static function exec(string $sql) : false|int
    {
        self::connect();
        return self::$link->exec($sql);
    }

    public static function insert(string $table, array $data) : false|int
    {
        self::connect();
        $keys = [];
        $marks = [];
        $values = [];
        $valueKeys = 1;
        foreach ($data as $key => $value) {
            $keys[] = "`{$key}`";
            $marks[] = '?';
            $values[$valueKeys++] = $value;
        }
        $sql = "INSERT INTO `{$table}` (" . implode(',', $keys) . ") VALUES (" . implode(',', $marks) . ")";
        self::execute($sql, $values);
        return self::$link->lastInsertId();
    }

    public static function update(string $table, array $data, array $where) : false|int
    {
        self::connect();
        $values = [];
        $valueKeys = 1;
        $set = [];
        foreach ($data as $key => $value) {
            $set[] = "{$key} = ?";
            $values[$valueKeys++] = $value;
        }
        $params = [];
        foreach ($where as $key => $value) {
            $params[] = "{$key} = ?";
            $values[$valueKeys++] = $value;
        }
        $sql = "UPDATE `{$table}` SET " . implode(',', $set) . " WHERE 1=1 AND " . implode(' AND ', $params);
        return self::execute($sql, $values);
    }

    private static $inTransaction = false;

    /**
     * @throws Exception
     */
    public static function beginTransaction() : bool
    {
        self::connect();
        self::$inTransaction = self::$link->beginTransaction();
        return self::$inTransaction;
    }

    /**
     * @throws Exception
     */
    public static function commit() : bool
    {
        self::connect();
        if (!self::$inTransaction) {
            return false;
        }

        if (!self::$link->commit()) {
            return false;
        }

        self::$inTransaction = false;
        return true;
    }

    /**
     * @throws Exception
     */
    public static function rollback() : bool
    {
        self::connect();
        if (!self::$inTransaction) {
            return false;
        }

        if (!self::$link->rollback()) {
            return false;
        }

        self::$inTransaction = false;
        return true;
    }
}

class YpfController
{
    protected YpfRequest $request;

    public function __construct(YpfRequest $request)
    {
        $this->request = $request;
        $this->init();
    }

    protected function init()
    {

    }

    protected function getConfig(string $key) : mixed
    {
        return Ypf::getConfig($key);
    }
}

class YpfRequest
{
    private string $method;

    private array $get = [];

    private array $post = [];

    private array $header = [];

    private ?int $id = null;
    private ?string $no = null;

    private int $requestTime = 0;

    public function __construct(array $headers = [])
    {
        $this->method = strtolower($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->get = $_GET;
        $this->post = $_POST;
        foreach ($_SERVER as $key => $value) {
            if (isset($headers[$key])) {
                $this->header[$headers[$key]] = $value;
            }
        }
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            $input = json_decode($input, true);
            if (!empty($input)) {
                $this->post = $input;
            }
        }
        $this->requestTime = $_SERVER['REQUEST_TIME'] ?? 0;
    }

    public function setId(int $id)
    {
        $this->id = $id;
    }

    public function setNo(string $no)
    {
        $this->no = $no;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function no(): ?string
    {
        return $this->no;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function requestTime(): int
    {
        return $this->requestTime;
    }

    public function get(string $key, mixed $default = null)
    {
        return $this->get[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null)
    {
        return $this->post[$key] ?? $default;
    }

    public function header(string $key, mixed $default = null)
    {
        return $this->header[$key] ?? $default;
    }

    public function has(string $key)
    {
        return isset($this->get[$key]) || isset($this->post[$key]);
    }
}

abstract class YpfAuth
{
    // 过期时间，单位秒
    protected int $timeout = 1800;
    // 单例
    protected bool $singleton = true;

    protected int $appId = 0;
    protected YpfRequest $request;

    protected string $username = 'username';
    protected string $password = 'password';
    protected string $encrypt = 'md5';

    protected array $roles = [];
    protected array $attrs = [];

    public function __construct(YpfRequest $request, int $appId = 0)
    {
        $this->request = $request;
        $this->appId = $appId;
    }

    public function roles() : array
    {
        return $this->roles;
    }

    public function extra(string $key, mixed $default = null) : mixed
    {
        return $this->attrs[$key] ?? $default;
    }

    abstract public function checkAuth(int $apiId) : bool;

    abstract public function checkLogin();
}

abstract class YpfCommand
{
    protected array $args = [];

    public function __construct(array $args = [])
    {
        $this->args = $args;
    }

    abstract public function handle();
}

abstract class Data
{
    protected array $input = [];
    protected array $output = [];
    protected array $attrs = [];
    protected array $resp = [];
    protected bool $isPaginated = false;

    public function __construct()
    {
        $this->config();
    }

    abstract protected function config();

    protected function input(string $key, string $name, string $type, mixed $default = null, string $validate = '')
    {
        $this->i($key, $name, $type, $default, $validate);
    }

    protected function output(string $key, string $name, string $type, mixed $default = null, string $validate = '')
    {
        $this->o($key, $name, $type, $default, $validate);
    }

    protected function i(string $key, string $name, string $type, mixed $default = null, string $validate = '')
    {
        $this->input[$key] = new F($key, $name, $type, $default, $validate);
    }

    protected function o(string $key, string $name, string $type, mixed $default = null, string $validate = '')
    {
        $this->output[$key] = new F($key, $name, $type, $default, $validate);
    }

    public function resp() : array
    {
        $resp = [];
        foreach ($this->output as $k => $v) {
            if (isset($this->resp[$k])) {
                $resp[$k] = $this->resp[$k];
            } elseif (!empty($v->default)) {
                $resp[$k] = $v->default;
            } else {
                throw new Exception($v->name . '不能为空');
            }
        }
        return $resp;
    }

    public function has(string $key)
    {
        return isset($this->attrs[$key]);
    }

    public function get(string $key, mixed $default = null)
    {
        return $this->attrs[$key] ?? $default;
    }

    public function set(string $key, mixed $value)
    {
        $this->resp[$key] = $value;
    }

    public function pass(string $key)
    {
        if (!isset($this->input[$key])) {
            throw new Exception($key . '未定义');
        }
        if (!isset($this->attrs[$key])) {
            throw new Exception($key . '未传入');
        }
        $this->resp[$key] = $this->attrs[$key];
    }

    public function init(YpfRequest $request)
    {
        foreach ($this->input as $k => $v) {
            if ($v->required && !$request->has($k)) {
                throw new Exception($v->name . '不能为空');
            }
            $this->attrs[$k] = $this->getValue($v->type, $request->post($k, $v->default), $v->name);
        }
    }

    protected function checkField(array $config, array &$return)
    {
        foreach ($config as $v) {
            switch ($v) {
                case 'required':
                    $return['required'] = true;
                    break;
                default:
                    $tmp = explode(':', $v);
                    switch ($tmp[0]) {
                        case 'default':
                            if (!empty($tmp[1])) {
                                $return['default'] = $tmp[1];
                            }
                            break;
                    }
                    break;
            }
        }
    }

    protected function getValue(?string $type, mixed $v, string $name)
    {
        $tmp = explode('|', $type);
        $type = $tmp[0];
        $subType = $tmp[1] ?? null;
        switch ($type) {
            case 'int':
                return intval($v);
            case 'float':
                return floatval($v);
            case 'array':
                if (!is_array($v)) {
                    throw new AppException(400, $name . '必须是数组');
                }

                switch ($subType) {
                    case 'int':
                        return array_map('intval', $v);
                    case 'float':
                        return array_map('floatval', $v);
                    default:
                        return $v;
                }
            default:
                return $v;
        }
    }

    public function getDoc()
    {
        $doc = [
            'request' => [],
            'response' => [],
        ];
        foreach ($this->input as $k=>$v) {
            $doc['request'][] = [
                'field' => $k,
                'name' => $v->name,
                'type' => $v->type,
                'required' => $v->required,
                'default' => $v->default,
                'validate' => $v->validate,
            ];
        }
        foreach ($this->output as $k=>$v) {
            $doc['response'][] = [
                'field' => $k,
                'name' => $v->name,
                'type' => $v->type,
                'default' => $v->default,
            ];
        }

        return $doc;
    }
}

class QueryBuilder
{
    protected string $order;
    protected string $where = '';
    protected array $params = [];
    protected int $paramCnt = 1;

    public function build() : array
    {
        return [
            'where' => $this->where,
            'params' => $this->params,
        ];
    }

    public function where(string $v1, mixed $v2, mixed $v3 = null) : static
    {
        if ($v3 === null) {
            $v3 = $v2;
            $v2 = '=';
        }
        $this->where .= ' AND `' . $v1 . '` ' . $v2 . ' ?';
        $this->params[$this->paramCnt++] = $v3;
        return $this;
    }

    public function whereLike(string $v1, mixed $v2, string $v3 = 'LIKE') : static
    {
        $this->where .= ' AND `' . $v1 . '` ' . $v3 . ' ?';
        $this->params[$this->paramCnt++] = '%' . $v2 . '%';
        return $this;
    }

    public function whereIn(string $v1, array $v2, string $v3 = 'IN') : static
    {
        $this->where .= ' AND `' . $v1 . '` ' . $v3 . ' (' . implode(',', array_fill(0, count($v2), '?')) . ')';
        foreach ($v2 as $v) {
            $this->params[$this->paramCnt++] = $v;
        }
        return $this;
    }

    public function whereNull(string $v1) : static
    {
        $this->where .= ' AND `' . $v1 . '` IS NULL';
        return $this;
    }

    public function whereNotNull(string $v1) : static
    {
        $this->where .= ' AND `' . $v1 . '` IS NOT NULL';
        return $this;
    }

    public function whereNotLike(string $v1, mixed $v2) : static
    {
        return $this->where($v1, $v2, 'NOT LIKE');
    }

    public function whereNotIn(string $v1, array $v2) : static
    {
        return $this->whereIn($v1, $v2, 'NOT IN');
    }

    public function whereBetween(string $v1, mixed $v2, mixed $v3) : static
    {
        $this->where .= ' AND `' . $v1 . '` BETWEEN ? AND ?';
        $this->params[$this->paramCnt++] = $v2;
        $this->params[$this->paramCnt++] = $v3;
        return $this;
    }
}

class ModelQuery
{
    protected Model $model;
    protected QueryBuilder $queryBuilder;
    protected string $table;
    protected string $primary = 'id';
    protected string $order;
    protected string $where = '';
    protected ?string $key = null;
    protected array $params = [];
    protected int $paramCnt = 1;

    public function __construct(Model $model)
    {
        $this->model = $model;
        $this->queryBuilder = new QueryBuilder();
        $this->table = $model->table();
        $this->primary = $model->primary();
        $this->order = $model->order();
    }

    public function where(string $v1, mixed $v2, mixed $v3 = null) : static
    {
        $this->queryBuilder->where($v1, $v2, $v3);
        return $this;
    }

    public function whereLike(string $v1, mixed $v2, string $v3 = 'LIKE') : static
    {
        $this->queryBuilder->whereLike($v1, $v2, $v3);
        return $this;
    }

    public function whereIn(string $v1, array $v2, string $v3 = 'IN') : static
    {
        $this->queryBuilder->whereIn($v1, $v2, $v3);
        return $this;
    }

    public function whereNull(string $v1) : static
    {
        $this->queryBuilder->whereNull($v1);
        return $this;
    }

    public function whereNotNull(string $v1) : static
    {
        $this->queryBuilder->whereNotNull($v1);
        return $this;
    }

    public function whereNotLike(string $v1, mixed $v2) : static
    {
        $this->queryBuilder->whereNotLike($v1, $v2);
        return $this;
    }

    public function whereNotIn(string $v1, array $v2) : static
    {
        $this->queryBuilder->whereNotIn($v1, $v2);
        return $this;
    }

    public function whereBetween(string $v1, mixed $v2, mixed $v3) : static
    {
        $this->queryBuilder->whereBetween($v1, $v2, $v3);
        return $this;
    }

    public function key(string $str) : static
    {
        $this->key = $str;
        return $this;
    }

    public function order(string $v1, string $v2 = 'asc') : static
    {
        $this->order = $v1 . ' ' . $v2;
        return $this;
    }

    public function first()
    {
        [$where, $params] = $this->queryBuilder->build();
        $sql = 'SELECT * FROM `' . $this->table . '` 
            WHERE 1=1 ' . $where . ' ORDER BY ' . $this->order . ' LIMIT 1';
        $rs = DB::execute($sql, $params);
        return $rs->fetch(PDO::FETCH_ASSOC);
    }
    
    public function all()
    {
        [$where, $params] = $this->queryBuilder->build();
        $sql = 'SELECT * FROM `' . $this->table . '` 
            WHERE 1=1 ' . $where . ' ORDER BY ' . $this->order;
        $rs = DB::execute($sql, $params);
        if (empty($this->key)) {
            return $rs->fetchAll(PDO::FETCH_ASSOC);
        }
        $return = [];
        while ($row = $rs->fetch(PDO::FETCH_ASSOC)) {
            $return[$row[$this->key]] = $row;
        }
        return $return;
    }
}

class Model
{
    protected string $table;
    protected string $primary = 'id';
    protected string $order = '`id` desc';
    protected ModelQuery $query;
    protected array $columns = [];
    protected array $record = [];

    public function init(array $row)
    {
        $this->record = $row;
        foreach ($this->columns as $key => $value) {
            if (!isset($row[$key])) {
                continue;
            }
            $columnKey = $value->key;
            if ($value->type == 'array') {
                $this->$columnKey = json_decode($row[$key], true);
            } else {
                $this->$columnKey = $row[$key];
            }
        }
    }

    public function save() : static
    {
        $primary = $this->primary;
        if (empty($primary) || empty($this->record)) {
            // 新增
            $arr = [];
            foreach ($this->columns as $key => $value) {
                $key = $value->key;
                if ($key == $primary) {
                    continue;
                }
                $arr[$key] = $this->$key;
            }
            DB::insert($this->table, $arr);
            $this->$primary = DB::lastInsertId();
        } else{
            // 更新
            $arr = [];
            foreach ($this->columns as $key => $value) {
                $key = $value->key;
                if ($key == $primary) {
                    continue;
                }
                if ($this->record[$key] == $this->$key) {
                    continue;
                }
                $arr[$key] = $this->$key;
            }
            if (!empty($arr)) {
                DB::update($this->table, $arr, [$primary => $this->$primary]);
            }
        }
        return $this;
    }

    public function table()
    {
        return $this->table;
    }

    public function primary()
    {
        return $this->primary;
    }

    public function order()
    {
        return $this->order;
    }

    public function newQuery()
    {
        return new ModelQuery($this);
    }

    public static function query() : ModelQuery
    {
        return (new static())->newQuery();
    }

    public static function find(mixed $primaryId) : static
    {
        $model = new static();
        if (empty($model->columns)) {
            throw new \Exception('columns is empty');
        }
        $sql = 'SELECT * FROM `' . $model->table() . '` WHERE `' . $model->primary() . '` = ?';
        $params = [1 => $primaryId];

        $rs = DB::execute($sql, $params);
        $row = $rs->fetch(PDO::FETCH_ASSOC);
        $model->init($row);
        return $model;
    }
    
    public static function __callStatic(string $name, array $arguments)
    {
        return static::query()->$name(...$arguments);
    }
}

class Cache
{
    public static function set(string $key, mixed $value)
    {
        file_put_contents(storage_path('cache/' . $key . '.php'), serialize($value));
    }

    public static function get(string $key)
    {
        if (!file_exists(storage_path('cache/' . $key . '.php'))) {
            return null;
        }
        return unserialize(file_get_contents(storage_path('cache/' . $key . '.php')));
    }
}

function getConfig(string $key)
{
    return Ypf::getConfig($key);
}

function isProd(): bool
{
    return APP_DEV;
}

function var_output(mixed $expression) {
    $export = var_export($expression, TRUE);
    $export = preg_replace("/^([ ]*)(.*)/m", '$1$1$2', $export);
    $array = preg_split("/\r\n|\n|\r/", $export);
    $array = preg_replace(["/\s*array\s\($/", "/\)(,)?$/", "/\s=>\s$/"], [NULL, ']$1', ' => ['], $array);
    $export = join(PHP_EOL, array_filter(["["] + $array));
    return $export;
}

class F
{
    public string $key;
    public string $name;
    public string $type;
    public bool $required = false;
    public mixed $default;
    public string $validate;

    public function __construct(string $key, string $name, string $type, mixed $default = null, string $validate = '')
    {
        $this->key = $key;
        $this->name = $name;
        $this->type = $type;
        $this->required = $default === null;
        $this->default = $default;
        $this->validate = $validate;
    }
}

function f(string $key, string $name, string $type, mixed $default = null, string $validate = '')
{
    return new F($key, $name, $type, $default, $validate);
}

function storage_path(string $path)
{
    return APP_PATH . '/storage/' . $path;
}

function public_path(string $path)
{
    return APP_PATH . '/public/' . $path;
}

function log_path(string $path)
{
    return storage_path('logs/' . $path);
}

function json(mixed $data)
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function uuid(bool $min = true) {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
    return vsprintf($min ? '%s%s%s%s%s%s%s%s' : '%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

const ACL_NON = 0;
const ACL_LOGIN = 1;
const ACL_AUTH = 2;

class Consts
{
    const DISABLED = 0;
    const ENABLED = 1;

    const OFFLINE = 0;
    const ONLINE = 1;

    const REVIEW_PENDING = 0;
    const REVIEW_APPROVED = 1;
    const REVIEW_REJECTED = 2;
    const REVIEW_CANCELED = 3;

    const CODE_ACCESS_DENIED = 9999999999;
}