抽奖
==
抽奖，随机模型 保底模型 作弊

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist e282486518/lottery "*"
```

or add

```
"e282486518/lottery": "*"
```

to the require section of your `composer.json` file.


Usage
-----

Once the extension is installed, simply use it in your code by  :

```php
    /**
     * 抽奖接口
     *
     * 奖品设置：[1=>汽车, 2=>手机, 3=>平板, 4=>手表, 5=>话费]
     *
     * @return array
     *
     * @author hlf <phphome@qq.com> 2021/8/16
     */
    public function actionRun() {
        $uid = 21;
        if ($uid <= 0) {
            return $this->ret(1, '请登录后再抽奖!');
        }
        $lottery = new Lottery();
        if (date('Ymd') == '20210817') {
            // 第一天的抽奖设置
            $lottery->setName('20210817');
            if ($lottery->visit(false) == 0) {
                // 第一次访问，库存配置
                $lottery->skuInit([
                    1 => 1,
                    2 => 3,
                    3 => 5,
                    4 => 50,
                    5 => 200
                ]);
            }
        } elseif (date('Ymd') == '20210818') {
            // 第一天的抽奖设置
            $lottery->setName('20210818');
            if ($lottery->visit(false) == 0) {
                // 第一次访问，库存配置
                $lottery->skuInit([
                    1 => 0,
                    2 => 1,
                    3 => 2,
                    4 => 20,
                    5 => 80
                ]);
            }
        } elseif (date('Ymd') == '20210819') {
            // 第一天的抽奖设置
            $lottery->setName('20210819');
            if ($lottery->visit(false) == 0) {
                // 第一次访问，库存配置
                $lottery->skuInit([
                    1 => 1,
                    2 => 1,
                    3 => 2,
                    4 => 20,
                    5 => 50
                ]);
            }
        } else {
            return $this->ret(1, '不在抽奖时间范围内~ ');
        }
        // 基础中奖率设置
        $lottery->setRate([
            1 => 0,
            2 => 0.03,
            3 => 0.05,
            4 => 0.05,
            5 => 0.9
        ]);
        // 保底设置
        $lottery->setBaseline([
            ['prize' => [4 => 20, 5 => 80], 'limit' => 500],
            ['prize' => [2 => 30, 3 => 70], 'limit' => 14000],
        ]);
        // 作弊设置，[用户UID=>奖品ID]
        $lottery->setCheat([
            21 => 1,
        ]);
        // 特殊条件触发：完成了某些任务/xx时间之后 才能中大奖
        $task = true;
        if (date('YmdH') > 2021081611 && $task) {
            $lottery->setRate([
                1 => 0.1,
            ]);
        }
        // 抽奖
        $item_id = $lottery->run();
        // 写入到文件日志

        // 中奖的人，写入到中奖记录
        if ($item_id >= 0) {
            $data = [
                'uid'     => $uid,
                'item_id' => $item_id,
                'ip'      => Yii::$app->request->getUserIP(),
                'ctime'   => time(),
            ];

        }
        // 积分减少，积分消费记录


        return $this->ret(0, 'success', $item_id);
    }
```