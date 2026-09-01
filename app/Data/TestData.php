<?php
namespace App\Data;

use Data;

class TestData extends Data
{
    protected function config()
    {
        $this->i('id', '编号', 'int', null);
        $this->i('name', '名称', 'string', '默认名称');

        $this->o('id', '编号', 'int', null);
        $this->o('name', '名称', 'string', '默认名称');
    }
}