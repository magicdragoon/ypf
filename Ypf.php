<?php

const APP_PATH = __DIR__;
include __DIR__ . '/.env.php';

class Ypf
{
    /**
     * 获取当前时间戳
     * @return int|null
     */
    public static function timestamp()
    {
        return Context::now()->timestamp();
    }

    /**
     * 获取当前时间
     * @return Time|null
     */
    public static function now()
    {
        return Context::now();
    }

    /**
     * 应用入口
     */
    public static function app()
    {
        self::init();
        // 非命令行，开发环境下或版本号不匹配或未配置路由时，初始化
        if (APP_DEV || empty(VERSION) || VERSION != APP_VERSION || empty(ROUTES)) {
            include __DIR__ . '/YpfTools.php';
            YpfTools::init();
        }

        Context::set('now', new Time($_SERVER['REQUEST_TIME'] ?? time()));
        $request = new Request(HEADER_ATTRS ?? []);
        $resp = $request->dispatch();
        $log = $request->getLog();
        if ($resp !== null) {
            $log .= "\nresp: " . json($resp);
            header('Content-Type: application/json; charset=utf-8');
            echo json([
                RESP_CODE => 200,
                RESP_DATA  => $resp,
                RESP_MSG => '',
                RESP_TIMESTAMP  => self::timestamp(),
            ]);
        }
        Logger::info($log);
    }

