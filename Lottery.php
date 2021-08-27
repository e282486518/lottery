<?php

namespace e282486518\lottery;

use Yii;
use yii\base\Component;
use yii\helpers\Json;

/**
 * Class Lottery
 * 抽奖概率算法
 * 使用方法如下：
 * ```php
 * $uid = 10;
 * $lottery = new Lottery();
 * $lottery->setName('cpse');
 * $lottery->setRate([]); // 基础中奖率设置
 * $lottery->setBaseline([]); // 保底配置
 * $item_id = $lottery->run($uid);
 * if ($item_id == -1)
 *      // 未中奖
 * $rel = ['uid'=>$uid, 'item_id'=>$item_id]
 * // 中奖信息写到数据库
 * ```
 *
 * @package common\components
 */
class Lottery extends Component {

    /**
     * 抽奖次数统计，hash格式，field为 $this->name，value为总抽奖次数(数字方便增减)
     */
    const REDIS_VISIT = 'cpsapp:lottery_visit';

    /**
     * 奖品库存，list格式，key=REDIS_SKU+name+奖品id，value为1，队列的长度为奖品个数
     */
    const REDIS_SKU = 'cpsapp:lottery_sku:';

    /**
     * 保底功能的上次中奖节点，hash格式，key=REDIS_BASELINE，field为保底配置key，value为上次中奖节点
     * [保底id=>上次中奖节点,保底id=>上次中奖节点]
     */
    const REDIS_BASELINE = 'cpsapp:lottery_baseline';

    /**
     * 作弊用户记录保存，hash格式，field为$this->name，value为已发放的作弊用户uid
     * [UID1,UID2,...]
     */
    const REDIS_CHEAT = 'cpsapp:lottery_cheat';

    /**
     * @var string 活动名称，不同名称的活动可互不干扰
     */
    public string $name = 'cpse';

    /**
     * @var float 基础中间概率(百分比，保留小数点后两位) 例如 0.85%
     */
    public float $BaseRate = 0.99;

    /** @var bool 是否开启计数器 */
    public bool $is_visit = true;

    /** @var bool 是否开启保底算法，保底算反需要 $is_visit=true 支持 */
    public bool $is_baseline = true;

    /**
     * @var bool 是否必须设置为true才能中奖。
     * 需求：不完成某些任务或达到某些条件就不允许中奖
     */
    public bool $must = true;

    /**
     * @var array 作弊中奖配置 [用户UID=>奖品ID,...]
     */
    public array $cheat = [];

    /**
     * @var array array 中奖概率(百分比，保留小数点后两位)，可以随时提高/降低中奖率
     *      例如 [1=>0.05,2=>0.85,3=>1.00] 或 [奖品id=>中奖概率]
     */
    public array $rate = [];

    /**
     * @var array 保底配置，靠前的奖优先选中
     *      [
     *          [
     *              prize=>[1=>20,2=>80], // prize 触发保底后，如果这里为多个奖品，其奖品的中奖概率的定义 [奖品ID=>中奖概率]
     *              limit=>400
     *          ],
     *          [prize=>[3=>100], limit=>1200],
     *          ...
     *      ]
     */
    public array $baseline = [];

    /**
     * ---------------------------------------
     * 构造方法
     * ---------------------------------------
     */
    public function init() {

    }

