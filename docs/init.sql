CREATE TABLE `apps` (
  `id` int unsigned NOT NULL COMMENT '主键',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '名称',
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '应用Key',
  `secret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '应用密钥',
  `type` tinyint unsigned DEFAULT '0' COMMENT '应用类型',
  `auth` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '鉴权',
  `config` json DEFAULT NULL COMMENT '供应商设置',
  `deleted` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '是否删除',
  `create_time` datetime NOT NULL COMMENT '创建时间',
  `update_time` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='应用';

INSERT INTO `apps` (`id`, `name`, `key`, `secret`, `type`, `auth`, `config`, `deleted`, `create_time`, `update_time`) VALUES (0, '开发', '', '', 0, '', NULL, 0, '2026-08-14 15:23:01', '2026-08-14 15:23:01');

CREATE TABLE `auths` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '编号',
  `parent` int unsigned NOT NULL DEFAULT '0' COMMENT '父编号',
  `app` int unsigned NOT NULL DEFAULT '0' COMMENT '应用',
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '键',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '名称',
  `is_api` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '是否是接口',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='权限';

CREATE TABLE `employees` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '编号',
  `merchant_ids` json NOT NULL COMMENT '所属商家',
  `focus_merchant_ids` json DEFAULT NULL COMMENT '关注商家',
  `roles` json DEFAULT NULL COMMENT '角色列表',
  `mobile` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '手机',
  `nickname` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '昵称',
  `avatar` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '头像',
  `username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '用户名',
  `password` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '密码',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT '更新时间',
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `idx_username` (`username`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='员工';

CREATE TABLE `employee_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '编号',
  `employee_id` int NOT NULL COMMENT '员工编号',
  `token` char(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '凭证',
  `expired_at` datetime NOT NULL COMMENT '过期时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `idxToken` (`token`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='员工凭证';

CREATE TABLE `roles` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '编号',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '名称',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='角色';

CREATE TABLE `role_auths` (
  `role_id` int unsigned NOT NULL COMMENT '角色编号',
  `auth_id` int unsigned NOT NULL COMMENT '权限编号',
  PRIMARY KEY (`role_id`,`auth_id`),
  KEY `idx_auth` (`auth_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色权限';
