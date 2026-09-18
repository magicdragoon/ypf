<?php
namespace App\Command;

include APP_PATH . '/YpfTools.php';

use Command;
use DB;
use Model;
use YpfTools;

/**
 * 创建
 * php artisan make
 */
class MakeCommand extends Command
{
    /**
     * 创建别名
     * @var array
     */
    protected $alias = [
        'c' => 'controller',
        's' => 'service',
        'm' => 'model',
        'cs' => 'controller_service',
        'r' => 'restful',
        'i' => 'init',
    ];


    protected function controller()
    {
        [$file, $content] = $this->getController();
        file_put_contents($file, $content);
        echo "创建控制器{$file}成功\n";
        exit;
    }

    protected function service()
    {
        [$serviceFile, $serviceContent] = $this->getService();
        file_put_contents($serviceFile, $serviceContent);
        echo "创建服务{$serviceFile}成功\n";
        exit;
    }

    protected function model()
    {
        $args = $this->getArgv(['table']);
        $table = DB::query("SELECT TABLE_NAME, TABLE_COMMENT FROM information_schema.TABLES
            WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA = '{DB['dbname']}' AND TABLE_NAME = '{$args['table']}'", [], true);
        if (empty($table)) {
            echo "表{$args['table']}不存在\n";
            exit;
        }
        YpfTools::scanTable(DB['dbname'], $table, true);
    }

    protected function controller_service()
    {
        $args = $this->getArgv(['class', 'path', 'app', 'name', 'uri']);
        [$controllerFile, $controllerContent] = $this->getController($args);
        [$serviceFile, $serviceContent] = $this->getService($args);
        file_put_contents($controllerFile, $controllerContent);
        file_put_contents($serviceFile, $serviceContent);
        echo "创建控制器{$controllerFile}, 服务{$serviceFile}成功\n";
        exit;
    }

    protected function restful()
    {
        if (count($this->argv) == 5) {
            $args = $this->getArgv(['class', 'path', 'app', 'name', 'uri']);
            $args['model'] = $args['class'];
        } else {
            $args = $this->getArgv(['class', 'path', 'app', 'name', 'uri', 'model']);
        }
        [$controllerFile, $controllerContent] = $this->getController($args, true);
        [$serviceFile, $serviceContent] = $this->getService($args, true);
        file_put_contents($controllerFile, $controllerContent);
        file_put_contents($serviceFile, $serviceContent);
        echo "创建控制器{$controllerFile}, 服务{$serviceFile}成功\n";
        exit;
    }

    /**
     * 创建控制器
     * @param array|null $args
     * @param bool $restful
     * @return array
     */
    private function getController($args = null, $restful = false)
    {
        if (empty($args)) {
            $args = $this->getArgv(['class', 'path', 'app', 'name', 'uri']);
        }

        $args['path'] = trim($args['path'], '\\');
        $path = APP_PATH . '/app/Controller/' . $args['path'] . '/';
        $file = $path . $args['class'] . 'Controller.php';
        if (file_exists($file)) {
            echo "控制器{$args['class']}已存在\n";
            exit;
        }
        $id = YpfTools::scanMaxControllerId($path);
        if (empty($id)) {
            echo '未扫描到最大ID', "\n";
            exit;
        }
        $id++;
        $arr = [
            '{class}' => $args['class'],
            '{path}' => empty($args['path']) ? '' : ('\\' . $args['path']),
            '{app}' => empty($args['app']) ? 1 : intval($args['app']),
            '{id}' => $id,
            '{name}' => trim($args['name']),
            '{uri}' => trim($args['uri'], '/'),
        ];
        return [$file, str_replace(array_keys($arr), array_values($arr), $restful ? self::TEMP_RESTFUL_CONTROLLER : self::TEMP_CONTROLLER)];
    }

    /**
     * 创建服务
     * @param array|null $args
     * @param bool $restful
     * @return array
     */
    private function getService($args = null, $restful = false)
    {
        if (empty($args)) {
            if ($restful) {
                $args = $this->getArgv(['class', 'path', 'model']);
            } else {
                $args = $this->getArgv(['class', 'path']);
            }
        }
        $args['path'] = trim($args['path'], '\\');
        $path = APP_PATH . '/app/Service/' . $args['path'] . '/';
        $file = $path . $args['class'] . 'Service.php';
        if (file_exists($file)) {
            echo "服务{$args['class']}已存在\n";
            exit;
        }
        $id = YpfTools::scanMaxServiceId($path);
        if (empty($id)) {
            echo '未扫描到最大ID', "\n";
            exit;
        }
        $id++;
        $arr = [
            '{class}' => $args['class'],
            '{path}' => empty($args['path']) ? '' : ('\\' . $args['path']),
            '{id}' => $id,
        ];
        if ($restful) {
            $modelName = $args['model'];
            $modelClass = 'App\Model\\' . $args['model'];
            if (!class_exists($modelClass)) {
                echo "模型{$modelClass}不存在\n";
                exit;
            }
            if (!is_subclass_of($modelClass, Model::class)) {
                echo "模型{$modelClass}不是Model的子类\n";
                exit;
            }
            $index = '';
            $store = '';
            $update = '';
            foreach ($modelClass::COLUMNS as $k=>$v) {
                // 主键，创建时间，更新时间跳过
                if ($v[0] == $modelClass::PRIMARY) {
                    continue;
                }
                // TODO: JSON处理
                $indexType = ($v[2] == 'Time') ? 'string\', \'between' : $v[2];
                $index .= "\n        ['" . $k . "', '" . $v[1] . "', '" . $indexType . "'],";
                if ($k == $modelClass::CREATED_AT || $k == $modelClass::UPDATED_AT) {
                    continue;
                }
                $store .= "\n        ['" . $k . "', '" . $v[1] . "', '" . $v[2] . "', " . (empty($v[3]) ? 'null' : '') . "],";
                $update .= "\n        '" . $k . "',";
            }
            $arr['{modelClass}'] = $modelClass;
            $arr['{model}'] = $modelName;
            $arr['{index}'] = $index;
            $arr['{store}'] = $store;
            $arr['{update}'] = $update;
        }
        return [$file, str_replace(array_keys($arr), array_values($arr), $restful ? self::TEMP_RESTFUL_SERVICE : self::TEMP_SERVICE)];
    }

    protected function init()
    {
        if (isset($this->args['force']) && $this->args['force'] == 'true') {
            $force = true;
        } else {
            $force = false;
        }
        $index = file_get_contents(APP_PATH . '/public/index.php');
        // 正则表达式：专门捕获 APP_VERSION 的值
        $pattern = "/define\s*\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/i";
        preg_match($pattern, $index, $matches);
        $appVersion = $matches[1] ?? '';
        if (empty($appVersion)) {
            echo "APP_VERSION 未定义\n";
            exit;
        } else {
            define('APP_VERSION', $appVersion);
        }
        YpfTools::scanDb(DB['dbname'], $force, ['adonis_schema', 'adonis_schema_versions', 'delete_histories']);
        YpfTools::init();

        // 初始化权限
        DB::execute('TRUNCATE TABLE `auths`');
        foreach (YpfTools::getAuth() as $id=>$auth) {
            DB::insert('auths', [
                'id' => $id,
                'parent' => $auth['parent'],
                'app' => $auth['app'],
                'key' => $auth['key'],
                'name' => $auth['name'],
                'is_api' => $auth['is_leaf'] ? 1 : 0,
            ]);
        }
        echo '初始化完成', "\n";
    }

    const TEMP_CONTROLLER = <<<'EOF'
<?php
namespace App\Controller{path};

use Controller;

/**
 * @app {app}
 * @id {id}00
 * @name {name}
 */
class {class}Controller extends Controller
{
    /**
     * @id {id}01
     * @uri {uri}
     * @name {name}列表
     * @allow GET
     */
    public function index()
    {
        return [];
    }

}

EOF;

    const TEMP_RESTFUL_CONTROLLER = <<<'EOF'
<?php
namespace App\Controller{path};

use App\Service{path}\{class}Service;
use RestfulController;

/**
 * @app {app}
 * @id {id}00
 * @name {name}
 */
class {class}Controller extends RestfulController
{
    public const SERVICE = {class}Service::class;

    /**
     * @id {id}01
     * @uri {uri}
     * @name {name}列表
     * @allow GET
     */
    public function index()
    {
        return $this->service->index();
    }

    /**
     * @id {id}02
     * @uri {uri}/:id
     * @name {name}详情
     * @allow GET
     */
    public function show()
    {
        return $this->service->show();
    }

    /**
     * @id {id}03
     * @uri {uri}
     * @name {name}创建
     * @allow POST
     */
    public function store()
    {
        return $this->service->store();
    }

    /**
     * @id {id}04
     * @uri {uri}/:id
     * @name {name}更新
     * @allow PUT
     */
    public function update()
    {
        return $this->service->update();
    }

    /**
     * @id {id}05
     * @uri {uri}/:id
     * @name {name}删除
     * @allow DELETE
     */
    public function destroy()
    {
        return $this->service->destroy();
    }
}

EOF;

    const TEMP_SERVICE = <<<'EOF'
<?php
namespace App\Service{path};

use Service;

class {class}Service extends Service
{
    public const SERVICE_ID = {id};

}

EOF;

    const TEMP_RESTFUL_SERVICE = <<<'EOF'
<?php
namespace App\Service{path};

use {modelClass};
use RestfulService;

class {class}Service extends RestfulService
{
    public const SERVICE_ID = {id};
    public static $index = [{index}
    ];
    public static $store = [{store}
    ];
    public static $update = [{update}
    ];
    public static $model = {model}::class;
}

EOF;
}