    /**
     * ---------------------------------------
     * 开始抽奖
     *
     * @return int|string
     *
     * @author hlf <phphome@qq.com> 2021/8/13
     * ---------------------------------------
     */
    public function run($uid = 0) {
        // 保底算法需要 $this->is_visit=true 支持
        if ($this->is_baseline == true) {
            $this->is_visit = true;
        }
        // 抽奖计数器
        $num = 0;
        if ($this->is_visit) {
            $num = $this->visit(); // 抽奖次数+1
        }
        // 初始化中奖的奖品ID，-1表示未中奖
        $item_id = -1;
        // 可以设置某些条件未达到,一定不会中奖
        if ($this->must) {
            // 作弊模型
            if ($item_id == -1) {
                $item_id = $this->cheatModel($uid);
            }
            // 固定概率模型
            if ($item_id == -1) {
                $item_id = $this->randArrModel($this->rate);
            }
            // 保底模型
            if ($item_id == -1) {
                $item_id = $this->baseModel($num);
            }
        }
        // 未中奖
        if ($item_id == -1) {
            return -1;
        }
        // 库存判断，并操作库存-1
        $sku = $this->sku($item_id);
        // 无库存，未中奖
        if ($sku == -1) {
            return -1;
        }
        // 到这一步可以正常发放奖励了，发放奖励条件：1、概率算法中已中奖；2、库存中有奖品
        $this->baseRecord($item_id, $num);
        // 返回奖品id
        return $item_id;
    }

    /**
     * ---------------------------------------
     * 设置name值
     *
     * @param $attr string
     *
     * @author hlf <phphome@qq.com> 2021/8/12
     * ---------------------------------------
     */
    public function setName(string $attr) {
        $this->name = $attr;
    }

    /**
     * ---------------------------------------
     * 设置基础中奖率
     *
     * @param array $attr
     *
     * @author hlf <phphome@qq.com> 2021/8/16
     * ---------------------------------------
     */
    public function setRate(array $attr) {
        foreach ($attr as $key => $value) {
            $this->rate[$key] = $value;
        }
    }

    /**
     * ---------------------------------------
     * 设置作弊配置
     *
     * @param array $attr
     *
     * @author hlf <phphome@qq.com> 2021/8/16
     * ---------------------------------------
     */
    public function setCheat(array $attr) {
        $this->cheat = $attr;
    }

    /**
     * ---------------------------------------
     * 设置保底配置
     *
     * @param array $attr
     *
     * @author hlf <phphome@qq.com> 2021/8/16
     * ---------------------------------------
     */
    public function setBaseline(array $attr) {
        $this->baseline = $attr;
    }

    /**
     * ---------------------------------------
     * 设置保底配置
     *
     * @param bool $attr
     *
     * @author hlf <phphome@qq.com> 2021/8/16
     * ---------------------------------------
     */
    public function setMust(bool $attr) {
        $this->must = $attr;
    }

    /**
     * ---------------------------------------
     * 抽奖计数器+1
     *
     * @return int
     *
     * @author hlf <phphome@qq.com> 2021/8/12
     * ---------------------------------------
     */
    public function visit($is_add = true) {
        if ($is_add) {
            $num = Yii::$app->redis->hincrby(self::REDIS_VISIT, $this->name, 1);
        } else {
            $num = Yii::$app->redis->hget(self::REDIS_VISIT, $this->name);
        }
        return $num > 0 ? $num : 0;
    }

    /**
     * ---------------------------------------
     * 奖品库存初始化
     *
     * @param array $itemArr 奖品库存 [奖品id=>库存数量, 'item_id'=>num]
     *
     * @author hlf <phphome@qq.com> 2021/8/13
     * ---------------------------------------
     */
    public function skuInit(array $itemArr) {
        // 初始化库存
        foreach ($itemArr as $item_id => $num) {
            $rediskey = self::REDIS_SKU . $this->name . ':' . $item_id;
            for ($i = 0; $i < $num; $i++) {
                Yii::$app->redis->lpush($rediskey, 1);
            }
        }
        // 初始化抽奖数
        Yii::$app->redis->hset(self::REDIS_VISIT, $this->name, 0);
        // 初始化保底节点
        Yii::$app->redis->hdel(self::REDIS_BASELINE, $this->name);
    }

    /**
     * ---------------------------------------
     * 奖品库存减少1，如果返回-1表示库存为0，需重新判断是否中奖
     * 这使用队列解决超卖问题，采用redis的原子性就能避免超卖问题
     *
     * @param int $item_id 奖品id
     * @return int|mixed
     *
     * @author hlf <phphome@qq.com> 2021/8/13
     * ---------------------------------------
     */
    public function sku(int $item_id) {
        $rediskey = self::REDIS_SKU . $this->name . ':' . $item_id;
        if (Yii::$app->redis->lpop($rediskey)) {
            return Yii::$app->redis->llen($rediskey); // 减少后的库存
        }
        return -1; // 无库存
    }

