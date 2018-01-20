<?php

namespace frontend\controllers;

use common\config\Conf;
use common\models\User;
use common\services\UserService;
use common\utils\ResponseUtil;
use common\utils\VerifyUtil;
use frontend\form\LoginForm;
use frontend\form\SignupForm;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\ForbiddenHttpException;

/**
 * UserController implements the CRUD actions for User model.
 */
class UserController extends BaseController
{
    public $layout = 'main-member';

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'only'  => [
                    'logout',
                    'signup',
                    'login'
                ],
                'rules' => [
                    [
                        'actions' => ['signup', 'login'],
                        'allow'   => true,
                        'roles'   => ['?'],
                    ],
                    [
                        'actions' => ['logout'],
                        'allow'   => true,
                        'roles'   => ['@'],
                    ],
                ],
            ],
            'verbs'  => [
                'class'   => VerbFilter::className(),
                'actions' => [
                    'logout' => ['post'],
                ],
            ],
        ];
    }

    /**
     * 登录页
     * @return string
     * @since 2018-01-14
     */
    public function actionLogin()
    {
        $this->layout = 'main-login';

        if (!\Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        $model = new LoginForm();
        if ($model->load(Yii::$app->request->post())) {
            $res = $model->login();
            if ($res === 1) {
                return $this->redirect(['default/index']);
            } else {
                $this->redirectMsgBox(['user/login'], Yii::$app->params['loginMsg'][$res]);
            }
        } else {
            return $this->render('login', [
                'model' => $model,
            ]);
        }

    }

    /**
     * 注册
     * @return mixed
     * @since 2018-01-16
     */
    public function actionSignup()
    {
        $this->layout = 'main-login';

        $model = new SignupForm();
        if ($model->load(Yii::$app->request->post())) {
            if ($user = $model->signup()) {
                if ($model->login()) {
                    $this->redirectMsgBox(['default/index'], '亲爱的' . $user->fdName . '，欢迎你使用Team');
                } else {
                    $this->redirectMsgBox(['user/login'], '注册成功，请登录...');
                }
            } else {
                $this->redirectMsgBox(['user/signup'], '注册失败了...');
            }
        }

        return $this->render('signup', [
            'model' => $model,
        ]);
    }

    /**
     * 退出登录
     * @return mixed
     * @since 2016-02-28
     */
    public function actionLogout()
    {
        Yii::$app->user->logout();
        return $this->redirect(['/user/login']);
    }

    /**
     * 普通成员认证
     * @since 2018-01-18
     */
    public function actionAuth()
    {
        $auth = Yii::$app->request->get('auth');
        $email = Yii::$app->request->get('email');

        if (!$auth || !$email) {
            $this->redirectMsgBox(['user/login'], '链接无效');
        }

        $user = UserService::factory()->getUserObjByAccount($email);
        if (!$user) {
            $this->redirectMsgBox(['user/login'], '链接无效');
        }

        if ($auth != Yii::$app->security->generatePasswordHash($user->fdLogin)) {
            $this->redirectMsgBox(['user/login'], '链接无效');
        }

        $user->fdStatus = Conf::USER_ENABLE;
        $user->fdVerify = date('Y-m-d H:i:s');
        $res = $user->update();

        if (!$res) {
            $this->redirectMsgBox(['user/login'], '认证失败');
        }

        // 验证成功，执行登录
        if (UserService::factory()->login($user)) {
            $this->redirectMsgBox(['default/index'], $user->fdName . ' 欢迎你...');
        } else {
            $this->redirectMsgBox(['user/login'], '验证成功，请登录...');
        }
    }

    /**
     * 导入成员
     * @return string
     * @throws ForbiddenHttpException
     */
    public function actionImport()
    {
        // 权限检测，只有超级管理员和普通管理员才能执行
        if (!Yii::$app->user->can('importUser')) {
            throw new ForbiddenHttpException(Yii::t('yii', 'You are not allowed to perform this action.'));
        }

        if (Yii::$app->request->isAjax && ($data = Yii::$app->request->post())) {
            if (!$data['accounts']) {
                ResponseUtil::jsonCORS(['status' => 0, 'msg' => '账号不能为空']);
            }

            $exists = []; // 已存在的账号
            $newAccounts = []; // 新账号
            $accounts = array_unique(array_filter(explode(',', $data['accounts'])));

            foreach ($accounts as $account) {
                // 账号已经存在
                if (UserService::factory()->getUserObjByAccount($account)) {
                    $exists[] = $account;
                    continue;
                }

                $temp = [
                    'name'  => '',
                    'phone' => '',
                    'email' => '',
                    'login' => 't_' . VerifyUtil::getRandomCode(8, 3)
                ];

                if (VerifyUtil::checkPhone($account)) {
                    $temp['phone'] = $account;
                    $temp['name'] = $account;
                } elseif (VerifyUtil::checkEmail($account)) {
                    $temp['email'] = $account;
                    $temp['name'] = $account;
                } else {
                    $temp['login'] = $account;
                    $temp['name'] = $account;
                }

                $newAccounts[] = $temp;
            }

            // 成员入库
            if ($newAccounts) {
                UserService::factory()->batchCreateUser($this->companyID, $newAccounts);
                ResponseUtil::jsonCORS([
                    'status' => 0,
                    'exists' => $exists
                ]);
            }

            ResponseUtil::jsonCORS([
                'status' => 1,
                'exists' => $exists
            ]);
        }

        return $this->render('import-user');
    }
}
