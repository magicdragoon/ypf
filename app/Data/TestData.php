<?php
namespace App\Data;

use Data;

class TestData extends Data
{
    public const REQ = [
        ['id', '编号', 'int', null],
        ['name', '名称', 'string'],
    ];
    public const RESP = [
        ['id', '编号', 'int', null],
        ['name', '名称', 'string'],
    ];
}