    /**
     * ---------------------------------------
     * 固定概率算法，单个奖品
     * 抽奖一次，且由中奖率判断是否中奖
     *
     * @param $rate float 中奖概率(百分比，保留小数点后两位) 例如 0.85%
     * @return bool
     *
     * @author hlf <phphome@qq.com> 2021/8/12
     * ---------------------------------------
     */
    public function randOneModel(float $rate = 0, int $max = 10000) {
        $max = $max > 10000 ? $max : 10000;
        // 初始化中奖率
        if (empty($rate)) {
            $rate = $this->BaseRate;
        }
        // 随机数
        $num = mt_rand(1, $max);
        if ($num <= ($max * $rate / 100)) {
            return true; // 中奖
        }
        return false; // 未中奖
    }

    /**
     * ---------------------------------------
     * 固定概率算法，多个奖品
     * 抽奖一次，返回中奖物品id，其中未中奖id=-1
     * @param $rate array 中奖概率(百分比，保留小数点后两位)
     *      例如 [0.05,0.85,1] 或 [奖品id=>中奖概率]
     *
     * @return int|string
     *
     * @author hlf <phphome@qq.com> 2021/8/13
     * ---------------------------------------
     */
    public function randArrModel(array $rate = [], int $max = 100000) {
        $max = $max > 10000 ? $max : 10000;
        // 初始化中奖率
        if (empty($rate)) {
            $rate = $this->rate;
        }
        // 转化为[5,90,190]
        $_tmp = 0;
        foreach ($rate as &$value) {
            $value = $max * $value / 100;
            $value += $_tmp;
            $_tmp  = $value;
        }
        // 随机数
        $num = mt_rand(1, $max);
        // 随机数落在哪个区间就表示哪个中奖了
        foreach ($rate as $key => $val) {
            if ($num <= $val) return $key;
        }
        // 未中奖
        return -1;
    }

    /**
     * ---------------------------------------
     * 保底概率模型
     *
     * @param int $num
     * @return int|string
     *
     * @author hlf <phphome@qq.com> 2021/8/14
     * ---------------------------------------
     */
    public function baseModel(int $num) {
        if ($this->is_baseline && !empty($this->baseline)) {
            // 保底模型
            $base_id = $this->baseline($num);
            if ($base_id == -1) return -1; // 未触发保底模型
            $item_id = $this->baselineTrigger($base_id);
            if ($item_id == -1) return -1; // 保底中也未中奖
            return $item_id;
        } else {
            return -1; // 未开启保底模型
        }
    }

    /**
     * ---------------------------------------
     * 触发保底后，记录保底节点信息
     *
     * @param int $item_id 奖品ID
     * @param int $num 当前抽奖数
     *
     * @author hlf <phphome@qq.com> 2021/8/14
     * ---------------------------------------
     */
    public function baseRecord(int $item_id, int $num) {
        // 将当前中奖节点记录到redis中
        if ($this->is_baseline) {
            $base_id = $this->itemToBase($item_id);
            if ($base_id != -1) {
                $this->setLast($base_id, $num);
            }
        }
    }

    /**
     * ---------------------------------------
     * 抽奖保底算法
     * redis储存上次中奖的次数格式为[1=>2655, 2=>9856]
     *
     * @param int $num 当前抽奖数目
     *
     * @author hlf <phphome@qq.com> 2021/8/12
     * ---------------------------------------
     */
    public function baseline(int $num) {
        if (!$this->is_baseline || empty($this->baseline)) {
            return -1; // 未配置保底
        }
        // 获取上次中奖的节点
        $arrNum = $this->getLast();
        // 计算保底，保底配置数组越靠前越优先
        foreach ($this->baseline as $base_id => $item) {
            if ($num - $arrNum[$base_id] >= $item['limit']) {
                return $base_id;
            }
        }
        // 未达到保底要求
        return -1;
    }

