<?php

namespace App\Controller;

use App\Data\TestData;
use app\Service\DevService;
use YpfController;

/**
 * @app 0
 * @id 990000
 * @name 开发工具
 * @auth ACL_NON
 */
class DevController extends YpfController
{

    protected function init()
    {
        if (!APP_DEV) {
            throw new \Exception('系统异常');
        }
    }

    /**
     * @id 99000001
     * @uri /
     * @name index
     * @allow GET
     */
    public function index()
    {
        echo 'hello world!';
    }

    /**
     * @id 99000002
     * @uri dev/docs
     * @name 文档
     * @allow GET
     */
    public function docs()
    {
        echo json(include APP_PATH . '/storage/docs.php');
    }

    /**
     * @id 99000003
     * @uri dev/auth
     * @name 权限测试
     * @allow GET
     */
    public function auth()
    {

    }

    /**
     * @id 99000004
     * @uri dev/test
     * @name 测试
     * @allow GET
     */
    public function test(TestData $data)
    {
        return (new DevService())->test($data);
    }
}