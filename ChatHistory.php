<?php
namespace app\api\controller;

use think\Controller;
use think\Exception;
use think\Request;
use app\common\model\ChatIndex;
use think\Db;
use app\common\library\TimeSync;
use think\Cache;

class Chathistory extends Controller
{
    protected $noNeedRight = ['history'];
    
    // 🔥 性能优化配置
    private $cachePrefix = 'chat_history:';
    private $cacheExpire = 300; // 5分钟缓存
    private $maxPerPage = 50; // 限制单次查询数量
    private $defaultPerPage = 20;
    
    /**
     * 🔥 严格分页逻辑说明：
     *
     * 为确保分页数据绝对无重复，使用 createtime + idx_id 组合条件：
     *
     * 1.首页：不传 last_time 和 last_id，直接按 createtime DESC， idx_id DESC 排序
     * 2.下页：传入上页最后一条的 last_time 和 last_id（对应idx_id）
     * 3.查询条件：（createtime < last_time） OR （createtime = last_time AND idx_id < last_id）
     *
     * 这样即使存在相同时间戳的消息，也能通过 idx_id 确保分页唯一性和连续性
     *
     * 🔥 缓存策略：
     * - 首页请求（last_time=0）：不使用缓存，确保最新消息的实时性
     * - 分页请求（last_time>0）：使用缓存，提高历史数据查询性能
     */
    
    // 🔥 监控统计
    private $performanceStats = [
        'start_time' => 0,
        'db_queries' => 0,
        'cache_hits' => 0,
        'memory_usage' => 0
    ];

