-- ============================================================================
-- Sikelan 用户表
-- 日期: 2026-09-15
-- MySQL 8.0+ / InnoDB / utf8mb4 / 应用层统一 UTC
--
-- 设计约定:
--   1. 所有 NOT NULL 字段必须有 DEFAULT（严格模式下 INSERT 不报错）
--   2. 密码用 bcrypt 哈希存储，绝不存明文（框架 bcrypt() 函数）
--   3. 业务必填字段（username/email/password）由应用层 Validator 拦截空值，
--      DB 层默认值仅作兜底
--   4. 审计时间用 DATETIME(3)，与项目其他表一致
--   5. 可重复执行（CREATE TABLE IF NOT EXISTS）
-- ============================================================================

CREATE TABLE IF NOT EXISTS `users` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
    `username`          VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '登录用户名',
    `email`             VARCHAR(128)    NOT NULL DEFAULT '' COMMENT '邮箱（登录凭证之一）',
    `password`          VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '密码（bcrypt 哈希，绝不存明文）',
    `nickname`          VARCHAR(64)     NOT NULL DEFAULT '' COMMENT '昵称',
    `avatar`            VARCHAR(255)    NULL COMMENT '头像 URL',
    `role`              VARCHAR(20)     NOT NULL DEFAULT 'trader' COMMENT '角色 admin/trader/viewer',
    `status`            VARCHAR(16)     NOT NULL DEFAULT 'active' COMMENT '状态 active/disabled',
    `email_verified_at` DATETIME(3)     NULL COMMENT '邮箱验证时间（NULL=未验证）',
    `last_login_at`     DATETIME(3)     NULL COMMENT '最后登录时间',
    `last_login_ip`     VARCHAR(45)     NULL COMMENT '最后登录 IP（兼容 IPv6）',
    `remember_token`    VARCHAR(100)    NULL COMMENT '记住我 Token',
    `created_at`        DATETIME(3)     NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间（UTC）',
    `updated_at`        DATETIME(3)     NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间（UTC）',
    `deleted_at`        DATETIME(3)     NULL COMMENT '软删除时间（NULL=未删除）',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_username` (`username`),
    UNIQUE KEY `uk_users_email` (`email`),
    KEY `idx_users_status` (`status`),
    KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表';
