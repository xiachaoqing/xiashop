<?php

namespace app\forms;

use app\components\subscribe\OrderPayMessage;
use setting\models\Setting;
use users\models\User;
use Yii;

trait Notify
{
    public function paid($value)
    {
        if (isset($value['out_trade_no'])) {
            $pay_number = $value['out_trade_no'];
            $order_sn = substr($pay_number, 10);
            $pay_type = 'wechat';
        } else {
            $pay_number = '';
            $pay_type = '';
            $order_sn = $value;
        }

        $model = M('order', 'Order')::find()->where(['order_sn' => $order_sn])->one();

        if ($model && $model->status < 201) {
            $model->status = 201;
            $model->pay_number = $pay_number;
            $model->pay_type = $pay_type;
            $model->pay_time = time();

            Yii::info('判断插件是否安装' . $this->plugins("task", "status"));
            Yii::info('读取积分支付信息' . $model->total_score);
            Yii::info('读取积分支付总价' . $model->total_amount);
            Yii::info('读取积分支付订单' . $order_sn);
            Yii::info('读取积分支付用户' . $model->UID);

            //判断插件已经安装，则执行
            if ($this->plugins("task", "status")) {
                //判断是否积分订单
                if ($model->total_score > 0) {
                    //执行下单操作减积分操作
                    $this->plugins("task", [
                        "order",
                        [
                            $model->total_score,
                            $model->UID,
                            $order_sn,
                            "order",
                        ]
                    ]);
                }
                //执行下单操作
                $this->plugins("task", [
                    "score",
                    [
                        "goods",
                        $model->pay_amount,
                        $model->UID,
                        $order_sn,

                    ]
                ]);
                //执行下单操作
                $this->plugins("task", [
                    "score",
                    [
                        "order",
                        $model->total_amount,
                        $model->UID,
                        $order_sn,
                    ]
                ]);
            }

            if ($model->save()) {
                $this->module->event->user_statistical = [
                    'UID' => $model->UID,
                    'buy_number' => 1,
                    'buy_amount' => $model->pay_amount,
                    'last_buy_time' => time()
                ];
                $this->module->trigger('user_statistical');
                $this->module->event->pay_order_sn = $order_sn;
                $this->module->event->pay_uid = $model->UID;
                $this->module->trigger('pay_order');
                $name = '小店';
                $res = Setting::findOne(['AppID' => \Yii::$app->params['AppID'], 'keyword' => 'setting_collection']);
                if ($res) {
                    $info = to_array($res['content']);
                    $name = $info['store_setting']['name'] ?? '小店';
                }
                $user = User::findOne($model->UID);
                Yii::$app->user->login($user);
                $this->module->event->sms = [
                    'type' => 'order_pay',
                    'mobile' => Yii::$app->user->identity->mobile ?? '',
                    'params' => [
                        'name' => $name,
                    ],
                ];
                $this->module->trigger('send_sms');

                $setting = Setting::findOne(
                    [
                        'AppID' => Yii::$app->params['AppID'],
                        'merchant_id' => 1,
                        'keyword' => 'sms_setting',
                        'is_deleted' => 0
                    ]
                );
                if ($setting && $setting['content']) {
                    $mobiles = json_decode($setting['content'], true);
                    $this->module->event->sms = [
                        'type' => 'order_pay_business',
                        'mobile' => $mobiles['mobile_list'] ?? [],
                        'params' => [
                            'code' => substr($order_sn, -4),
                        ],
                    ];
                    $this->module->trigger('send_sms');
                }

                \Yii::$app->subscribe
                    ->setUser(\Yii::$app->user->id)
                    ->setPage('pages/order/detail?id=' . $model->id)
                    ->send(
                        new OrderPayMessage([
                            'amount' => $model->pay_amount,
                            'payTime' => date('Y年m月d日 H:i', time()),
                            'businessName' => $name,
                            'orderNo' => $model->order_sn,
                        ])
                    );

                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }
}
