<?php
namespace App\Model;

use Model;

/**
 * 权限
 * @property int $id 编号
 * @property int $parent 父编号
 * @property int $app 应用
 * @property string $key 键
 * @property string $name 名称
 * @property int $isApi 是否是接口
 */
class Auth extends Model
{
    public const TABLE = 'auths';
    public const CREATED_AT = null;
    public const UPDATED_AT = null;

    public const COLUMNS = [
        'id' => ['id', '编号', 'int'],
        'parent' => ['parent', '父编号', 'int'],
        'app' => ['app', '应用', 'int'],
        'key' => ['key', '键', 'string'],
        'name' => ['name', '名称', 'string'],
        'isApi' => ['is_api', '是否是接口', 'int'],
    ];
}
