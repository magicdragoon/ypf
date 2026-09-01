<?php
return [
    0 => [
        'id' => 0,
        'name' => '开发',
        'docs' => [
            0 => [
                'id' => '990000',
                'parent' => 0,
                'name' => '开发工具',
                'key' => 'dev',
                'method' => NULL,
                'uri' => NULL,
            ],
            1 => [
                'id' => '99000001',
                'parent' => 990000,
                'name' => 'index',
                'key' => 'dev.index',
                'method' => 'get',
                'uri' => '/',
            ],
            2 => [
                'id' => '99000002',
                'parent' => 990000,
                'name' => '文档',
                'key' => 'dev.docs',
                'method' => 'get',
                'uri' => 'dev/docs',
            ],
            3 => [
                'id' => '99000003',
                'parent' => 990000,
                'name' => '权限测试',
                'key' => 'dev.auth',
                'method' => 'get',
                'uri' => 'dev/auth',
            ],
            4 => [
                'id' => '99000004',
                'parent' => 990000,
                'name' => '测试',
                'key' => 'dev.test',
                'method' => 'get',
                'uri' => 'dev/test',
                'request' => [
                    0 => [
                        'field' => 'id',
                        'name' => '编号',
                        'type' => 'int',
                        'required' => true,
                        'default' => NULL,
                        'validate' => '',
                    ],
                    1 => [
                        'field' => 'name',
                        'name' => '名称',
                        'type' => 'string',
                        'required' => false,
                        'default' => '默认名称',
                        'validate' => '',
                    ],
                ],
                'response' => [
                    0 => [
                        'field' => 'id',
                        'name' => '编号',
                        'type' => 'int',
                        'default' => NULL,
                    ],
                    1 => [
                        'field' => 'name',
                        'name' => '名称',
                        'type' => 'string',
                        'default' => '默认名称',
                    ],
                ],
            ],
        ],
    ],
];