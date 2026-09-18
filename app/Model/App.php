<?php
namespace App\Model;

use Model;

/**
 * 应用
 * @property int $id 主键
 * @property string $name 名称
 * @property string|null $key 应用Key
 * @property string|null $secret 应用密钥
 * @property int|null $type 应用类型
 * @property string|null $auth 鉴权
 * @property array|null $config 供应商设置
 * @property int $deleted 是否删除
 * @property \Time $createTime 创建时间
 * @property \Time $updateTime 更新时间
 */
class App extends Model
{
    public const TABLE = 'apps';
    public const CREATED_AT = 'createTime';
    public const UPDATED_AT = 'updateTime';

    public const COLUMNS = [
        'id' => ['id', '主键', 'int'],
        'name' => ['name', '名称', 'string'],
        'key' => ['key', '应用Key', 'string', 'nullable'],
        'secret' => ['secret', '应用密钥', 'string', 'nullable'],
        'type' => ['type', '应用类型', 'int', 'nullable'],
        'auth' => ['auth', '鉴权', 'string', 'nullable'],
        'config' => ['config', '供应商设置', 'array', 'nullable'],
        'deleted' => ['deleted', '是否删除', 'int'],
        'createTime' => ['create_time', '创建时间', 'DateTime'],
        'updateTime' => ['update_time', '更新时间', 'DateTime'],
    ];
}