    /**
     * ---------------------------------------
     * 抽奖保底算法中，触发保底后，从保底配置中返回保底奖品id
     *
     * @param int $base_id 保底配置的key
     * @return bool|int|string|null
     *
     * @author hlf <phphome@qq.com> 2021/8/13
     * ---------------------------------------
     */
    public function baselineTrigger(int $base_id) {
        if (!$this->is_baseline || empty($this->baseline)) {
            return -1; // 未配置保底
        }
        if (!isset($this->baseline[$base_id]['prize'])) {
            return -1;
        }
        $prize = $this->baseline[$base_id]['prize'];
        // 只有一个成员时，直接返回
        if (count($prize) == 1) {
            return $prize[0] ?? key($prize);
        }
        // 多个成员时，根据其配置的几率返回
        return $this->randArrModel($prize);
    }

    /**
     * ---------------------------------------
     * 保底算法中，最后一次中奖的节点
     *
     * @param int $base_id
     * @param int $num
     * @return bool
     *
     * @author hlf <phphome@qq.com> 2021/8/13
     * ---------------------------------------
     */
    public function setLast(int $base_id, int $num) {
        $arrNum = $this->getLast();
        if ($base_id >= 0 && $num >= 0) {
            $arrNum[$base_id] = $num;
        }
        // 写入redis
        Yii::$app->redis->hset(self::REDIS_BASELINE, $this->name, Json::encode($arrNum));
        return true;
    }

    /**
     * ---------------------------------------
     * 获取 保底算法中，最后一次中奖的节点
     *
     * @return array
     *
     * @author hlf <phphome@qq.com> 2021/8/13
     * ---------------------------------------
     */
    public function getLast() {
        $arrNum = Yii::$app->redis->hget(self::REDIS_BASELINE, $this->name);
        if (empty($arrNum)) {
            // 初始化数据
            foreach ($this->baseline as $base_id => $item) {
                $arrNum[$base_id] = 0;
            }
            // 写入空数据到redis
            Yii::$app->redis->hset(self::REDIS_BASELINE, $this->name, Json::encode($arrNum));
            return $arrNum;
        }
        return Json::decode($arrNum);
    }

    /**
     * ---------------------------------------
     * 保底配置中，奖品ID(item_id)转化为 保底ID(base_id)
     *
     * @param int $item_id
     * @return int|string
     *
     * @author hlf <phphome@qq.com> 2021/8/13
     * ---------------------------------------
     */
    public function itemToBase(int $item_id) {
        if (!$this->is_baseline || empty($this->baseline)) {
            return -1; // 未配置保底
        }
        foreach ($this->baseline as $base_id => $item) {
            $prize = $item['prize'];
            if (in_array($item_id, array_keys($prize))) {
                return $base_id;
            }
        }
        return -1; // 在保底中未匹配到奖品id，就表示此奖品未做保底
    }

    /**
     * ---------------------------------------
     * 作弊模型
     *
     * @param int $uid
     * @return int 奖品ID
     *
     * @author hlf <phphome@qq.com> 2021/8/14
     * ---------------------------------------
     */
    public function cheatModel(int $uid) {
        if (empty($this->cheat) || $uid <= 0) {
            return -1;
        }
        // 判断是否已中过奖
        $uids = Yii::$app->redis->hget(self::REDIS_CHEAT, $this->name);
        if (!empty($uids)) {
            $uids = Json::decode($uids);
            if (in_array($uid, $uids)) {
                return -1;
            }
        } else {
            $uids = [];
        }
        // 判断是否命中作弊配置
        if (in_array($uid, array_keys($this->cheat))) {
            // 写入redis作弊中奖几率
            array_push($uids, $uid);
            Yii::$app->redis->hset(self::REDIS_CHEAT, $this->name, Json::encode($uids));
            return $this->cheat[$uid];
        }
        return -1;
    }

}