    /**
     * 获取客户端 IP
     * @return string
     */
    public static function ip()
    {
        // 定义可能包含真实 IP 的 HTTP 头，优先级从上到下
        $headers = [
            'HTTP_CF_CONNECTING_IP',       // Cloudflare 专属
            'HTTP_X_REAL_IP',              // Nginx 常用代理头
            'HTTP_X_FORWARDED_FOR',        // 业界标准的代理头（可能包含多个IP，逗号分隔）
            'HTTP_CLIENT_IP',              // 历史遗留头
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'                  // 最终兜底：建立实际 TCP 连接的 IP
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                // 像 X-Forwarded-For 这样的头可能会有多个 IP (例如: "client, proxy1, proxy2")
                $ips = explode(',', $_SERVER[$header]);

                foreach ($ips as $ip) {
                    $ip = trim($ip);

                    // 验证是否为合法的 IPv4 或 IPv6 地址
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
                        // 如果你的业务在公网上，你可以取消下面这行的注释，用来过滤掉局域网/保留 IP 的伪造
                        // if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { continue; }

                        return $ip;
                    }
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * 命令行入口
     */
    public static function cli()
    {
        self::init(true);
        $argv = $_SERVER['argv'];
        $argCnt = count($argv);
        if ($argCnt < 2) {
            echo "请输入命令\n";
            exit;
        }

        $args = [];
        if (str_contains($argv[1], '_')) {
            $command = Str::snakeToCamel($argv[1]);
        } elseif (str_contains($argv[1], '-')) {
            $command = Str::kebabToCamel($argv[1]);
        } elseif (str_contains($argv[1], ':')) {
            $tmp = explode(':', $argv[1]);
            $command = ucfirst($tmp[0]);
            $args['command'] = $tmp[1];
        } else {
            $command = ucfirst($argv[1]);
        }

        $command = 'App\\Command\\' . $command . 'Command';
        if (!class_exists($command) || !is_subclass_of($command, Command::class)) {
            echo "命令{$argv[1]}不存在\n";
            exit;
        }

        unset($argv[0]);
        unset($argv[1]);
        for ($i = 2; $i < $argCnt; $i++) {
            // 确保字符串是以 '--' 开头，并且包含 '='
            if (str_starts_with($argv[$i], '--') && str_contains($argv[$i], '=')) {
                $parts = explode('=', substr($argv[$i], 2), 2);
                if (count($parts) === 2) {
                    $args[$parts[0]] = $parts[1];
                } else {
                    $args[$parts[0]] = true;
                }
                unset($argv[$i]);
            } elseif (str_contains($argv[$i], ':')) {
                $tmp = explode(':', $argv[$i]);
                $args[$tmp[0]] = $tmp[1];
                unset($argv[$i]);
            }
        }

        $command = new $command($args, array_values($argv));
        $command->handle();
    }

    /**
     * 初始化上下文
     * @param bool $isCli
     * @return void
     * @throws Exception
     */
    private static function init($isCli = false)
    {
        set_error_handler('Ypf::errorHandle');

        set_exception_handler('Ypf::exceptionHandle');

        spl_autoload_register('Ypf::autoload');

        ini_set('date.timezone', defined('TIMEZONE') ? TIMEZONE : 'Asia/Shanghai');

        // 初始化数据库
        DB::init(DB ?? null);

        if (!empty(CORS_ORIGIN)) {
            header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
            header('Access-Control-Allow-Methods: ' . CORS_METHODS);
            header('Access-Control-Allow-Headers: ' . CORS_HEADERS);
        }
    }

    /**
     * 错误处理
     * @param int $no
     * @param string $message
     * @param string $file
     * @param int $line
     * @return void
     */
    public static function errorHandle($no, $message, $file, $line)
    {
        // 排除掉被 @ 符号抑制的错误
        if (!(error_reporting() & $no)) {
            return;
        }
        // 将错误转化为异常抛出
        throw new ErrorException($message, 99999999, $no, $file, $line);
    }

    /**
     * 异常处理
     * @param Throwable $e
     * @return void
     */
    public static function exceptionHandle($e)
    {
        if (DB::inTransaction()) {
            DB::rollBack();
        }
        $request = Context::getRequest();
        if (!empty($request)) {
            $log = $request->getLog();
        } else {
            $log = '[' . date('Y-m-d H:i:s') . '][' . Ypf::ip() . '] ';
        }
        [$sql, $bindings] = DB::lastSql();
        if (!empty($sql)) {
            $log .= "\nlast sql: " . $sql;
            if (!empty($bindings)) {
                $log .= "\nlast bindings: " . json($bindings);
            }
        }
        $log .= "\n" . $e->getCode() . ': ' . $e->getMessage();
        $log .= "\n" . $e->getTraceAsString();
        Logger::error($log);
        if (APP_DEV) {
            echo '{"' . RESP_CODE . '": ' . $e->getCode() . ', "' . RESP_MSG . '": "' . $e->getMessage() . '"}';
        } else {
            echo '{"' . RESP_CODE . '": ' . $e->getCode() . ', "' . RESP_MSG . '": "系统异常"}';
        }
    }

    /**
     * 自动加载
     * @param string $classname
     * @return void
     * @throws Exception
     */
    public static function autoload($classname)
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
     * @return void
     */
    public static function loadVendor()
    {
        include APP_PATH . '/vendor/autoload.php';
    }
}

/**
 * 应用异常类
 */
class AppException extends Exception
{
    /**
     * 构造函数
     * @param int $code
     * @param string $message
     */
    public function __construct($code, $message = '系统异常')
    {
        parent::__construct($message, $code);
    }
}

/**
 * 数据库类
 */
class DB
{
    /**
     * 数据库连接
     * @var PDO|bool
     */
    private static $link = false;

    /**
     * 数据库配置
     * @var array
     */
    private static $config;

    private static $lastSql = '';
    private static $lastBindings = [];

    /**
     * 初始化数据库连接
     * @param array $config
     * @throws Exception
     */
    public static function init($config = null)
    {
        if (!isset($config['host']) || !isset($config['dbname']) || !isset($config['username']) || !isset($config['password'])) {
            throw new Exception("db config error");
        }
        self::$config = $config;
    }

    /**
     * 连接数据库
     * @throws Exception
     */
    private static function connect()
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
     * 准备SQL语句
     * @param string $sql
     * @return false|PDOStatement
     */
    public static function prepare($sql)
    {
        self::connect();
        return self::$link->prepare($sql);
    }

    /**
     * 执行SQL语句
     * @param string $sql
     * @param array $params
     * @return false|PDOStatement
     */
    public static function execute($sql, $params = null)
    {
        self::$lastSql = $sql;
        self::$lastBindings = $params;
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
     * 获取最后插入的ID
     * @return int
     */
    public static function lastInsertId()
    {
        self::connect();
        return self::$link->lastInsertId();
    }

    public static function lastSql()
    {
        return [self::$lastSql, self::$lastBindings];
    }

    /**
     * 查询数据
     * @param string $sql
     * @param array $params
     * @param bool $all
     * @return array|null
     */
    public static function query($sql, $params = [], $all = true)
    {
        return $all ? self::queryAll($sql, $params) : self::queryOne($sql, $params);
    }

    /**
     * 查询字段
     * @param string $sql
     * @param array $params
     * @param string $field
     * @return array|null
     */
    public static function queryFields($sql, $params, $field)
    {
        $result = [];
        $statement = self::execute($sql, $params);
        if (!empty($statement)) {
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $result[] = $row[$field];
            }
        }
        return $result === false ? null : $result;
    }

    /**
     * 查询单条数据
     * @param string $sql
     * @param array $params
     * @return array|null
     */
    public static function queryOne($sql, $params = [])
    {
        $statement = self::execute($sql, $params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * 查询所有数据
     * @param string $sql
     * @param array $params
     * @param string $key
     * @return array|null
     */
    public static function queryAll($sql, $params = [], $key = null)
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
        return $result === false ? null : $result;
    }

    /**
     * 执行SQL语句
     * @param string $sql
     * @param array $params
     * @return bool
     */
    public static function exec($sql, $params = [])
    {
        self::$lastSql = $sql;
        self::$lastBindings = $params;
        self::connect();
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
        return $statement->execute();
    }

    /**
     * 插入数据
     * @param string $table
     * @param array $data
     * @return int|null
     */
    public static function insert($table, $data)
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
        $id = self::$link->lastInsertId();
        return $id === false ? null : $id;
    }

    /**
     * 更新数据
     * @param string $table
     * @param array $data
     * @param array $where
     * @return bool
     */
    public static function update($table, $data, $where)
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
        return self::exec($sql, $values);
    }

    /**
     * 删除数据
     * @param string $table
     * @param array $where
     * @return bool
     */
    public static function delete($table, $where)
    {
        self::connect();
        $params = [];
        $values = [];
        $valueKeys = 1;
        foreach ($where as $key => $value) {
            $params[] = "{$key} = ?";
            $values[$valueKeys++] = $value;
        }
        $sql = "DELETE FROM `{$table}` WHERE 1=1 AND " . implode(' AND ', $params);
        return self::exec($sql, $values);
    }

    /**
     * 查询数据
     * @param string $table
     * @param array|string $fields
     * @param array|string|null $where
     * @param array|string|null $order
     * @return array|null
     */
    public static function select ($table, $fields, $where = null, $order = null)
    {
        if (empty($fields)) {
            $fields = '*';
        }
        if (is_string($fields)) {
            $sql = "SELECT " . $fields . " FROM `{$table}`";
        } elseif (is_array($fields)) {
            foreach ($fields as $key => $value) {
                if (is_string($key)) {
                    $fields[$key] = '`' . $key . '` AS `' . $value . '`';
                } else {
                    $fields[$key] = '`' . $value . '`';
                }
            }
            $sql = "SELECT " . implode(',', $fields) . " FROM `{$table}`";
        } else {
            $sql = "SELECT * FROM `{$table}`";
        }
        if (!empty($where)) {
            if (is_string($where)) {
                $sql .= ' WHERE ' . $where;
            } else {
                $sql .= ' WHERE 1=1';
                foreach ($where as $v) {
                    $v[1] = strtoupper($v[1]);
                    if ($v[1] == 'CONTAINS') {
                        $sql .= ' AND JSON_CONTAINS(`' . $v[0] . '`, \'' . $v[2] . '\')';
                    } else {
                        $sql .= ' AND ' . $v[0] . ' ';
                        if (array_key_exists(2, $v)) {
                            $sql .= $v[1] . ' ';
                        }
                        if ($v[1] == 'IS') {
                            $sql .= $v[2];
                        } elseif (is_numeric($v[2])) {
                            $sql .= $v[2];
                        } else {
                            $sql .= '\'' . $v[2] . '\'';
                        }
                    }
                }
            }
        }
        if (!empty($order)) {
            $sql .= ' ORDER BY ' . $order;
        }
        return self::queryAll($sql);
    }

    /**
     * 事务
     */
    private static $inTransaction = false;

    public static function inTransaction()
    {
        return self::$inTransaction;
    }

    /**
     * 开始事务
     * @return bool
     */
    public static function beginTransaction()
    {
        self::connect();
        self::$inTransaction = self::$link->beginTransaction();
        return self::$inTransaction;
    }

    /**
     * 提交事务
     * @return bool
     */
    public static function commit()
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
     * 回滚事务
     * @return bool
     */
    public static function rollback()
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

/**
 * 控制器
 */
class Controller
{
    public function __construct()
    {
        $this->init();
    }

    /**
     * 初始化
     */
    protected function init()
    {

    }
}

/**
 * RESTful控制器
 */
class RestfulController extends Controller
{

    public const SERVICE = '';

    /**
     * 服务
     * @var RestfulService|static::SERVICE
     */
    protected $service;

    public function __construct()
    {
        parent::__construct();
        if (empty(static::SERVICE) || !is_subclass_of(static::SERVICE, RestfulService::class)) {
            throw new AppException(9004000001, 'Service class must extend RestfulService!');
        }
        $service = static::SERVICE;
        $this->service = new $service();
    }
}

/**
 * 路由
 */
class Route
{
    /**
     * 路由类
     * @var string
     */
    public $class;
    /**
     * 路由方法
     * @var string
     */
    public $method;
    /**
     * 路由ID
     * @var int
     */
    public $id;
    /**
     * 应用ID
     * @var int
     */
    public $app;
    /**
     * 认证ID
     * @var int
     */
    public $auth;
    /**
     * 请求URI
     * @var string|null
     */
    public $uri = null;
    /**
     * 请求参数
     * @var string|null
     */
    public $req = null;

    /**
     * 构造函数
     * @param array $route
     * @param string|null $uri
     */
    public function __construct($route, $uri = null)
    {
        $this->class = $route['class'];
        $this->method = $route['method'];
        $this->id = intval($route['id']);
        $this->app = intval($route['app']);
        $this->auth = intval($route['auth']);
        $this->req = $route['req'] ?? null;
        $this->uri = $uri;
    }
}

/**
 * 请求
 */
class Request
{
    /**
     * 请求方法
     * @var string
     */
    private $method;

    /**
     * 请求参数
     * @var array
     */
    private $attr = [];

    /**
     * 请求头
     * @var array
     */
    private $header = [];

    /**
     * 请求选项
     * @var array
     */
    private $options = [];

    /**
     * 路由
     * @var Route|null
     */
    private $route = null;

    /**
     * 认证中间件
     * @var AuthMiddleware|null
     */
    private $auth = null;

    /**
     * 请求时间
     * @var int
     */
    private $requestTime = 0;

    /**
     * 日志
     * @var string
     */
    private $log = '';

    /**
     * 构造函数
     * @param array $headers
     */
    public function __construct($headers = [])
    {
        $this->method = strtolower($_SERVER['REQUEST_METHOD'] ?? 'GET');
        foreach ($_GET as $key => $value) {
            $this->attr[$key] = $value;
        }
        // post会覆盖get的参数
        foreach ($_POST as $key => $value) {
            $this->attr[$key] = $value;
        }
        foreach ($_SERVER as $key => $value) {
            if (isset($headers[$key])) {
                $this->header[$headers[$key]] = $value;
            }
        }
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            $input = json_decode($input, true);
            if (!empty($input)) {
                // input覆盖其他参数
                foreach ($input as $key => $value) {
                    $this->attr[$key] = $value;
                }
            }
        }
        $this->requestTime = $_SERVER['REQUEST_TIME'] ?? 0;
        Context::setRequest($this);
    }

    /**
     * 路由分发
     */
    public function dispatch()
    {
        $routeKey = trim($_SERVER['PATH_INFO'] ?? '', '\/');
        if (empty($routeKey)) {
            $routeKey = '/';
        } else {
            // 匹配id
            $routeKey = preg_replace_callback('/\/\d+/', function($matches) {
                $this->setId(intval(substr($matches[0], 1)));
                return '/:id';
            }, $routeKey);
            // 匹配no
            if (!empty(URL_NO) && empty($this->has('id'))) {
                $routeKey = preg_replace_callback('/\/[A-Z]{2}-[A-Za-z0-9]{32}/', function($matches) {
                    $this->setId(substr($matches[0], 1));
                    return '/:id';
                }, $routeKey);
            }
        }

        $route = ROUTES[$routeKey . '@' . $this->method];
        if (empty($route)) {
            throw new AppException(9002010001, 'Access denied!');
        }
        $this->route = new Route($route, $routeKey);
        if (empty(APPS[$this->route->app])) {
            throw new AppException(9002010002, 'Access denied!');
        }
        // 有id需要登录
        $app = APPS[$this->route->app];
        if (!empty($app['auth'])) {
            $this->auth = new $app['auth']($this->route->app);
            if ($this->route->auth != ACL_NON) {
                $this->auth->checkLogin();
            }

            // 需要权限
            if ($this->route->auth == ACL_AUTH) {
                if (!$this->auth->checkAuth($this->route->id)) {
                    throw new AppException(9002010003, 'Access denied!');
                }
            }
        }

        $controller = new ($this->route->class)();
        $action = $this->route->method;
        if (empty($this->route->req)) {
            $resp = $controller->$action();
        } else {
            try {
                $param = new ($this->route->req)();
                $param->init();
            } catch (Throwable $e) {
                if (APP_DEV) {
                    throw $e;
                }
                throw new AppException(9002010004, $e->getMessage());
            }
            $resp = $controller->$action($param);
        }
        return $resp;
    }

    /**
     * 设置id
     * @param int|string $id
     */
    public function setId($id)
    {
        $this->attr['id'] = $id;
    }

    /**
     * 设置选项
     * @param string $key
     * @param mixed $value
     */
    public function setOption($key, $value)
    {
        $this->options[$key] = $value;
    }

    /**
     * 获取选项
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function option($key = null, $default = null)
    {
        if (empty($key)) {
            return $this->options;
        }
        if (!array_key_exists($key, $this->options)) {
            return $default;
        }
        return $this->options[$key];
    }

    /**
     * 获取认证中间件
     * @return ?AuthMiddleware
     */
    public function auth()
    {
        return $this->auth;
    }

    /**
     * 获取请求方法
     * @return string
     */
    public function method()
    {
        return $this->method;
    }

    /**
     * 获取请求时间
     * @return int
     */
    public function requestTime()
    {
        return $this->requestTime;
    }

    /**
     * 获取请求参数
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        return $this->attr[$key] ?? $default;
    }

    /**
     * 获取请求参数数组
     * @param string $key
     * @param string $sep
     * @param array $default
     * @return array
     */
    public function getArr($key, $sep = ',', $default = [])
    {
        if (!array_key_exists($key, $this->attr)) {
            return $default;
        }
        if (!is_array($this->attr[$key])) {
            return explode($sep, $this->attr[$key]);
        }
        return $this->attr[$key];
    }

    /**
     * 获取请求头
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function header($key, $default = null)
    {
        return $this->header[$key] ?? $default;
    }

    /**
     * 检查请求参数是否存在
     * @param string $key
     * @return bool
     */
    public function has($key)
    {
        return array_key_exists($key, $this->attr);
    }

    /**
     * 检查请求头是否存在
     * @param string $key
     * @return bool
     */
    public function hasHeader($key)
    {
        return array_key_exists($key, $this->header);
    }

    /**
     * 获取所有参数
     * @return array
     */
    public function attrs()
    {
        return $this->attr;
    }

    /**
     * 获取所有请求头
     * @return array
     */
    public function headers()
    {
        return $this->header;
    }

    /**
     * 获取日志
     * @return string
     */
    public function getLog()
    {
        $log = '[' . date('Y-m-d H:i:s') . '][' . Ypf::ip() . '] ';
        if (!empty($this->route)) {
            $log .= $this->route->uri . '@' . $this->method;
        }
        $log .= "\nrequest: " . json($this->attr);
        $log .= "\nheader: " . json($this->header);
        if (!empty($this->auth)) {
            $log .= "\nauth: " . $this->auth->userId() . '(' . $this->auth->token() . ')';
        }
        return $log;
    }
}

/**
 * 认证中间件
 */
abstract class AuthMiddleware
{
    /**
     * 过期时间，单位秒
     * @var int
     */
    protected $timeout = 1800;

    /**
     * 应用id
     * @var int
     */
    protected $appId = 0;
    /**
     * 请求
     * @var Request
     */
    protected $request;

    /**
     * 用户名
     * @var string
     */
    protected $username = 'username';
    /**
     * 密码
     * @var string
     */
    protected $password = 'password';
    /**
     * 加密方式
     * @var string
     */
    protected $encrypt = 'md5';
    /**
     * 用户id
     * @var int
     */
    protected $userId = 0;
    /**
     * 令牌id
     * @var int
     */
    protected $tokenId = 0;
    /**
     * 令牌
     * @var string
     */
    protected $token = '';
    /**
     * 角色
     * @var array
     */
    protected $roles = [];
    /**
     * 扩展属性
     * @var array
     */
    protected $attrs = [];

    /**
     * 构造函数
     * @param int $appId 应用id
     */
    public function __construct($appId = 0)
    {
        $this->request = Context::getRequest();
        $this->appId = $appId;
    }

    /**
     * 获取角色
     * @return array
     */
    public function roles()
    {
        return $this->roles;
    }

    /**
     * 获取用户id
     * @return int
     */
    public function userId()
    {
        return $this->userId;
    }

