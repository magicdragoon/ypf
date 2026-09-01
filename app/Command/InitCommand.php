<?php
namespace App\Command;

use DB;
use Ypf;
use YpfCommand;
use YpfTools;

class InitCommand extends YpfCommand
{
    public function handle()
    {
        include APP_PATH . '/YpfTools.php';
        $index = file_get_contents(APP_PATH . '/public/index.php');
        // 正则表达式：专门捕获 APP_VERSION 的值
        $pattern = "/define\s*\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/i";
        preg_match($pattern, $index, $matches);
        $appVersion = $matches[1] ?? '';
        if (empty($appVersion)) {
            echo 'APP_VERSION 未定义' . PHP_EOL;
            exit;
        } else {
            define('APP_VERSION', $appVersion);
        }
        $config = Ypf::getConfig();
        YpfTools::init($config);

        $hasAuth = YpfTools::scanDb($config['db']['dbname']);
        if ($hasAuth) {
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
        }
        echo '初始化完成' . PHP_EOL;
    }
}