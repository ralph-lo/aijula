-- 数据库表结构修正和问题修复

-- 1. 修正 fa_other_messages 表结构
-- 原表缺少必要的字段，需要添加 user_id 字段来支持用户下注记录

ALTER TABLE `fa_other_messages` 
ADD COLUMN `user_id` int(11) DEFAULT NULL COMMENT '用户ID' AFTER `room_id`,
ADD COLUMN `bet_amount` decimal(10,2) DEFAULT 0.00 COMMENT '下注金额' AFTER `message`,
ADD INDEX `idx_user_id` (`user_id`),
ADD INDEX `idx_room_createtime` (`room_id`, `createtime`);

-- 2. 确保 chat_index 表有正确的结构
-- 如果表不存在，创建它
CREATE TABLE IF NOT EXISTS `fa_chat_index` (
  `idx_id` int(11) NOT NULL AUTO_INCREMENT COMMENT '索引ID',
  `room_id` int(11) NOT NULL COMMENT '房间ID',
  `msg_id` int(11) NOT NULL COMMENT '消息ID',
  `type` varchar(20) NOT NULL COMMENT '消息类型',
  `createtime` int(11) NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`idx_id`),
  KEY `idx_room_type_time` (`room_id`, `type`, `createtime`),
  KEY `idx_createtime_id` (`createtime`, `idx_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='聊天消息索引表';

-- 3. 修正现有数据
-- 为现有的 other_messages 记录添加 user_id（如果缺失）
-- 注意：这里需要根据实际情况设置正确的 user_id

-- 示例：为测试数据设置默认用户ID（实际使用时需要根据真实数据调整）
UPDATE `fa_other_messages` 
SET `user_id` = 2 
WHERE `user_id` IS NULL AND `message` LIKE '%"type":"bet"%';

-- 4. 添加数据完整性检查
-- 确保 chat_index 表中的记录与实际消息表对应

-- 检查并修复缺失的索引记录
INSERT IGNORE INTO `fa_chat_index` (`room_id`, `msg_id`, `type`, `createtime`)
SELECT 
    `room_id`,
    `id` as `msg_id`,
    'user_bet' as `type`,
    `createtime`
FROM `fa_other_messages` 
WHERE `message` LIKE '%"type":"bet"%'
AND NOT EXISTS (
    SELECT 1 FROM `fa_chat_index` 
    WHERE `fa_chat_index`.`msg_id` = `fa_other_messages`.`id`
    AND `fa_chat_index`.`type` = 'user_bet'
);

-- 5. 性能优化索引
-- 为 frequently queried columns 添加复合索引

ALTER TABLE `fa_other_messages` 
ADD INDEX `idx_type_createtime` (`type`, `createtime`),
ADD INDEX `idx_user_createtime` (`user_id`, `createtime`);

-- 6. 数据清理和优化
-- 删除可能存在的重复或无效记录

DELETE FROM `fa_other_messages` 
WHERE `message` IS NULL OR `message` = '';

-- 7. 添加约束确保数据完整性
ALTER TABLE `fa_other_messages` 
MODIFY COLUMN `message` json NOT NULL COMMENT '消息内容',
MODIFY COLUMN `createtime` int(11) NOT NULL COMMENT '创建时间';

-- 8. 创建视图简化查询（可选）
CREATE OR REPLACE VIEW `v_user_bets` AS
SELECT 
    om.id,
    om.room_id,
    om.user_id,
    om.message,
    om.createtime,
    JSON_EXTRACT(om.message, '$.data.bet') as bet_text,
    JSON_EXTRACT(om.message, '$.data.nickname') as nickname,
    JSON_EXTRACT(om.message, '$.data.avatar') as avatar
FROM `fa_other_messages` om
WHERE JSON_EXTRACT(om.message, '$.type') = 'bet';

-- 9. 添加触发器确保数据一致性（可选）
DELIMITER $$

CREATE TRIGGER `tr_other_messages_after_insert` 
AFTER INSERT ON `fa_other_messages`
FOR EACH ROW
BEGIN
    -- 自动在 chat_index 表中创建对应记录
    INSERT IGNORE INTO `fa_chat_index` (`room_id`, `msg_id`, `type`, `createtime`)
    VALUES (NEW.room_id, NEW.id, 'user_bet', NEW.createtime);
END$$

DELIMITER ;

-- 10. 性能监控表（可选）
CREATE TABLE IF NOT EXISTS `fa_performance_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(50) NOT NULL COMMENT '操作类型',
  `room_id` int(11) DEFAULT NULL COMMENT '房间ID',
  `duration` decimal(10,3) DEFAULT NULL COMMENT '执行时间(秒)',
  `memory_usage` int(11) DEFAULT NULL COMMENT '内存使用(KB)',
  `db_queries` int(11) DEFAULT NULL COMMENT '数据库查询次数',
  `cache_hits` int(11) DEFAULT NULL COMMENT '缓存命中次数',
  `error_message` text COMMENT '错误信息',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_type_created` (`type`, `created_at`),
  KEY `idx_room_created` (`room_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='性能监控日志表';

-- 11. 数据验证查询
-- 检查数据完整性的查询语句

-- 检查是否有孤立的 chat_index 记录
SELECT COUNT(*) as orphaned_index_records
FROM `fa_chat_index` ci
LEFT JOIN `fa_other_messages` om ON ci.msg_id = om.id AND ci.type = 'user_bet'
WHERE om.id IS NULL AND ci.type = 'user_bet';

-- 检查是否有缺失索引的消息记录
SELECT COUNT(*) as missing_index_records
FROM `fa_other_messages` om
LEFT JOIN `fa_chat_index` ci ON om.id = ci.msg_id AND ci.type = 'user_bet'
WHERE ci.msg_id IS NULL AND om.message LIKE '%"type":"bet"%';

-- 12. 清理和优化建议
-- 定期执行的维护语句

-- 清理过期缓存（如果使用文件缓存）
-- DELETE FROM cache WHERE expire_time < UNIX_TIMESTAMP();

-- 优化表结构
-- OPTIMIZE TABLE `fa_other_messages`;
-- OPTIMIZE TABLE `fa_chat_index`;

-- 分析表统计信息
-- ANALYZE TABLE `fa_other_messages`;
-- ANALYZE TABLE `fa_chat_index`;