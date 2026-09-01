<?php
namespace App\Model;

use Model;

/**
 * 应用
 */
class App extends Model
{
    protected string $table = 'app';
    ###generated###
    
    /**
     * 主键
     */
    public int $id;

    /**
     * 名称
     */
    public string $name;

    /**
     * 应用Key
     */
    public string $key;

    /**
     * 应用密钥
     */
    public string $secret;

    /**
     * 应用类型
     */
    public int $type;

    /**
     * 鉴权
     */
    public string $auth;

    /**
     * 供应商设置
     */
    public array $config;

    /**
     * 是否删除
     */
    public int $deleted;

    /**
     * 创建时间
     */
    public string $createTime;

    /**
     * 更新时间
     */
    public string $updateTime;

    protected function columns()
    {
        $this->columns = [
            'id' => f('id', '主键', 'int'),
            'name' => f('name', '名称', 'string'),
            'key' => f('key', '应用Key', 'string'),
            'secret' => f('secret', '应用密钥', 'string'),
            'type' => f('type', '应用类型', 'int'),
            'auth' => f('auth', '鉴权', 'string'),
            'config' => f('config', '供应商设置', 'array'),
            'deleted' => f('deleted', '是否删除', 'int'),
            'create_time' => f('createTime', '创建时间', 'string'),
            'update_time' => f('updateTime', '更新时间', 'string'),
        ];
    }
    ###generated###
}