    /**
     * 获取令牌
     * @return string
     */
    public function token()
    {
        return $this->token;
    }

    /**
     * 获取扩展属性
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function extra($key, $default = null)
    {
        return $this->attrs[$key] ?? $default;
    }

    /**
     * 检查认证
     * @param int $apiId
     * @return bool
     */
    abstract public function checkAuth($apiId);

    /**
     * 检查登录
     * @return void
     */
    abstract public function checkLogin();

    /**
     * 退出登录
     * @return void
     */
    abstract public function logout();

    /**
     * 获取令牌
     * @param int $id
     * @param int $expiresIn
     * @return array
     */
    abstract public function getToken($id, $expiresIn = 86400);

    /**
     * 获取用户
     * @return mixed
     */
    abstract public function getUser();

    /**
     * 生成密码哈希
     * @param string $password
     * @return string
     */
    abstract public function makePassword($password);

    /**
     * 验证密码哈希
     * @param string $password
     * @param string $hashPassword
     * @return bool
     */
    abstract public function verifyPassword($password, $hashPassword);
}

/**
 * 命令
 */
abstract class Command
{
    /**
     * 命令参数
     * @var array
     */
    protected $args = [];
    /**
     * 命令参数
     * @var array
     */
    protected $argv = [];
    /**
     * 创建别名
     * @var array
     */
    protected $alias = [];

    /**
     * 构造函数
     * @param array $args
     * @param array $argv
     */
    public function __construct($args = [], $argv = [])
    {
        $this->args = $args;
        $this->argv = $argv;
    }

    /**
     * 处理命令
     * @return void
     */
    public function handle()
    {
        if (isset($this->args['command'])) {
            $command = $this->alias[$this->args['command']] ?? $this->args['command'];
        } elseif (isset($this->args['c'])) {
            $command = $this->alias[$this->args['c']] ?? $this->args['c'];
        } else {
            echo '请输入命令', "\n";
            exit;
        }
        if (!method_exists($this, $command)) {
            throw new Exception($command . '不存在');
        }
        $this->$command();
        echo '操作完成', "\n";
    }

    /**
     * 获取参数
     * @param array $keys
     * @return array
     */
    protected function getArgv($keys)
    {
        if (count($keys) != count($this->argv)) {
            throw new Exception('参数格式错误: ' . implode(' ', $keys));
        }
        $argv = [];
        foreach ($keys as $k => $v) {
            if (array_key_exists($k, $this->argv)) {
                $argv[$v] = $this->argv[$k];
            }
        }
        return $argv;
    }
}

/**
 * 数据
 */
class Data
{
    /**
     * 请求参数
     * @var array
     */
    public const REQ = [];
    /**
     * 响应参数
     * @var array
     */
    public const RESP = [];
    /**
     * 每页数量
     * @var int
     */
    public const PRE_PAGE = 0;

    /**
     * 请求参数
     * @var array
     */
    protected $input = [];
    /**
     * 响应参数
     * @var array
     */
    protected $output = [];
    /**
     * 扩展属性
     * @var array
     */
    protected $attrs = [];
    /**
     * 响应
     * @var array
     */
    protected $resp = [];
    /**
     * 是否分页
     * @var bool
     */
    protected $isPaginated = false;
    /**
     * 请求
     * @var Request
     */
    protected $request;

    public function __construct()
    {
        $this->input = fcheck(static::REQ);
        $this->output = fcheck(static::RESP);
    }

    /**
     * 获取请求
     * @return Request
     */
    public function request()
    {
        return $this->request;
    }

    /**
     * 获取响应
     * @return array
     */
    public function resp()
    {
        $resp = [];
        foreach ($this->output as $k => $v) {
            if (array_key_exists($k, $this->resp)) {
                $resp[$k] = $this->getValue($v->type, $this->resp[$k], $v->name);
            } elseif (!empty($v->default)) {
                $resp[$k] = $v->default;
            } else {
                throw new Exception($v->name . '不能为空');
            }
        }
        return $resp;
    }

    /**
     * 检查扩展属性是否存在
     * @param string $key
     * @return bool
     */
    public function has($key)
    {
        return array_key_exists($key, $this->attrs);
    }

    /**
     * 获取属性
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        return $this->attrs[$key] ?? $default;
    }

    /**
     * 设置属性
     * @param string $key
     * @param mixed $value
     */
    public function set($key, $value)
    {
        $this->resp[$key] = $value;
    }

    /**
     * 使用数组设置属性
     * @param array $array
     */
    public function setArray($array)
    {
        foreach ($array as $k => $v) {
            $this->set($k, $v);
        }
    }

    /**
     * 传递属性
     * @param string $key
     */
    public function pass($key)
    {
        if (!isset($this->input[$key])) {
            throw new Exception($key . '未定义');
        }
        if (!array_key_exists($key, $this->attrs)) {
            throw new Exception($key . '未传入');
        }
        $this->resp[$key] = $this->attrs[$key];
    }

    /**
     * 初始化
     */
    public function init()
    {
        $this->request = Context::getRequest();
        $this->attrs = $this->initAttrs($this->input, $this->request->attrs());
    }

    /**
     * 初始化属性
     * @param array $input
     * @param array $attrs
     * @return array
     */
    protected function initAttrs($input, $attrs)
    {
        $arr = [];
        foreach ($input as $k => $v) {
            if ($v instanceof Field) {
                if (!array_key_exists($k, $attrs)) {
                    if ($v->required) {
                        throw new Exception($v->name . '不能为空');
                    }
                    continue;
                }
                $arr[$k] = $this->getValue($v->type, $attrs[$k], $v->name);
            } elseif (is_array($v)) {
                if (!array_key_exists($k, $attrs)) {
                    if ($v['required']) {
                        throw new Exception($v['name'] . '不能为空');
                    }
                    continue;
                }
                if (empty($v['children'])) {
                    $arr[$k] = $attrs[$k];
                } elseif ($v['format'] == null) {
                    $arr[$k] = $this->initAttrs($v['children'], $attrs[$k]);
                } elseif ($v['format'] == 'array' && is_array($attrs[$k])) {
                    foreach ($attrs[$k] as $kk => $vv) {
                        $arr[$k][$kk] = $this->initAttrs($v['children'], $vv);
                    }
                }
            }
        }
        return $arr;
    }

    /**
     * 获取当前时间
     * @return int
     */
    public function now()
    {
        if (!empty($this->request)) {
            return $this->request->requestTime();
        }
        return time();
    }

    /**
     * 检查字段配置
     * @param array $config
     * @param array $return
     */
    protected function checkField($config, &$return)
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