    public function history(Request $request)
    {
        // 🔥 性能监控开始
        $this->performanceStats['start_time'] = microtime(true);
        $this->performanceStats['memory_usage'] = memory_get_usage();
        
        try {
            // 1.🔥 优化参数验证
            $p = $this->validateParamsOptimized($request);
            $roomId = $p['room_id'];
            $perPage = $p['per_page'];
            $lastTime = $p['last_time'];
            $lastId = $p['last_id']; // 🔥 新增 last_id
            
            // 2.🔥 缓存策略 - 仅对分页请求使用缓存（last_time > 0）
            $useCache = $lastTime > 0; // 🔥 首页请求（last_time=0）不使用缓存，确保实时性
            $cacheKey = $this->getCacheKey($roomId, $perPage, $lastTime, $lastId);
            
            if ($useCache) {
                $cachedResult = Cache::get($cacheKey);
                if ($cachedResult !== false) {
                    $this->performanceStats['cache_hits']++;
                    $this->logPerformance('cache_hit', $roomId);
                    return json($cachedResult);
                }
            }
            
            // 3.🔥 优化数据库查询 - 单次联合查询
            $result = $this->getHistoryMessagesOptimized($roomId, $perPage, $lastTime, $lastId);
            
            // 4.🔥 缓存结果（仅缓存分页数据，首页数据保持实时）
            if ($useCache) {
                Cache::set($cacheKey, $result, $this->cacheExpire);
            }
            
            // 5.🔥 性能日志
            $this->logPerformance('success', $roomId);
            
            return json($result);
            
        } catch (Exception $e) {
            // 🔥 错误监控
            $this->logPerformance('error', $roomId ?? 0, $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage()], 500);
        }
    }
    
    // 🔥 优化的历史消息获取方法
    private function getHistoryMessagesOptimized($roomId, $perPage, $lastTime, $lastId = 0)
    {
        // 🔥 使用ThinkPHP查询构建器，避免表名问题
        $query = Db::name('chat_index')
            ->where('room_id', $roomId);
        
        // 🔥 严格分页逻辑：使用 createtime + idx_id 组合条件确保绝对无重复
        if ($lastTime > 0) {
            if ($lastId > 0) {
                // 🔥 组合条件：时间小于last_time，或者时间等于last_time但idx_id小于last_id
                $query->where(function($query) use ($lastTime, $lastId) {
                    $query->where('createtime', '<', $lastTime)
                        ->whereOr(function($query) use ($lastTime, $lastId) {
                            $query->where('createtime', $lastTime)
                                ->where('idx_id', '<', $lastId);
                        });
                });
            } else {
                $query->where('createtime', '<', $lastTime);
            }
        }
        
        // 🔥 分步查询：先获取索引，再批量查询具体内容
        $this->performanceStats['db_queries']++;
        $indexRows = $query
            ->order('createtime DESC, idx_id DESC') // 🔥 添加 idx_id 排序确保唯一性
            ->limit($perPage)
            ->select();
        
        if (empty($indexRows)) {
            return [
                'code' => 1,
                'data' => [
                    'per_page' => $perPage,
                    'last_time' => null,
                    'last_id' => null,
                    'data' => [],
                ],
                'msg' => '成功',
            ];
        }
        
        // 🔥 按类型分组并批量查询
        $messageData = $this->batchFetchMessageData($indexRows);
        $rows = $this->combineIndexWithData($indexRows, $messageData);
        
        // 2.🔥 批量处理用户信息
        $userIds = [];
        $robotIds = [];
        foreach ($rows as $row) {
            if ($row['type'] === 'user_bet' && $row['sender_id']) {
                $userIds[] = $row['sender_id'];
            } elseif ($row['type'] === 'robot_bet' && $row['sender_id']) {
                $robotIds[] = substr($row['sender_id'], 6); // 移除前缀
            }
        }
        
        // 🔥 批量查询用户和机器人信息
        $userInfoMap = [];
        $robotInfoMap = [];
        
        if (!empty($userIds)) {
            $this->performanceStats['db_queries']++;
            $users = Db::name('user')->where('id', 'in', array_unique($userIds))->select();
            foreach ($users as $user) {
                $userInfoMap[$user['id']] = $user;
            }
        }
        
        if (!empty($robotIds)) {
            $this->performanceStats['db_queries']++;
            $robots = Db::name('game_robot')->where('id', 'in', array_unique($robotIds))->select();
            foreach ($robots as $robot) {
                $robotInfoMap[$robot['id']] = $robot;
            }
        }
        
        // 3.🔥 优化消息格式化
        $result = [];
        $groupedBets = $this->groupBetMessages($rows);
        $processedGroups = [];
        
        foreach ($rows as $row) {
            if (!$row['message_content'] && !in_array($row['type'], ['user_bet', 'robot_bet'])) {
                continue;
            }
            
            $base = [
                'id' => $row['msg_id'],
                'type' => $row['type'],
                'time' => date('H:i:s', $row['createtime']),
                'timestamp_unix' => $row['createtime'],
            ];
            
            // 🔥 优化消息处理逻辑
            $this->processMessageByType($base, $row, $groupedBets, $processedGroups, $userInfoMap, $robotInfoMap);
            
            if (!empty($base['message'])) {
                $result[] = $base;
            }
        }
        
        // 🔥 获取最后一条记录的时间戳和idx_id，用于下页分页
        $lastRow = end($indexRows); // 🔥 使用原始索引数据获取idx_id
        
        return [
            'code' => 1,
            'data' => [
                'per_page' => $perPage,
                'last_time' => $lastRow['createtime'] ?? null, // 🔥 当前页最后一条数据的时间戳
                'last_id' => $lastRow['idx_id'] ?? null, // 🔥 当前页最后一条数据的idx_id，确保分页唯一性
                'data' => $result,
                // 🔥 性能统计（可选，调试时使用）
                '_performance' => $this->getPerformanceStats()
            ],
            'msg' => '成功',
        ];
    }
    
    // 🔥 批量获取消息数据
    private function batchFetchMessageData($indexRows)
    {
        $messageData = [];
        $typeGroups = [];
        
        // 按类型分组
        foreach ($indexRows as $row) {
            $typeGroups[$row['type']][] = $row['msg_id'];
        }
        
        // 表名映射
        $tableMap = [
            'seal' => 'seal_remind_messages',
            'stop' => 'stop_betting_messages',
            'ads1' => 'advertisement_messages_1',
            'ads2' => 'advertisement_messages_2',
            'verify' => 'bet_verification_messages',
            'start' => 'start_betting_messages',
            'draws' => 'draws_messages',
            'chat' => 'chat_logs',
            'bill' => 'bill_messages',
            'user_bet' => 'other_messages', // 修正：user_bet应该映射到other_messages
            'robot_bet' => 'robot_bet_logs',
        ];
        
        // 批量查询每种类型的数据
        foreach ($typeGroups as $type => $msgIds) {
            if (!isset($tableMap[$type]) || empty($msgIds)) {
                continue;
            }
            
            $this->performanceStats['db_queries']++;
            $rows = Db::name($tableMap[$type])
                ->where('id', 'in', $msgIds)
                ->select();
            
            foreach ($rows as $row) {
                $messageData[$type][$row['id']] = $row;
            }
        }
        
        return $messageData;
    }
    
    // 🔥 合并索引和消息数据
    private function combineIndexWithData($indexRows, $messageData)
    {
        $result = [];
        
        foreach ($indexRows as $indexRow) {
            $type = $indexRow['type'];
            $msgId = $indexRow['msg_id'];
            
            // 获取对应的消息数据
            $msgData = $messageData[$type][$msgId] ?? null;
            if (!$msgData) {
                continue;
            }
            
            // 合并数据
            $combined = [
                'id' => $msgId,
                'msg_id' => $msgId,
                'type' => $type,
                'createtime' => $indexRow['createtime'],
                'room_id' => $indexRow['room_id'],
            ];
            
            // 根据类型添加特定字段
            if (in_array($type, ['user_bet', 'robot_bet'])) {
                // 修正：从message字段中解析bet_info和bet_amount
                $messageJson = json_decode($msgData['message'], true);
                $combined['message_content'] = $messageJson['data']['bet'] ?? '';
                $combined['bet_amount'] = $this->extractBetAmount($messageJson['data']['bet'] ?? '');
                $combined['sender_id'] = $type === 'user_bet' ? $msgData['user_id'] : $msgData['robot_id'];
            } else {
                $combined['message_content'] = $msgData['message'] ?? '';
            }
            
            $result[] = $combined;
        }
        
        return $result;
    }
    
    // 🔥 从下注文本中提取金额
    private function extractBetAmount($betText)
    {
        // 从"大59"、"小100"等文本中提取数字
        if (preg_match('/(\d+)$/', $betText, $matches)) {
            return (int)$matches[1];
        }
        return 0;
    }

    // 🔥 优化的消息分组
    private function groupBetMessages($rows)
    {
        $grouped = [];
        foreach ($rows as $row) {
            if (in_array($row['type'], ['user_bet', 'robot_bet'])) {
                $senderId = $row['sender_id'];
                $groupKey = $senderId . '_' . $row['createtime'];
                $grouped[$groupKey][] = $row;
            }
        }
        return $grouped;
    }
    
    // 🔥 按类型处理消息
    private function processMessageByType(&$base, $row, $groupedBets, &$processedGroups, $userInfoMap, $robotInfoMap)
    {
        switch ($row['type']) {
            case 'robot_bet':
                $this->processBetMessage($base, $row, $groupedBets, $processedGroups, $userInfoMap, $robotInfoMap);
                break;
            case 'user_bet':
                $this->processBetMessage2($base, $row);
                break;
            case 'draws':
                $this->processDrawsMessage($base, $row);
                break;
            case 'chat':
                $this->processChatMessage($base, $row);
                break;
            default:
                $this->processOtherMessage($base, $row);
                break;
        }
    }
    
    // 🔥 处理下注消息
    private function processBetMessage2(&$base, $row)
    {
        $json = json_decode($row['message_content'], true) ?: [];
        
        // 修正：从message_content中获取用户信息
        $avatar = $json['data']['avatar'] ?? '';
        if (empty($avatar)) {
            $avatar = letter_avatar($json['data']['nickname'] ?? '玩家');
        }

        $base['message']['data'] = [
            'avatar' => $avatar,
            'nickname' => $json['data']['nickname'] ?? '玩家',
            'is_robot' => false,
            'bet' => $json['data']['bet'] ?? '',
            'time' => $json['data']['time'] ?? date('H:i:s', $row['createtime']),
        ];
    }
    
    private function processBetMessage(&$base, $row, $groupedBets, &$processedGroups, $userInfoMap, $robotInfoMap)
    {
        $senderId = $row['sender_id'];
        $groupKey = $senderId . '_' . $row['createtime'];
        
        if (isset($processedGroups[$groupKey])) {
            $base['message'] = null; // 标记为已处理
            return;
        }
        $processedGroups[$groupKey] = true;
        
        $isRobot = $row['type'] === 'robot_bet';
        $infoId = $isRobot ? (int)substr($senderId, 6) : $senderId;
        $infoRow = $isRobot ? ($robotInfoMap[$infoId] ?? null) : ($userInfoMap[$infoId] ?? null);
        
        if (!$infoRow) {
            $base['message'] = null;
            return;
        }
        
        $avatar = empty($infoRow['avatar']) ? letter_avatar($infoRow['nickname']) : $infoRow['avatar'];
        
        // 🔥 优化下注文本处理
        $betText = $this->formatBetText($groupedBets[$groupKey] ?? [$row]);
        
        $base['message']['data'] = [
            'avatar' => $avatar,
            'nickname' => $infoRow['nickname'] ?? ($isRobot ? '玩家' : '游客'),
            'is_robot' => $isRobot,
            'bet' => $betText,
            'time' => date('H:i:s', $row['createtime']),
        ];
    }
    
    // 🔥 处理开奖消息
    private function processDrawsMessage(&$base, $row)
    {
        $json = json_decode($row['message_content'], true) ?: [];
        $base['message']['data'] = [
            'number' => $json['data']['number'] ?? '',
            'issue' => $json['data']['issue'] ?? '',
            'time' => $json['data']['time'] ?? date('H:i:s', $row['createtime']),
        ];
    }
    
    // 🔥 处理聊天消息
    private function processChatMessage(&$base, $row)
    {
        $json = json_decode($row['message_content'], true) ?: [];
        $base['message'] = $json;
    }
    
    // 🔥 处理其他消息
    private function processOtherMessage(&$base, $row)
    {
        $json = json_decode($row['message_content'], true) ?: [];
        $content = $json['data']['content'] ?? '';
        
        $base['message'] = [
            'data' => [
                'content' => str_replace('\r\n', "\n", $content),
                'time' => date('H:i:s', $row['createtime']),
            ]
        ];
    }
    
    // 🔥 优化下注文本格式化
    private function formatBetText($betRows)
    {
        if (count($betRows) > 1) {
            $combinedBets = [];
            foreach ($betRows as $betRow) {
                $combinedBets[] = $this->formatBetInfo($betRow['message_content'], $betRow['bet_amount']);
            }
            return implode('，', $combinedBets);
        } else {
            return $this->formatBetInfo($betRows[0]['message_content'], $betRows[0]['bet_amount']);
        }
    }
    
    // 🔥 生成缓存键
    private function getCacheKey($roomId, $perPage, $lastTime, $lastId = 0)
    {
        return $this->cachePrefix . md5("room_{$roomId}_page_{$perPage}_time_{$lastTime}_id_{$lastId}");
    }
    
    // 🔥 优化参数验证
    private function validateParamsOptimized(Request $r)
    {
        $p = $r->only(['room_id', 'per_page', 'last_time', 'last_id']);
        
        if (empty($p['room_id']) || !is_numeric($p['room_id']) || $p['room_id'] <= 0) {
            throw new Exception('无效的房间ID');
        }
        
        $p['room_id'] = (int)$p['room_id'];
        $p['per_page'] = isset($p['per_page'])
            ? min($this->maxPerPage, max(1, (int)$p['per_page']))
            : $this->defaultPerPage;
        $p['last_time'] = isset($p['last_time']) ? max(0, (int)$p['last_time']) : 0;
        $p['last_id'] = isset($p['last_id']) ? max(0, (int)$p['last_id']) : 0; // 🔥 新增 last_id 参数
        
        return $p;
    }
    
    // 🔥 性能监控和日志
    private function logPerformance($type, $roomId, $error = null)
    {
        $duration = microtime(true) - $this->performanceStats['start_time'];
        $memoryUsage = memory_get_usage() - $this->performanceStats['memory_usage'];
        
        $logData = [
            'type' => $type,
            'room_id' => $roomId,
            'duration' => round($duration * 1000, 2) . 'ms',
            'memory_usage' => round($memoryUsage / 1024, 2) . 'KB',
            'db_queries' => $this->performanceStats['db_queries'],
            'cache_hits' => $this->performanceStats['cache_hits'],
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if ($error) {
            $logData['error'] = $error;
        }
        
        // 🔥 记录性能日志（可以写入文件或发送到监控系统）
        if ($duration > 1.0) { // 超过1秒的慢查询
            error_log('SLOW_QUERY: ' . json_encode($logData));
        }
        
        // 🔥 可以在这里添加监控告警逻辑
        if ($type === 'error') {
            error_log('CHAT_HISTORY_ERROR: ' . json_encode($logData));
        }
    }
    
    // 🔥 获取性能统计
    private function getPerformanceStats()
    {
        return [
            'duration' => round((microtime(true) - $this->performanceStats['start_time']) * 1000, 2) . 'ms',
            'memory_usage' => round((memory_get_usage() - $this->performanceStats['memory_usage']) / 1024, 2) . 'KB',
            'db_queries' => $this->performanceStats['db_queries'],
            'cache_hits' => $this->performanceStats['cache_hits']
        ];
    }
    
    // 🔥 原有的formatBetInfo方法保持不变
    private function formatBetInfo($betInfo, $totalAmount)
    {
        $betType = [
            'big' => '大', 'small' => '小', 'odd' => '单', 'even' => '双',
            'big_odd' => '大单', 'big_even' => '大双', 'small_odd' => '小单', 'small_even' => '小双',
            'max' => '极大', 'min' => '极小', 'leopard' => '豹子', 'straight' => '顺子', 'pair' => '对子',
            '0' => '0', '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6',
            '7' => '7', '8' => '8', '9' => '9', '10' => '10', '11' => '11', '12' => '12',
            '13' => '13', '14' => '14', '15' => '15', '16' => '16', '17' => '17', '18' => '18',
            '19' => '19', '20' => '20', '21' => '21', '22' => '22', '23' => '23', '24' => '24',
            '25' => '25', '26' => '26', '27' => '27',
        ];

        $label = $betType[$betInfo] ?? '无';

        if (ctype_digit($betInfo)) {
            return $label . '点' . intval($totalAmount);
        }

        return $label . intval($totalAmount);
    }
    
    // 原有的rooms方法保持不变
    public function rooms()
    {
        $rooms = Db::name('game_room')
            ->where('status', 1)
            ->where('parent_id', 'in', [16, 17, 19])
            ->select();
        
        $wsMap = [
            1 => 'wss://wanmei888.cc:2999', 2 => 'wss://wanmei888.cc:2999',
            3 => 'wss://wanmei888.cc:2999', 5 => 'wss://wanmei888.cc:2999',
            6 => 'wss://wanmei888.cc:2999', 9 => 'wss://wanmei888.cc:2999',
            10 => 'wss://wanmei888.cc:2999',
        ];
        
        foreach ($rooms as &$room) {
            $room['ws_url'] = $wsMap[$room['id']] ?? 'wss://wanmei888.cc:2999';
        }

        return json(['data' => $rooms]);
    }
}