    /**
     * 获取属性值
     * @param string $type
     * @param mixed $v
     * @param string $name
     * @return mixed
     */
    protected function getValue($type, $v, $name)
    {
        $tmp = explode('|', $type);
        $type = $tmp[0];
        $subType = $tmp[1] ?? null;
        switch ($type) {
            case 'int':
                return intval($v);
            case 'float':
                return floatval($v);
            case 'Time':
                return empty($v) ? null : (is_string($v) ? $v : $v->toString());
            case 'array':
                if (!is_array($v) && $v !== null) {
                    throw new AppException(9003010001, $name . '必须是数组');
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

    /**
     * 获取文档
     * @return array
     */
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

/**
 * 查询构建器
 */
class QueryBuilder
{
    /**
     * 模型
     * @var string
     */
    protected $model = '';
    /**
     * 排序
     * @var string
     */
    protected $order;
    /**
     * 查询条件
     * @var string
     */
    protected $where = '';
    /**
     * 主键
     * @var string|null
     */
    protected $key = null;
    /**
     * 字段
     * @var string
     */
    protected $field = '';
    /**
     * 查询条件
     * @var array
     */
    protected $conditions = [];
    /**
     * 参数
     * @var array
     */
    protected $bindings = [];
    /**
     * 表
     * @var string
     */
    protected $table = '';
    /**
     * 别名
     * @var string
     */
    protected $alias = '';
    /**
     * 连接
     * @var array
     */
    protected $joins = [];
    /**
     * 左连接
     * @var array
     */
    protected $leftJoins = [];

    /**
     * 构造函数
     * @param string $model
     * @param string $alias
     */
    public function __construct($model, $alias = 't1')
    {
        $this->table = '`' . $model::TABLE . '` ' . $alias;
        $this->alias = $alias;
        $this->model = $model;
        $this->field = $alias . '.*';
    }

    /**
     * 设置表
     * @param string $table
     * @return static
     */
    public static function table($table)
    {
        $query = new self(Model::class);
        $query->table = '`' . $table . '` ' . $query->alias;
        return $query;
    }

    /**
     * 构建查询
     * @return array
     */
    public function build()
    {
        return [
            $this->where . ' ' . $this->toSql(),
            $this->bindings,
        ];
    }

    /**
     * 转换为SQL
     * @return string
     */
    public function toSql()
    {
        if (empty($this->conditions)) {
            return '';
        }
        $sql = '';
        foreach ($this->conditions as $v) {
            if ($v[0]) {
                // nested query
                $sql .= ' ' . $v[1] . ' (' . $v[2]->toSql() . ')';
            } else {
                $sql .= ' ' . $v[1] . ' ' . $v[2] . ' ' . $v[3] . ' ' . $v[4];
            }
        }
        return $sql;
    }

    /**
     * 转换为SELECT SQL
     * @return string
     */
    public function toSelectSql()
    {
        return 'SELECT ' . $this->field . ' FROM ' . $this->table . ' ' .
            implode(' ', $this->joins) . ' ' . implode(' ', $this->leftJoins) . ' WHERE 1=1 ' . $this->toSql();
    }

    /**
     * 获取参数
     * @return array
     */
    public function getBindings()
    {
        return $this->bindings;
    }

    /**
     * 获取列名
     * @param string $column
     * @param string|null $alias
     * @return string
     */
    private function column($column, $alias = null)
    {
        return ($alias ?? $this->alias) . '.`' . $column . '`';
    }

    /**
     * 设置查询条件
     * @param string $v1
     * @param string $v2
     * @param mixed $v3
     * @param string|null $alias
     * @return static
     */
    public function where($v1, $v2, $v3 = null, $alias = null)
    {
        if ($v3 === null) {
            $v3 = $v2;
            $v2 = '=';
        }
        $this->conditions[] = [
            false,
            'AND',
            $this->column($v1, $alias),
            $v2,
            '?',
        ];
        $this->bindings[] = $v3;
        return $this;
    }

    /**
     * 设置查询条件 Like
     * @param string $v1
     * @param string $v2
     * @param string|null $alias
     * @return static
     */
    public function whereLike($v1, $v2, $alias = null)
    {
        $this->conditions[] = [
            false,
            'AND',
            $this->column($v1, $alias),
            'LIKE',
            '?',
        ];
        $this->bindings[] = '%' . $v2 . '%';
        return $this;
    }

    /**
     * 设置查询条件 Not Like
     * @param string $v1
     * @param string $v2
     * @param string|null $alias
     * @return static
     */
    public function whereNotLike($v1, $v2, $alias = null)
    {
        $this->conditions[] = [
            false,
            'AND',
            $this->column($v1, $alias),
            'NOT LIKE',
            '?',
        ];
        $this->bindings[] = '%' . $v2 . '%';
        return $this;
    }

    /**
     * 设置查询条件 In
     * @param string $v1
     * @param array $v2
     * @param string|null $alias
     * @return static
     */
    public function whereIn($v1, $v2, $alias = null)
    {
        if (empty($v2)) {
            return $this;
        }
        if ($v2 instanceof QueryBuilder) {
            $this->conditions[] = [
                false,
                'AND',
                $this->column($v1, $alias),
                'IN',
                '(' . $v2->toSelectSql() . ')',
            ];
            $this->bindings = array_merge($this->bindings, $v2->getBindings());
        } else {
            $this->conditions[] = [
                false,
                'AND',
                $this->column($v1, $alias),
                'IN',
                '(' . implode(',', array_fill(0, count($v2), '?')) . ')',
            ];
            $this->bindings = array_merge($this->bindings, $v2);
        }
        return $this;
    }

    /**
     * 设置查询条件 Not In
     * @param string $v1
     * @param array $v2
     * @param string|null $alias
     * @return static
     */
    public function whereNotIn($v1, $v2, $alias = null)
    {
        if (empty($v2)) {
            return $this;
        }
        $this->conditions[] = [
            false,
            'AND',
            $this->column($v1, $alias),
            'NOT IN',
            '(' . implode(',', array_fill(0, count($v2), '?')) . ')',
        ];
        return $this;
    }

    /**
     * 设置查询条件 In Raw
     * @param string $v1
     * @param string $sql
     * @param string|null $alias
     * @return static
     */
    public function whereInRaw($v1, $sql, $alias = null) {
        $this->conditions[] = [
            false,
            'AND',
            $this->column($v1, $alias),
            'IN',
            '(' . $sql . ')',
        ];
        return $this;
    }

    /**
     * 设置查询条件 Not In Raw
     * @param string $v1
     * @param string $sql
     * @param string|null $alias
     * @return static
     */
    public function whereNotInRaw($v1, $sql, $alias = null) {
        $this->conditions[] = [
            false,
            'AND',
            $this->column($v1, $alias),
            'NOT IN',
            '(' . $sql . ')',
        ];
        return $this;
    }

    /**
     * 设置查询条件 Null
     * @param string $v1
     * @param string|null $alias
     * @return static
     */
    public function whereNull($v1, $alias = null)
    {
        $this->conditions[] = [
            false,
            'AND',
            $this->column($v1, $alias),
            'IS',
            'NULL',
        ];
        return $this;
    }

    /**
     * 设置查询条件 Not Null
     * @param string $v1
     * @param string|null $alias
     * @return static
     */
    public function whereNotNull($v1, $alias = null)
    {
        $this->conditions[] = [
            false,
            'AND',
            $this->column($v1, $alias),
            'IS',
            'NOT NULL',
        ];
        return $this;
    }

    /**
     * 设置查询条件 Between
     * @param string $v1
     * @param string $v2
     * @param string $v3
     * @param string|null $alias
     * @return static
     */
    public function whereBetween($v1, $v2, $v3, $alias = null)
    {
        $this->conditions[] = [
            false,
            'AND',
            $this->column($v1, $alias),
            'BETWEEN',
            '? AND ?',
        ];
        $this->bindings[] = $v2;
        $this->bindings[] = $v3;
        return $this;
    }

    /**
     * 设置查询条件 Json Contains
     * @param string $v1
     * @param mixed $v2
     * @param mixed $v3
     * @param bool $isArray
     * @param string|null $alias
     * @return static
     */
    public function jsonContains($v1, $v2, $v3 = null, $isArray = false, $alias = null)
    {
        $conditions = [
            false,
            'AND',
            'JSON_CONTAINS(',
            $this->column($v1, $alias),
            ', ?)',
        ];
        if ($v3 === null) {
            $v3 = $v2;
        } elseif (!$isArray) {
            $conditions[3] = '->"$.' . $v2 . '"';
        } else {
            $conditions[3] = '->"$[*].' . $v2 . '"';
        }
        $this->conditions[] = $conditions;
        $this->bindings[] = json($v3);
        return $this;
    }

    /**
     * 设置查询条件 Or
     * @param Closure $column
     * @return static
     */
    public function or($column)
    {
        $nestedQuery = new self($this->model, $alias ?? $this->alias);
        $column($nestedQuery);

        $this->conditions[] = [
            true,
            'OR',
            $nestedQuery,
        ];
        $this->bindings = array_merge($this->bindings, $nestedQuery->getBindings());
        return $this;
    }

    /**
     * 设置查询条件 And
     * @param Closure $column
     * @return static
     */
    public function and($column)
    {
        $nestedQuery = new self($this->model, $alias ?? $this->alias);
        $column($nestedQuery);

        $this->conditions[] = [
            true,
            'AND',
            $nestedQuery,
        ];
        $this->bindings = array_merge($this->bindings, $nestedQuery->getBindings());
        return $this;
    }

    /**
     * 设置返回数组键
     * @param string $str
     * @return static
     */
    public function key($str)
    {
        $this->key = $str;
        return $this;
    }

    /**
     * 设置 Order
     * @param string $v1
     * @param string $v2
     * @param string|null $alias
     * @return static
     */
    public function order($v1, $v2 = 'asc', $alias = null)
    {
        $this->order = $this->column($v1, $alias) . ' ' . $v2;
        return $this;
    }

    /**
     * 设置主键 Order
     * @return static
     */
    public function primaryOrder()
    {
        if (empty($this->order) && !empty($this->model) && !empty($this->model::PRIMARY)) {
            $this->order = $this->alias . '.`' . $this->model::PRIMARY . '` DESC';
        }
        return $this;
    }

    /**
     * 设置 Select
     * @param array $v1
     * @param string|null $alias
     * @return static
     */
    public function select($v1, $alias = null)
    {
        if (is_string($v1)) {
            $this->field = $this->column($v1, $alias);
        } else {
            $this->field = implode(',', array_map(function($v) {
                if (is_array($v)) {
                    return $v[0] . '.`' . $v[1] . '`';
                }
                return $this->alias . '.`' . $v . '`';
            }, $v1));
        }
        return $this;
    }

    /**
     * 设置 Select Raw
     * @param string $sql
     * @return static
     */
    public function selectRaw($sql)
    {
        $this->field = $sql;
        return $this;
    }

    /**
     * 设置 Join
     * @param string $table
     * @param string $alias
     * @param array $on
     * @param array $fields
     * @return static
     */
    public function join($table, $alias, $on, $fields = [])
    {
        if (empty($fields)) {
            throw new AppException(9006000001, 'fields is empty');
        }
        if (empty($on)) {
            throw new AppException(9006000002, 'on is empty');
        }
        $sql = 'inner join `' . $table . '` ' . $alias . ' ON ' . implode(' AND ', array_map(fn($k, $v) => $alias . '.`' . $k . '` = ' . $this->alias . '.`' . $v . '`', array_keys($on), array_values($on)));
        $this->joins[] = $sql;
        foreach ($fields as $k=>$v) {
            $this->field .= ', ' . $alias . '.`' . $k . '` AS `' . $v . '`';
        }

        return $this;
    }

    /**
     * 设置 Left Join
     * @param string $table
     * @param string $alias
     * @param array $on
     * @param array $fields
     * @param bool $inCount
     * @return static
     */
    public function leftJoin($table, $alias, $on, $fields = [], $inCount = false)
    {
        if (empty($fields)) {
            throw new AppException(9006000001, 'fields is empty');
        }
        if (empty($on)) {
            throw new AppException(9006000002, 'on is empty');
        }
        $sql = 'left join `' . $table . '` ' . $alias . ' ON ' . implode(' AND ', array_map(fn($k, $v) => $alias . '.`' . $k . '` = ' . $this->alias . '.`' . $v . '`', array_keys($on), array_values($on)));
        if ($inCount) {
            $this->joins[] = $sql;
        } else {
            $this->leftJoins[] = $sql;
        }
        foreach ($fields as $k=>$v) {
            $this->field .= ', ' . $alias . '.`' . $k . '` AS `' . $v . '`';
        }

        return $this;
    }

    /**
     * 获取分页
     * @param int $page
     * @param int $size
     * @param bool $model
     * @return array
     */
    public function getPage($page = 1, $size = 20, $model = true)
    {
        $result = [
            QUERY_ITEMS => [],
            QUERY_PAGES => [
                'perPage' => $size,
                'total' => 0,
                'currentPage' => 0,
                'lastPage' => 0,
            ],
        ];
        [$where, $params] = $this->build();
        $sql = 'SELECT count(*) as cnt FROM ' . $this->table . ' ' . implode(' ', $this->joins) . ' WHERE 1=1 ' . $where;
        $rs = DB::execute($sql, $params);
        $row = $rs->fetch(PDO::FETCH_ASSOC);
        if (empty($row) || empty($row['cnt'])) {
            return $result;
        }
        $result[QUERY_PAGES]['total'] = intval($row['cnt']);
        $result[QUERY_PAGES]['lastPage'] = ceil($result[QUERY_PAGES]['total'] / $result[QUERY_PAGES]['perPage']);
        if ($page > $result[QUERY_PAGES]['lastPage']) {
            $page = $result[QUERY_PAGES]['lastPage'];
        } elseif ($page < 1) {
            $page = 1;
        }
        $result[QUERY_PAGES]['currentPage'] = $page;
        $offset = ($page - 1) * $size;
        $sql = 'SELECT ' . $this->field . ' FROM ' . $this->table . ' ' .
            implode(' ', $this->joins) . ' ' . implode(' ', $this->leftJoins) . ' WHERE 1=1 ' . $where;
        if (!empty($this->order)) {
            $sql .= ' ORDER BY ' . $this->order;
        }
        $sql .= ' LIMIT ' . $offset . ', ' . $size;
        $rs = DB::execute($sql, $params);
        if (!$model || empty($this->model)) {
            $result[QUERY_ITEMS] = $rs->fetchAll(PDO::FETCH_ASSOC);
        } else {
            while ($row = $this->fetch()) {
                $result[QUERY_ITEMS][] = new $this->model($row);
            }
        }
        return $result;
    }

    /**
     * 查询结果集
     * @var false|PDOStatement
     */
    protected false|PDOStatement $rs;

    /**
     * 执行查询
     * @param string $field
     * @param bool $first
     * @return static
     */
    public function execute($field = '*', $first = false)
    {
        [$where, $params] = $this->build();
        $sql = 'SELECT ' . $field . ' FROM ' . $this->table . ' ' .
            implode(' ', $this->joins) . ' ' . implode(' ', $this->leftJoins) . ' WHERE 1=1 ' . $where;
        if (!empty($this->order)) {
            $sql .= ' ORDER BY ' . $this->order;
        }
        if ($first) {
            $sql .= ' LIMIT 1';
        }
        $this->rs = DB::execute($sql, $params);
        return $this;
    }

    /**
     * 获取查询结果
     * @return array|null
     */
    public function fetch()
    {
        if (empty($this->rs)) {
            return null;
        }
        $row = $this->rs->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * 获取所有查询结果
     * @return array
     */
    public function fetchAll()
    {
        if (empty($this->rs)) {
            return [];
        }
        return $this->rs->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 获取第一个查询结果
     * @return Model|null
     */
    public function first()
    {
        $this->execute($this->field, true);
        if (empty($this->rs)) {
            return null;
        }
        $row = $this->fetch();
        if (empty($row)) {
            return null;
        }
        if (empty($this->model)) {
            return $row;
        }
        return new $this->model($row);
    }

    /**
     * 获取数量
     * @return int
     */
    public function count()
    {
        $this->execute('count(*) as cnt');
        if (empty($this->rs)) {
            return 0;
        }
        $row = $this->fetch();
        if (empty($row) || empty($row['cnt'])) {
            return 0;
        }
        return intval($row['cnt']);
    }

    /**
     * 获取所有结果
     * @param string|null $resourceClass
     * @return array
     */
    public function get($resourceClass = null)
    {
        $this->execute($this->field, false);
        if (empty($this->rs)) {
            return [];
        }
        if (empty($this->model)) {
            return $this->fetchAll();
        }
        $return = [];
        if (!empty($resourceClass) && is_subclass_of($resourceClass, Resource::class)) {
            if (!empty($this->key)) {
                while ($row = $this->fetch()) {
                    $return[$row[$this->key]] = (new $resourceClass($row))->toArray();
                }
            } else {
                while ($row = $this->fetch()) {
                    $return[] = (new $resourceClass($row))->toArray();
                }
            }
        } else {
            if (!empty($this->key)) {
                while ($row = $this->fetch()) {
                    $return[$row[$this->key]] = new $this->model($row);
                }
            } else {
                while ($row = $this->fetch()) {
                    $return[] = new $this->model($row);
                }
            }
        }
        return $return;
    }

    /**
     * 获取所有结果的数组表示
     * @return array
     */
    public function getArray()
    {
        $list = $this->get();
        if (!empty($list)) {
            foreach ($list as $k=>$v) {
                $list[$k] = $v->toArray();
            }
        }
        return $list;
    }
    /**
     * 获取所有结果的指定字段
     * @param string $field
     * @param string|null $key
     * @return array
     */
    public function pluck($field, $key = null)
    {
        $return = [];
        if (!empty($key)) {
            $this->execute('`' . $field . '`, `' . $key . '`');
            if (empty($this->rs)) {
                return [];
            }
            while ($row = $this->fetch()) {
                $return[$row[$key]] = $row[$field];
            };
        } else {
            $this->execute('`' . $field . '`');
            if (empty($this->rs)) {
                return [];
            }
            while ($row = $this->fetch()) {
                $return[] = $row[$field];
            }
        }
        return $return;
    }
    /**
     * 获取所有数组结果
     * @return array
     */
    public function all()
    {
        $this->execute($this->field);
        if (empty($this->rs)) {
            return [];
        }
        if (empty($this->key)) {
            return $this->fetchAll();
        }
        $return = [];
        while ($row = $this->fetch()) {
            $return[$row[$this->key]] = $row;
        }
        return $return;
    }
}

/**
 * 模型类
 */
class Model
{
    /**
     * 表名
     */
    public const TABLE = '';
    /**
     * 主键
     */
    public const PRIMARY = 'id';
    /**
     * 字段定义
     */
    public const COLUMNS = [];
    /**
     * 唯一索引
     */
    public const UNIQUE = [];

    /**
     * 创建时间字段
     */
    public const CREATED_AT = 'createdAt';
    /**
     * 更新时间字段
     */
    public const UPDATED_AT = 'updatedAt';

    /**
     * 主键值
     * @var mixed
     */
    protected $primary = null;
    /**
     * 字段值
     * @var array
     */
    protected $columns = [];
    /**
     * 更新值
     * @var array
     */
    protected $updated = [];

    /**
     * 构造函数
     * @param array|null $row
     */
    public function __construct($row = null)
    {
        if (!empty($row)) {
            $this->setColumns($row);
        }
    }

    /**
     * 获取主键值
     * @return mixed
     */
    public function primary()
    {
        return $this->primary;
    }

    /**
     * 设置字段值
     * @param array $row
     */
    protected function setColumns($row)
    {
        if (!empty(static::UNIQUE)) {
            $this->primary = [];
        } else {
            $this->primary = null;
        }
        foreach (static::COLUMNS as $k => $v) {
            if (!array_key_exists($v[0], $row)) {
                continue;
            }
            $this->columns[$k] = checkValue($v, $row[$v[0]]);
            if ($k == static::PRIMARY) {
                $this->primary = $this->columns[$k];
            } elseif (in_array($k, static::UNIQUE)) {
                $this->primary[$k] = $this->columns[$k];
            }
        }
        if (!empty(static::UNIQUE) && !empty($this->primary)) {
            foreach (static::UNIQUE as $v) {
                if (empty($this->primary[$v])) {
                    // 唯一索引不完整，清空主键
                    $this->primary = null;
                    break;
                }
            }
        }
    }

    /**
     * 获取字段值
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        if (!array_key_exists($name, static::COLUMNS)) {
            throw new \Exception('column not found');
        }
        return $this->columns[$name] ?? null;
    }

    /**
     * 设置字段值
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value)
    {
        if (!array_key_exists($name, static::COLUMNS)) {
            throw new \Exception('column not found');
        }
        if (!array_key_exists($name, $this->columns)) {
            $this->columns[$name] = checkValue(static::COLUMNS[$name], $value);
        } elseif ($value !== $this->columns[$name]) {
            if ($name == static::PRIMARY || in_array($name, static::UNIQUE)) {
                // 不更新非空主键或唯一键字段
                return;
            }
            $this->columns[$name] = checkValue(static::COLUMNS[$name], $value);
            $this->updated[] = $name;
        }
    }

    /**
     * 检查字段是否存在
     * @param string $name
     * @return bool
     */
    public function __isset($name)
    {
        return array_key_exists($name, static::COLUMNS);
    }

    /**
     * 转换为数组
     * @return array
     */
    public function toArray()
    {
        $arr = [];
        foreach (static::COLUMNS as $k => $v) {
            if (!array_key_exists($k, $this->columns)) {
                $arr[$k] = null;
            } elseif (!empty($this->columns[$k]) && $this->columns[$k] instanceof \Time) {
                $arr[$k] = $this->columns[$k]->toString();
            } else {
                $arr[$k] = $this->columns[$k] ?? null;
            }
        }
        return $arr;
    }

    /**
     * 保存字段值
     * @param string|null $type
     * @param mixed $v
     * @return mixed
     */
    protected function saveValue($type, $v)
    {
        $tmp = explode('|', $type);
        $type = $tmp[0];
        if ($type == 'int') {
            return intval($v);
        } elseif ($type == 'float') {
            return floatval($v);
        } else {
            if ($v === null) {
                return null;
            }
            if ($type == 'Time' || $type == 'DateTime' || $type == 'Date') {
                if ($v instanceof \Time) {
                    return $v->toString();
                } elseif (!is_array($v)) {
                    return json($v);
                }
                return $v;
            } elseif ($type == 'array' || is_object($v)) {
                return json($v);
            }
            return $v;
        }
    }

    /**
     * 保存记录
     * @return static
     */
    public function save()
    {
        $now = new \Time(Ypf::timestamp());
        if ((empty(static::PRIMARY) && empty(static::UNIQUE)) || empty($this->primary)) {
            // 新增
            if (!empty(static::CREATED_AT)) {
                $this->columns[static::CREATED_AT] = $now;
            }
            if (!empty(static::UPDATED_AT)) {
                $this->columns[static::UPDATED_AT] = $now;
            }
            $arr = [];
            foreach (static::COLUMNS as $k => $v) {
                if ($k == 'id' || !array_key_exists($k, $this->columns)) {
                    continue;
                }
                $arr[$v[0]] = $this->saveValue($v[2], $this->columns[$k]);
            }
            DB::insert(static::TABLE, $arr);
            if (static::PRIMARY == 'id') {
                $this->primary = DB::lastInsertId();
                $this->columns['id'] = $this->primary;
            } elseif (!empty(static::UNIQUE)) {
                $this->primary = [];
                foreach (static::UNIQUE as $v) {
                    $this->primary[$v] = $this->columns[$v];
                }
            }
        } elseif (!empty($this->updated)) {
            // 更新
            $arr = [];
            foreach (static::COLUMNS as $k => $v) {
                if (!in_array($k, $this->updated)) {
                    continue;
                }
                $arr[$v[0]] = $this->saveValue($v[2], $this->columns[$k]);
            }
            if (!empty(static::UPDATED_AT)) {
                $arr[static::COLUMNS[static::UPDATED_AT][0]] = $now;
            }
            $where = [];
            if (!empty(static::PRIMARY)) {
                $where[static::COLUMNS[static::PRIMARY][0]] = $this->primary;
            } elseif (!empty(static::UNIQUE)) {
                $where = [];
                foreach (static::UNIQUE as $v) {
                    $where[static::COLUMNS[$v][0]] = $this->primary[$v];
                }
            }
            if (!empty($arr)) {
                DB::update(static::TABLE, $arr, $where);
            }
        }
        return $this;
    }

    /**
     * 删除记录
     * @return static
     */
    public function delete()
    {
        if (empty($this->primary)) {
            throw new \Exception('primary is empty');
        }
        $where = [];
        if (!empty(static::PRIMARY)) {
            $primary = $this->primary;
            $where[static::COLUMNS[static::PRIMARY][0]] = $this->primary;
        } elseif (!empty(static::UNIQUE)) {
            $primary = implode(',', array_values($this->primary));
            foreach (static::UNIQUE as $v) {
                $where[static::COLUMNS[$v][0]] = $this->primary[$v];
            }
        } else {
            throw new \Exception('primary or unique is empty');
        }
        DB::exec("INSERT INTO `delete_histories` (`table`, `primary`, `data`, `deleted_at`) VALUES (?, ?, ?, ?)", [
            static::TABLE,
            $primary,
            json($this->toArray()),
            date('Y-m-d H:i:s', Ypf::timestamp()),
        ]);
        DB::delete(static::TABLE, $where);
    }

    /**
     * 查找记录
     * @param mixed $primaryId
     * @return static|null
     */
    public static function find($primaryId)
    {
        if (is_array($primaryId)) {
            $sql = 'SELECT * FROM `' . static::TABLE . '` WHERE 1=1';
            $params = [];
            $paramsKey = 1;
            foreach ($primaryId as $k => $v) {
                $sql .= ' AND `' . $k . '` = ?';
                $params[$paramsKey++] = $v;
            }
            if (!empty(static::PRIMARY)) {
                $sql .= ' ORDER BY `' . static::COLUMNS[static::PRIMARY][0] . '` desc';
            }
            $sql .= ' LIMIT 1';
        } else {
            if (empty(static::PRIMARY)) {
                throw new \Exception('primary is empty');
            }
            $sql = 'SELECT * FROM `' . static::TABLE . '` WHERE `' . static::COLUMNS[static::PRIMARY][0] . '` = ?';
            $params = [1 => $primaryId];
        }
        $rs = DB::execute($sql, $params);
        $row = $rs->fetch(PDO::FETCH_ASSOC);
        if (empty($row)) {
            return null;
        }
        return new static($row);
    }

    /**
     * 构建查询
     * @param string $alias 别名
     * @return QueryBuilder
     */
    public static function query($alias = 't1')
    {
        return new QueryBuilder(static::class, $alias);
    }
}

/**
 * 资源类
 */
class Resource
{
    /**
     * 字段映射
     */
    public const COLUMNS = [];

    /**
     * 属性值
     */
    protected $attrs = [];

    /**
     * 构造函数
     * @param array|Model $row
     */
    public function __construct($row)
    {
        if (is_array($row)) {
            foreach (static::COLUMNS as $k => $v) {
                if (array_key_exists($v[0], $row)) {
                    $this->attrs[$k] = checkValue($v, $row[$v[0]]);
                } else {
                    $this->attrs[$k] = null;
                }
            }
        } elseif ($row instanceof Model) {
            foreach (static::COLUMNS as $k => $v) {
                if (array_key_exists($k, $row::COLUMNS)) {
                    $this->attrs[$k] = checkValue($v, $row->$k);
                } else {
                    $this->attrs[$k] = null;
                }
            }
        }
        $this->handle();
    }

    /**
     * 处理属性值
     */
    protected function handle()
    {
    }

    /**
     * 转换为数组
     * @return array
     */
    public function toArray()
    {
        return $this->attrs;
    }
}

/**
 * 服务类
 */
class Service
{
    /**
     * 服务ID
     */
    public const SERVICE_ID = 0;

    /**
     * 请求
     * @var Request
     */
    protected $request;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->request = Context::getRequest();
    }

    /**
     * 转换为资源列表
     * @param array $list
     * @param string $resourceClass
     * @return array
     */
    public function toResourceList($list, $resourceClass)
    {
        return array_map(function ($item) use ($resourceClass) {
            return (new $resourceClass($item))->toArray();
        }, $list);
    }
}

/**
 * RESTful服务类
 */
class RestfulService extends Service
{
    /**
     * 列表筛选项
     * @var array
     */
    public static $index = [];
    /**
     * 创建参数
     * @var array
     */
    public static $store = [];
    /**
     * 允许更新字段
     * @var array
     */
    public static $update = [];
    /**
     * 是否可删除
     * @var bool
     */
    public static $delete = true;
    /**
     * 是否分页
     * @var bool
     */
    public static $hasPage = true;
    /**
     * 索引忽略字段
     * @var array
     */
    public static $indexIgnore = [];
    /**
     * 显示忽略字段
     * @var array
     */
    public static $showIgnore = [];
    /**
     * 唯一字段
     * @var array
     */
    public static $unique = [];
    /**
     * 请求头ID映射
     * @var array
     */
    public static $headerId = [];
    /**
     * 模型类
     * @var string
     */
    public static $model = '';
    /**
     * 索引资源类
     * @var string
     */
    public static $indexResource = '';
    /**
     * 显示资源类
     * @var string
     */
    public static $showResource = '';

    public function __construct()
    {
        parent::__construct();

        if (empty(static::$model)) {
            throw new AppException(9005000001, 'model is empty');
        }
        if (!is_subclass_of(static::$model, Model::class)) {
            throw new AppException(9005000002, 'model must be subclass of Model');
        }
        if (!empty(static::$headerId)) {
            foreach (static::$headerId as $k => $v) {
                if (!$this->request->hasHeader($k)) {
                    throw new AppException(9005000003);
                }
            }
        }
    }

    /**
     * 获取记录信息
     * @return Model
     */
    protected function getInfo()
    {
        $info = static::$model::find($this->request->get('id'));
        if (!$info) {
            throw new AppException(9005000004, '指定的记录不存在');
        }
        if (!empty(static::$headerId)) {
            foreach (static::$headerId as $k => $v) {
                if (!isset(static::$model::COLUMNS[$v])) {
                    continue;
                }
                if (is_array($info->$v)) {
                    if (!in_array($this->request->header($k), $info->$v)) {
                        throw new AppException(9005000005, '系统异常');
                    }
                } elseif ($info->$v != $this->request->header($k)) {
                    throw new AppException(9005000006, '系统异常');
                }
            }
        }
        return $info;
    }

    /**
     * 构建查询
     * @param Request $request
     * @param array $index
     * @param string $model
     * @return QueryBuilder
     */
    public static function buildQuery($request, $index, $model)
    {
        $query = $model::query();
        if (!empty($index)) {
            foreach ($index as $v) {
                if (!isset($model::COLUMNS[$v[0]])) {
                    continue;
                }
                if (!$request->has($v[0]) && in_array('required', $v)) {
                    throw new AppException(9005010001, '参数' . $v[1] . '不能为空');
                }
                $dbField = $model::COLUMNS[$v[0]][0];
                if (empty($v[3])) {
                    $operator = '=';
                } else {
                    $operator = strtoupper($v[3]);
                }
                if ($operator == 'BETWEEN') {
                    if ($request->has($v[0] . QUERY_FROM) && $request->has($v[0] . QUERY_TO)) {
                        $query->whereBetween(
                            $dbField,
                            substr($request->get($v[0] . QUERY_FROM), 0, 10) . ' 00:00:00',
                            substr($request->get($v[0] . QUERY_TO), 0, 10) . ' 23:59:59'
                        );
                    }
                } elseif ($request->has($v[0])) {
                    if ($operator == 'IN') {
                        $query->whereIn($dbField, $request->getArr($v[0]));
                    } elseif ($operator == 'NOT IN') {
                        $query->whereNotIn($dbField, $request->getArr($v[0]));
                    } elseif ($operator == 'LIKE') {
                        $query->whereLike($dbField, $request->get($v[0]));
                    } elseif ($operator == 'NOT LIKE') {
                        $query->whereNotLike($dbField, $request->get($v[0]));
                    } elseif ($operator == 'NULL') {
                        $query->whereNull($dbField);
                    } elseif ($operator == 'NOT NULL') {
                        $query->whereNotNull($dbField);
                    } elseif ($operator == 'NULLABLE') {
                        $query->where($dbField, '=', $request->get($v[0]));
                    } else {
                        $query->where($dbField, $operator, $request->get($v[0]));
                    }
                }
            }
        }

        return $query;
    }

    /**
     * 列表操作
     * @return array
     */
    public function index()
    {
        /** @var QueryBuilder $query */
        $query = self::buildQuery($this->request, static::$index, static::$model);
        if (!empty(static::$headerId)) {
            foreach (static::$headerId as $k => $v) {
                if (!isset(static::$model::COLUMNS[$v])) {
                    continue;
                }
                $column = static::$model::COLUMNS[$v];
                if ($column[2] == 'array') {
                    $query->jsonContains($column[0], $this->request->header($k));
                } else {
                    $query->where($column[0], $this->request->header($k));
                }
            }
        }
        $this->beforeIndex($query);
        $query->primaryOrder();

        if (!static::$hasPage) {
            $pages = $query->all();
            $this->getIndexItem($pages);
        } else {
            $pages = $query->getPage(
                $this->request->get(QUERY_PAGE, 1),
                $this->request->get(QUERY_PERPAGE, QUERY_PAGESIZE),
                false
            );
            $this->getIndexItem($pages[QUERY_ITEMS]);
        }

        $this->afterIndex($pages);
        return $pages;
    }

    /**
     * 获取列表项
     * @param array $items
     */
    protected function getIndexItem(&$items)
    {
        if (!empty($items)) {
            if (!empty(static::$indexResource) && is_subclass_of(static::$indexResource, Resource::class)) {
                $items = array_map(function (array $item) {
                    return (new (static::$indexResource)($item))->toArray();
                }, $items);
            } else {
                $items = array_map(function (array $item) {
                    $arr = (new (static::$model)($item))->toArray();
                    if (!empty(static::$indexIgnore)) {
                        foreach (static::$indexIgnore as $k) {
                            if (isset($arr[$k])) {
                                unset($arr[$k]);
                            }
                        }
                    }
                    return $arr;
                }, $items);
            }
        }
    }

    /**
     * 显示操作
     * @return array
     */
    public function show()
    {
        $info = $this->getInfo();
        if (!empty(static::$showResource) && is_subclass_of(static::$showResource, Resource::class)) {
            $return = (new (static::$showResource)($info))->toArray();
        } else {
            $return = $info->toArray();
            if (!empty(static::$showIgnore)) {
                foreach (static::$showIgnore as $k) {
                    if (isset($return[$k])) {
                        unset($return[$k]);
                    }
                }
            }
        }
        $this->afterShow($return);
        return $return;
    }

    /**
     * 获取参数
     * @param Request $request
     * @param array $v
     * @return mixed
     */
    public static function getParam($request, $v)
    {
        if (!$request->has($v[0])) {
            if (array_key_exists(3, $v) && $v[3] === null) {
                throw new AppException(9005000011, '参数' . $v[1] . '不能为空');
            }
            return null;
        }
        $value = $request->get($v[0]);
        if (!empty($v[4]) && is_array($v[4]) && !in_array($value, $v[4])) {
            throw new AppException(9005000012, '参数' . $v[1] . '的值' . $value . '不在' . implode(',', $v[4]) . '中');
        }
        if (is_array($v[2])) {
            foreach ($value as &$vv) {
                foreach ($v[2] as $f) {
                    if (!array_key_exists($f[0], $vv) && array_key_exists(3, $f) && $f[3] === null) {
                        throw new AppException(9005000013, '参数' . $f[1] . '不能为空');
                    }
                }
            }
        }
        return $value;
    }

    /**
     * 存储操作
     * @return bool
     */
    public function store()
    {
        $validated = [];
        $uniqueName = [];
        $extra = [];
        foreach (static::$store as $v) {
            if (!isset(static::$model::COLUMNS[$v[0]])) {
                $extra[$v[0]] = self::getParam($this->request, $v);
            } else {
                $validated[$v[0]] = self::getParam($this->request, $v);
            }
            if (in_array($v[0], static::$unique)) {
                $uniqueName[] = $v[1] . '为' . $validated[$v[0]];
            }
        }
        if (!empty(static::$unique)) {
            $query = static::$model::query();
            foreach (static::$unique as $k) {
                $query->where(static::$model::COLUMNS[$k][0], $validated[$k]);
            }
            if ($query->count() > 0) {
                throw new AppException(9005030005, implode(',', $uniqueName) . '的数据已存在');
            }
        }
        if (!empty(static::$headerId)) {
            foreach (static::$headerId as $k => $v) {
                if (!isset(static::$model::COLUMNS[$v])) {
                    continue;
                }
                $validated[$v] = $this->request->header($k);
            }
        }
        DB::beginTransaction();
        $this->beforeStore($validated, $extra);
        $info = new (static::$model)();
        foreach ($validated as $k => $v) {
            $info->$k = $v;
        }
        $info->save();
        $this->afterStore($info, $extra);
        DB::commit();
        return $info->primary();
    }

    /**
     * 更新操作
     * @return bool
     */
    public function update()
    {
        $validated = [];
        $uniqueName = [];
        foreach (static::$store as $v) {
            if (in_array($v[0], static::$unique)) {
                $uniqueName[$v[0]] = $v[1] . '为';
            }
            if (!in_array($v[0], static::$update)) {
                continue;
            }
            if (!$this->request->has($v[0])) {
                continue;
            }
            $validated[$v[0]] = self::getParam($this->request, $v);
        }
        $info = $this->getInfo();
        if (!empty(static::$unique)) {
            $query = static::$model::query();
            foreach (static::$unique as $k) {
                if (array_key_exists($k, $validated)) {
                    $query->where(static::$model::COLUMNS[$k][0], $validated[$k]);
                    $uniqueName[$k] .= $validated[$k];
                } else {
                    $query->where(static::$model::COLUMNS[$k][0], $info->$k);
                    $uniqueName[$k] .= $info->$k;
                }
            }
            if (!empty(static::$model::PRIMARY)) {
                $query->where(static::$model::PRIMARY, '!=', $info->primary());
            } elseif (!empty(static::$model::UNIQUE)) {
                foreach ($info->primary() as $k => $v) {
                    $query->where($k, '!=', $v);
                }
            }
            if ($query->count() > 0) {
                throw new AppException(9005040005, implode(',', $uniqueName) . '的数据已存在');
            }
        }
        DB::beginTransaction();
        $this->beforeUpdate($validated, $info);
        foreach ($validated as $k => $v) {
            $info->$k = $v;
        }
        $info->save();
        $this->afterUpdate($info);
        DB::commit();
        return true;
    }

    /**
     * 删除操作
     * @return bool
     */
    public function destroy()
    {
        if (!static::$delete) {
            throw new AppException(9005050001, '删除操作被禁用');
        }
        $info = $this->getInfo();
        DB::beginTransaction();
        $this->beforeDestroy($info);
        $info->delete();
        $this->afterDestroy($info);
        DB::commit();
        return true;
    }

    /**
     * 选项操作
     * @param array|string $fields
     * @param array|null $where
     * @param string|null $order
     * @return array
     */
    public function option($fields, $where = null, $order = null)
    {
        if (empty($order) && !empty(static::$model::PRIMARY)) {
            $order = '`' . static::$model::PRIMARY . '` DESC';
        }
        if (!empty(static::$headerId)) {
            if (empty($where)) {
                $where = [];
            }
            foreach (static::$headerId as $k => $v) {
                if (!isset(static::$model::COLUMNS[$v])) {
                    continue;
                }
                $where[] = [static::$model::COLUMNS[$v][0], '=', $this->request->header($k)];
            }
        }
        return DB::select(static::$model::TABLE, $fields, $where, $order);
    }

    /**
     * 列表前置操作
     * @param QueryBuilder $query
     */
    protected function beforeIndex(&$query)
    {
    }

    /**
     * 创建前置操作
     * @param array $validated
     * @param array $extra
     */
    protected function beforeStore(&$validated, &$extra)
    {
    }

    /**
     * 更新前置操作
     * @param array $validated
     * @param Model $info
     */
    protected function beforeUpdate(&$validated, &$info)
    {
    }

    /**
     * 删除前置操作
     * @param Model $info
     */
    protected function beforeDestroy(&$info)
    {
    }

    /**
     * 列表后置操作
     * @param array $pages
     */
    protected function afterIndex(&$pages)
    {
    }

    /**
     * 详情后置操作
     * @param array $return
     */
    protected function afterShow(&$return)
    {
    }

    /**
     * 创建后置操作
     * @param Model $info
     * @param array $extra
     */
    protected function afterStore($info, $extra)
    {
    }

    /**
     * 更新后置操作
     * @param Model $info
     */
    protected function afterUpdate($info)
    {
    }

    /**
     * 删除后置操作
     * @param Model $info
     */
    protected function afterDestroy($info)
    {
    }
}

/**
 * 缓存类
 */
class Cache
{
    /**
     * 设置缓存
     * @param string $key
     * @param mixed $value
     */
    public static function set($key, $value)
    {
        file_put_contents(storage_path('cache/' . $key . '.php'), serialize($value));
    }

    /**
     * 获取缓存
     * @param string $key
     * @return mixed|null
     */
    public static function get($key)
    {
        if (!file_exists(storage_path('cache/' . $key . '.php'))) {
            return null;
        }
        return unserialize(file_get_contents(storage_path('cache/' . $key . '.php')));
    }
}

/**
 * 打印变量
 * @param mixed $expression
 * @return string
 */
function var_output($expression) {
    $export = var_export($expression, TRUE);
    $export = preg_replace("/^([ ]*)(.*)/m", '$1$1$2', $export);
    $array = preg_split("/\r\n|\n|\r/", $export);
    $array = preg_replace(["/\s*array\s\($/", "/\)(,)?$/", "/\s=>\s$/"], [NULL, ']$1', ' => ['], $array);
    $export = join("\n", array_filter(["["] + $array));
    return $export;
}

if (!function_exists('str_contains')) {
    /**
     * 字符串是否包含子字符串
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    function str_contains($haystack, $needle)
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    /**
     * 字符串是否以子字符串开头
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    function str_starts_with($haystack, $needle)
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    /**
     * 字符串是否以子字符串结尾
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    function str_ends_with($haystack, $needle)
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

/**
 * 字段类
 */
class Field
{
    /**
     * 键
     * @var string
     */
    public $key;
    /**
     * 名称
     * @var string
     */
    public $name;
    /**
     * 类型
     * @var string
     */
    public $type;
    /**
     * 是否必填
     * @var bool
     */
    public $required = false;
    /**
     * 默认值
     * @var mixed
     */
    public $default = null;
    /**
     * 校验规则
     * @var mixed
     */
    public $validate;
    /**
     * 格式化规则
     * @var mixed
     */
    public $format;

    /**
     * 构造函数
     * @param string $key
     * @param string $name
     * @param string $type
     * @param mixed $default
     * @param mixed $validate
     * @param mixed $format
     */
    public function __construct($key, $name, $type, $default = null, $validate = null, $format = null)
    {
        $this->key = $key;
        $this->name = $name;
        $this->type = $type;
        if ($default === null) {
            $this->required = true;
        } elseif ($default !== 'nullable') {
            $this->default = $default;
        }
        $this->validate = $validate;
        $this->format = $format;
    }
}

/**
 * 校验表单字段
 * @param array $arr
 * @return array
 */
function fcheck($arr)
{
    if (empty($arr)) {
        return [];
    }
    $a = [];
    foreach ($arr as $k=>$v) {
        if (empty($v[2])) {
            $v[2] = 'string';
        }
        if (!array_key_exists(3, $v)) {
            $v[3] = 'nullable';
        }
        if (is_numeric($k)) {
            $k = $v[0];
        }
        if (is_array($v[2])) {
            if (is_string($v[2][0]) && is_array($v[2][1])) {
                if (!is_subclass_of($v[2][0], Model::class)) {
                    throw new Exception($v[2][0] . '不是模型类');
                }
                if (empty($v[0])) {
                    $tmp = &$a;
                } else {
                    $a[$k] = [
                        'name' => $v[1],
                        'children' => [],
                        'required' => $v[3] === null,
                    ];
                    $tmp = &$a[$k]['children'];
                }
                foreach ($v[2][0]::COLUMNS as $kc=>$vc) {
                    if (in_array($kc, $v[2][1]) || $kc == 'deleted_at') {
                        continue;
                    }
                    $tmp[$kc] = new Field($vc[0], $vc[1], $vc[2], $v[3] ?? null);
                }
            } else {
                $a[$k] = [
                    'name' => $v[1],
                    'children' => fcheck($v[2]),
                    'required' => $v[3] === null,
                    'format' => $v[4] ?? null,
                ];
            }
        } else {
            $a[$k] = new Field($v[0], $v[1], $v[2], $v[3] ?? null, $v[4] ?? null, $v[5] ?? null);
        }
    }
    return $a;
}

/**
 * 打印数据
 * @param mixed $data
 * @param bool $exit
 */
function d($data, $exit = false)
{
    if (is_object($data) || is_array($data)) {
        echo json($data), "\n";
    } else {
        echo $data, "\n";
    }
    if ($exit) {
        exit;
    }
}

/**
 * 校验值
 * @param array $column
 * @param mixed $v
 * @return mixed
 */
function checkValue($column, $v)
{
    if ($v === null) {
        if (!in_array('nullable', $column)) {
            throw new \Exception($column[1] . '不能为空');
        }
        return null;
    }
    $tmp = explode('|', $column[2]);
    $type = $tmp[0];
    switch ($type) {
        case 'int':
            return intval($v);
        case 'float':
            return floatval($v);
        case 'Date':
            return new \Time($v, 'Y-m-d');
        case 'Time':
        case 'DateTime':
            return new \Time($v);
        case 'array':
            if (!is_array($v)) {
                $v = json_decode($v, true);
            }
            return $v;
        case 'string':
            return strval($v);
        default:
            return $v;
    }
}

/**
 * 获取存储路径
 * @param string $path
 * @return string
 */
function storage_path($path)
{
    return APP_PATH . '/storage/' . $path;
}

/**
 * 获取公共路径
 * @param string $path
 * @return string
 */
function public_path($path)
{
    return APP_PATH . '/public/' . $path;
}

/**
 * 获取日志路径
 * @param string $path
 * @return string
 */
function log_path($path)
{
    return storage_path('logs/' . $path);
}

/**
 * 转换为JSON字符串
 * @param mixed $data
 * @return string
 */
function json($data)
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * 生成UUID
 * @param bool $min
 * @return string
 */
function uuid($prefix = '', $min = true) {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
    return $prefix . vsprintf($min ? '%s%s%s%s%s%s%s%s' : '%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * 授权：无
 */
const ACL_NON = 0;
/**
 * 授权：登录
 */
const ACL_LOGIN = 1;
/**
 * 授权：认证
 */
const ACL_AUTH = 2;

/**
 * 常量类
 */
class Consts
{
    /**
     * 禁用
     */
    public const DISABLED = 0;
    /**
     * 启用
     */
    public const ENABLED = 1;
    /**
     * 离线
     */
    public const OFFLINE = 0;
    /**
     * 在线
     */
    public const ONLINE = 1;

    /**
     * 关闭
     */
    public const OFF = 0;
    /**
     * 开启
     */
    public const ON = 1;

    /**
     * 审核中
     */
    public const REVIEW_PENDING = 0;
    /**
     * 审核通过
     */
    public const REVIEW_APPROVED = 1;
    /**
     * 审核拒绝
     */
    public const REVIEW_REJECTED = 2;
    /**
     * 审核撤销
     */
    public const REVIEW_CANCELED = 3;
}

/**
 * 时间类
 */
class Time
{
    /**
     * 时间戳
     * @var int|null
     */
    private $timestamp;
    /**
     * 时间字符串
     * @var string|null
     */
    private $timeStr;
    /**
     * 时间格式
     * @var string
     */
    private $format;

    /**
     * 构造函数
     * @param int|string|null $time
     * @param string $format
     */
    public function __construct($time = null, $format = 'Y-m-d H:i:s')
    {
        if (empty($time)) {
            $this->timestamp = null;
            $this->timeStr = null;
        } elseif (is_int($time)) {
            $this->timestamp = $time;
            $this->timeStr = date($format, $time);
        } else {
            $this->timestamp = strtotime($time);
            $this->timeStr = date($format, $this->timestamp);
        }
        $this->format = $format;
    }

    /**
     * 获取时间戳
     * @return int|null
     */
    public function timestamp()
    {
        return $this->timestamp;
    }

    /**
     * 转换为字符串
     * @return string|null
     */
    public function toString()
    {
        return $this->timeStr;
    }

    /**
     * 添加天数
     * @param int $days
     * @return string|null
     */
    public function addDays($days = 1)
    {
        return date($this->format, $this->timestamp + $days * 86400);
    }

    /**
     * 转换为字符串
     * @return string|null
     */
    public function __toString()
    {
        return $this->timeStr;
    }
}

/**
 * 日志类
 */
class Logger
{
    public const PATH = APP_PATH . '/storage/log/';

    /**
     * 记录信息日志
     * @param string $msg
     */
    public static function info($msg)
    {
        self::log('info', $msg);
    }

    /**
     * 记录错误日志
     * @param string $msg
     */
    public static function error($msg)
    {
        self::log('error', $msg);
    }

    /**
     * 记录调试日志
     * @param string $msg
     */
    public static function debug($msg)
    {
        self::log('debug', $msg);
    }

    /**
     * 记录日志
     * @param string $file
     * @param string $msg
     */
    public static function log($file, $msg)
    {
        $path = self::PATH . date('Y-m-d');
        if (!is_dir($path)) {
            mkdir($path, 0766, true);
        }
        file_put_contents($path . '/' . $file . '.log', $msg . "\n", FILE_APPEND);
    }
}

/**
 * 上下文类
 */
class Context
{
    protected static $staticContext = [];

    /**
     * 设置请求上下文
     * @param Request $request
     */
    public static function setRequest($request)
    {
        self::set('request', $request);
    }

    /**
     * @return Request
     */
    public static function getRequest()
    {
        return self::get('request');
    }

    /**
     * @return Time|null
     */
    public static function now()
    {
        return self::get('now');
    }

    /**
     * 设置上下文数据
     * @param string $key
     * @param mixed $value
     */
    public static function set($key, $value)
    {
        self::$staticContext[$key] = $value;
    }

    /**
     * 获取上下文数据
     * @param string $key
     * @param mixed $default
     * @return mixed|null
     */
    public static function get($key, $default = null)
    {
        return self::$staticContext[$key] ?? $default;
    }

    /**
     * 判断上下文中是否存在指定 Key
     * @param string $key
     * @return bool
     */
    public static function has($key)
    {
        return array_key_exists($key, self::$staticContext);
    }

    /**
     * 删除上下文中的指定 Key
     * @param string $key
     */
    public static function delete($key)
    {
        unset(self::$staticContext[$key]);
    }
}

class Str
{
    /**
     * base64 编码
     * @param string $data
     * @return string
     */
    public static function base64Encode($data)
    {
        return rtrim(base64_encode($data), '=');
    }

    /**
     * base64 解码
     * @param string $data
     * @return string|null
     */
    public static function base64Decode($data)
    {
        $padding = (4 - (strlen($data) % 4)) % 4;
        $str = base64_decode($data . str_repeat('=', $padding), true);
        return $str === false ? null : $str;
    }

    /**
     * URL-safe base64 编码
     * @param string $data
     * @return string
     */
    public static function urlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), ['+' => '-', '/' => '_']), '=');
    }

    /**
     * URL-safe base64 解码
     * @param string $data
     * @return string|null
     */
    public static function urlDecode($data)
    {
        $padding = 4 - (strlen($data) % 4);
        if ($padding !== 4) {
            $data .= str_repeat('=', $padding);
        }
        $str = base64_decode(strtr($data, ['-' => '+', '_' => '/']), true);
        return $str === false ? null : $str;
    }

    /**
     * 生成 URL-safe base64 随机字符串（无 padding）
     * @param int $size
     * @return string
     */
    public static function randomString($size)
    {
        return self::urlEncode(random_bytes((int) ceil($size * 6 / 8)));
    }

    /**
     * 将 snake 命名法转换为驼峰命名法
     * @param string $string
     * @param bool $ucfirst
     * @return string
     */
    public static function snakeToCamel($string, $ucfirst = true)
    {
        return self::lowerToCamel($string, '_', $ucfirst);
    }

    /**
     * 将 kebab 命名法转换为驼峰命名法
     * @param string $string
     * @param bool $ucfirst
     * @return string
     */
    public static function kebabToCamel($string, $ucfirst = true)
    {
        return self::lowerToCamel($string, '-', $ucfirst);
    }

    /**
     * 将包含分隔符的字符串转换为驼峰命名法
     * @param string $string
     * @param string $separator
     * @param bool $ucfirst
     * @return string
     */
    public static function lowerToCamel($string, $separator, $ucfirst = true)
    {
        return self::arrayToCamel(explode($separator, strtolower($string)), $ucfirst);
    }

    /**
     * 将 snake 命名法转换为驼峰命名法（单数）
     * @param string $string
     * @param bool $ucfirst
     * @return string
     */
    public static function snakeToCamelSingular($string, $ucfirst = true)
    {
        $words = explode('_', strtolower($string));
        $lastWord = array_pop($words);
        $lastWord = self::pluralToSingular($lastWord);
        $words[] = $lastWord;
        return self::arrayToCamel($words, $ucfirst);
    }

    /**
     * 将驼峰命名法转换为 snake 命名法
     * @param string $str
     * @return string
     */
    public static function camelToSnake($str)
    {
        return self::camelToLower($str, '_');
    }

    /**
     * 将驼峰命名法转换为 kebab 命名法
     * @param string $str
     * @return string
     */
    public static function camelToKebab($str)
    {
        return self::camelToLower($str, '-');
    }

    /**
     * 将驼峰命名法转换为字符分割的字符串
     * @param string $str
     * @param string $separator
     * @return string
     */
    public static function camelToLower($str, $separator = '_')
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1' . $separator . '$2', $str));
    }

    /**
     * 简易版英语复数转单数
     * @param string $word
     * @return string
     */
    public static function pluralToSingular($word)
    {
        // 不可数名词（单复数同形）
        $uncountable = ['equipment', 'information', 'money', 'species', 'series', 'fish', 'sheep', 'data', 'news'];
        if (in_array(strtolower($word), $uncountable)) {
            return $word;
        }

        // 英语单复数正则替换规则 (注意顺序：从最特殊到最普通)
        $rules = [
            '/(quiz)zes$/i' => '$1',
            '/(matr)ices$/i' => '$1ix',
            '/(vert|ind)ices$/i' => '$1ex',
            '/^(ox)en$/i' => '$1',
            '/(alias|status)es$/i' => '$1',
            '/([octop|vir])i$/i' => '$1us',
            '/(cris|ax|test)es$/i' => '$1is',
            '/(shoe)s$/i' => '$1',
            '/(o)es$/i' => '$1',
            '/(bus)es$/i' => '$1',
            '/([m|l])ice$/i' => '$1ouse',
            '/(x|ch|ss|sh)es$/i' => '$1',
            '/(m)ovies$/i' => '$1ovie',
            '/(s)eries$/i' => '$1eries',
            '/([^aeiouy]|qu)ies$/i' => '$1y', // 例如: categories -> category
            '/([lr])ves$/i' => '$1f',         // 例如: halves -> half
            '/(tive)s$/i' => '$1',
            '/(hive)s$/i' => '$1',
            '/([^fo])ves$/i' => '$1fe',        // 例如: wives -> wife
            '/(us)es$/i' => '$1',
            '/s$/i' => '',                    // 最普通的去 s
        ];

        foreach ($rules as $rule => $replacement) {
            if (preg_match($rule, $word)) {
                return preg_replace($rule, $replacement, $word);
            }
        }

        return $word;
    }

    /**
     * 将数组转换为驼峰命名法
     * @param array $arr
     * @param bool $ucfirst
     * @return string
     */
    private static function arrayToCamel($arr, $ucfirst = true)
    {
        $str = '';
        foreach ($arr as $k=>$v) {
            if ($k === 0 && !$ucfirst) {
                $str .= $v;
            } else {
                $str .= ucfirst($v);
            }
        }
        return $str;
    }
}

class Arr
{
    /**
     * 数组键值对转换为关联数组
     * @param array $arr
     * @param string $key
     * @param string $value
     * @return array
     */
    public static function keyToAssoc($arr, $key = 'id', $value = 'name')
    {
        $return = [];
        foreach ($arr as $k=>$v) {
            $return[] = [
                $key => $k,
                $value => $v,
            ];
        }
        return $return;
    }
}

class Hash
{
    /**
     * Bcrypt 默认的加密成本
     */
    public const COST = 10;

    /**
     * 生成密码哈希
     *
     * @param  string  $value
     * @param  int|null  $cost
     * @return string
     */
    public static function make($value, $cost = null)
    {
        $hash = password_hash($value, PASSWORD_BCRYPT, [
            'cost' => $cost ?? self::COST,
        ]);

        if ($hash === false) {
            throw new AppException(8001010001, 'Bcrypt hashing not supported.');
        }

        return $hash;
    }

    /**
     * 验证密码是否匹配
     * @param  string  $value 明文密码
     * @param  string|null  $hashedValue 数据库里的哈希
     * @return bool
     */
    public static function verify($value, $hashedValue)
    {
        if ($hashedValue === null || strlen($hashedValue) === 0) {
            return false;
        }

        return password_verify($value, $hashedValue);
    }
}
