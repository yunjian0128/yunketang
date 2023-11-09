<?php

namespace app\home\controller;

use app\common\controller\Home;

// 引入FastAdmin自带的一个邮箱发送类
use app\common\library\Email;

class Business extends Home
{
    public function __construct() // 构造方法
    {
        parent::__construct();

        //公共区域加载模型
        $this->BusinessModel = model('Business.Business');
        $this->OrderModel = model('Subject.Order');
        $this->RecordModel = model('Business.Record');
        $this->CommentModel = model('Subject.Comment');
        $this->SubjectModel = model('Subject.Subject');
    }

    //会员首页
    public function index()
    {
        //给一个模板渲染
        return $this->view->fetch();
    }

    public function profile()
    {
        // 判断是否是post提交
        if ($this->request->isPost()) {
            // 接收数据
            $nickname = $this->request->param('nickname', '', 'trim');
            $mobile = $this->request->param('mobile', '', 'trim');
            $email = $this->request->param('email', '', 'trim');
            $gender = $this->request->param('gender', '0', 'trim');
            $code = $this->request->param('code', '', 'trim');
            $password = $this->request->param('password', '', 'trim');

            // 组装数据
            $data = [
                'id' => $this->view->business['id'],
                // 会员id
                'nickname' => $nickname,
                'mobile' => $mobile,
                'gender' => $gender,

            ];

            // 如果密码不为空，就修改密码，生成密码盐，生成新密码
            if (!empty($password)) {
                // 生成密码盐
                $salt = randstr();
                $data['salt'] = $salt;

                // 生成新密码
                $data['password'] = md5($password . $salt);
            }

            //判断是否修改了邮箱 输入的邮箱 不等于 数据库存入的邮箱
            //如果邮箱改变，需要重新认证
            if ($email != $this->view->business['email']) {
                $data['email'] = $email;
                $data['auth'] = '0';
            }

            // 判断是否有地区数据
            if (!empty($code)) {
                $parent = model('Region')->where(['code' => $code])->value('parentpath');

                if (!empty($parent)) {
                    $parent = explode(',', $parent);
                    $data['province'] = isset($parent[0]) ? $parent[0] : '';
                    $data['city'] = isset($parent[1]) ? $parent[1] : '';
                    $data['district'] = isset($parent[2]) ? $parent[2] : '';
                }
            }

            //判断是否有图片上传
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0) {
                $success = build_upload('avatar');

                //如果上传失败，就提醒
                if (!$success['result']) {
                    $this->error($success['msg']);
                    exit;
                }

                //如果上传成功
                $data['avatar'] = $success['data'];
            }

            // var_dump($data);
            // exit;

            //更新语句 如果是更新语句，需要给data提供一个主键id的值 这就是更新语句 使用验证器的场景
            $result = $this->BusinessModel->validate('common/Business/Business.profile')->isUpdate(true)->save($data);

            if ($result === FALSE) {
                $this->error($this->BusinessModel->getError());
                exit;
            }

            //判断是否有旧图片，如果有就删除
            if (isset($data['avatar'])) {
                is_file("." . $this->view->business['avatar']) && @unlink("." . $this->view->business['avatar']);
            }

            //修改了手机号，覆盖一下cookie
            if ($data['mobile'] != $this->view->business['mobile']) {
                $cookie = [
                    'id' => $data['id'],
                    'mobile' => $data['mobile']
                ];

                //覆盖cookie
                cookie('business', $cookie);
            }
            $this->success('更新资料成功', url('home/business/index'));
            exit;
        }
        return $this->view->fetch();
    }

    // 邮箱验证
    public function email()
    {
        //加载模型
        $EmsModel = model('common/Ems');

        //是否有ajax请求过来
        if ($this->request->isAjax()) {
            $action = $this->request->param('action', '', 'trim');

            if ($action == "send") {
                //获取用户信息
                $email = isset($this->view->business['email']) ? $this->view->business['email'] : '';

                if (empty($email)) {
                    $this->error('邮箱地址为空');
                    exit;
                }

                //生成一个验证码
                $code = randstr(5);

                //开启事务
                $EmsModel->startTrans();

                //删除掉之前旧的验证码
                $EmsModel->where(['email' => $email])->delete();

                //把验证码插入到数据库表中
                $data = [
                    'event' => 'auth',
                    'email' => $email,
                    'code' => $code,
                    'times' => 0,
                ];

                //插入数据
                $ems = $EmsModel->save($data);

                if ($ems === FALSE) {
                    $this->error('邮件插入失败');
                    exit;
                }

                //邮件主题
                $name = config('site.name');
                $subject = "【{$name}】邮箱验证";

                //组装文字信息
                $message = "<div>感谢您的使用，您的邮箱验证码为：<b>$code</b></div>";

                //实例化邮箱验证类
                $PhpMailer = new Email;

                //邮箱发送有规律，不可以发送关键词
                $result = $PhpMailer
                    ->to($email)
                    ->subject($subject)
                    ->message($message)
                    ->send();

                //检测邮箱发送成功还是失败
                if ($result) {
                    //发送验证码成功
                    //将事务提交，提交的意思就是让刚刚插入的记录真实存在到数据表中
                    $EmsModel->commit();
                    $this->success('邮件发送成功，请注意查收');
                    exit;
                } else {
                    //将刚才插入成功的验证码记录要撤销回滚
                    $EmsModel->rollback();
                    $this->error($PhpMailer->getError());
                    exit;
                }
            }
        }

        //是否有post请求过来
        if ($this->request->isPost()) {
            $code = $this->request->param('code', '', 'trim');

            if (empty($code)) {
                $this->error('验证码不能为空');
                exit;
            }

            $email = isset($this->view->business['email']) ? trim($this->view->business['email']) : '';

            if (empty($email)) {
                $this->error('邮箱不能为空');
                exit;
            }

            //开启事务
            $EmsModel->startTrans();
            $this->BusinessModel->startTrans();

            //查询这个验证码是否存在
            $where = ['email' => $email, 'code' => $code];
            $check = $EmsModel->where($where)->find();

            //如果没找到记录
            if (!$check) {
                $this->error('您输入的验证码有误，请重新输入');
                exit;
            }

            // 1、更新用户表  2、删除验证码记录

            //组装数据
            $data = [
                'id' => $this->view->business['id'],
                'auth' => '1'
            ];

            //执行
            $BusinessStatus = $this->BusinessModel->isUpdate(true)->save($data);

            if ($BusinessStatus === FALSE) {
                $this->error('用户邮箱认证状态修改失败');
                exit;
            }

            //第二条 删除验证码记录
            $EmsStatus = $EmsModel->where($where)->delete();

            if ($EmsStatus === FALSE) {
                //先要将用户表的更新进行回滚
                $this->BusinessModel->rollback();
                $this->error('验证码记录删除失败');
                exit;
            }

            if ($BusinessStatus === FALSE || $EmsStatus === FALSE) {
                $EmsModel->rollback();
                $this->BusinessModel->rollback();
                $this->error('验证失败');
                exit;
            } else {
                //提交事务
                $this->BusinessModel->commit();
                $EmsModel->commit();
                $this->success('邮箱验证成功', url('home/business/index'));
                exit;
            }
        }
        return $this->view->fetch();
    }

    // 用户订单
    public function order()
    {
        // 判断是否是ajax请求
        if ($this->request->isAjax()) {
            $busid = $this->request->param('busid', '', 'trim');
            $limit = $this->request->param('limit', 10, 'trim');
            $page = $this->request->param('page', 1, 'trim');

            $where = ['busid' => $busid];

            // 查询用户订单数量
            $count = $this->OrderModel->where($where)->count();

            // 偏移量
            $start = ($page - 1) * $limit;

            // 查询用户订单
            $list = $this->OrderModel
                ->with('subject')
                ->where($where)
                ->order('createtime desc')
                ->limit($start, $limit)
                ->select();

            // 组装数据
            $data = [
                'count' => $count,
                'list' => $list
            ];

            if (empty($list)) {
                $this->error('暂无更多数据');
                exit;
            } else {
                $this->success('成功返回数据', null, $data);
                exit;
            }
        }
        return $this->view->fetch();
    }

    // 用户消费
    public function record()
    {
        // 获取用户id
        $busid = $this->view->business['id'];

        // 返回用户id
        $this->assign('busid', $busid);

        // 判断是否是ajax请求
        if ($this->request->isAjax()) {
            $busid = $this->request->param('busid', '', 'trim');
            $limit = $this->request->param('limit', 10, 'trim');
            $page = $this->request->param('page', 1, 'trim');

            $where = ['busid' => $busid];

            // 查询用户订单数量
            $count = $this->RecordModel->where($where)->count();

            // 偏移量
            $start = ($page - 1) * $limit;

            // 查询用户订单
            $list = $this->RecordModel
                ->where($where)
                ->order('createtime desc')
                ->limit($start, $limit)
                ->select();

            // 组装数据
            $data = [
                'count' => $count,
                'list' => $list
            ];

            if (empty($list)) {
                $this->error('暂无更多数据');
                exit;
            } else {
                $this->success('成功返回数据', null, $data);
                exit;
            }
        }
        return $this->view->fetch();
    }

    // 用户充值
    public function recharge()
    {
        // 获取用户id
        $busid = $this->view->business['id'];

        // 查询用户余额
        $business = $this->BusinessModel->where(['id' => $busid])->find();
        $money = $business->money;

        // 传出数据
        $this->assign('money', $money);
        $this->assign('busid', $busid);

        // 判断是否是ajax请求
        if ($this->request->isPost()) {
            $recharge = $this->request->param('money', 0, 'trim');

            // 如果recharge为0
            if ($recharge <= 0) {
                $this->error('充值金额不能小于0');
                exit;
            }

            if ($money <= 0) {
                $this->error('充值失败');
                exit;
            }

            $money += $recharge;

            // 开启事务
            $this->BusinessModel->startTrans();
            $RechargeStatus = $this->BusinessModel->isUpdate(true)->save([
                'id' => $busid,
                'money' => $money
            ]);

            if ($RechargeStatus) {
                $this->BusinessModel->commit();
                $this->success('充值成功');
                exit;
            } else {
                // 回滚事务
                $this->BusinessModel->rollback();
                $this->error('充值失败');
                exit;
            }
        }
        return $this->view->fetch();
    }

    // 联系我们
    public function contact()
    {
        // 获取当前IP
        $data = GetClientIP();

        // 传出数据
        $this->assign('data', $data);

        return $this->view->fetch();
    }

    // 用户评价
    public function comment()
    {
        // 获取用户id和课程id
        $busid = $this->view->business['id'];
        $subid = $this->request->param('subid', 0, 'trim');

        $where = [
            'busid' => $busid,
            'subid' => $subid
        ];

        // 查询用户评价
        $comments = $this->CommentModel->where($where)->find();
        $content = isset($comments->content) ? $comments->content : '';

        // 查询课程信息
        $subject = $this->SubjectModel->where(['id' => $subid])->find();

        // 传出数据
        $this->assign([
            'content' => $content,
            'subject' => $subject
        ]);

        // 判断是否是post请求
        if ($this->request->isPost()) {
            $content = $this->request->param('content', '', 'trim');

            // 如果内容为空
            if (empty($content)) {
                $this->error('评论内容不能为空');
                exit;
            }

            // 组装数据
            $data = [
                'busid' => $busid,
                'subid' => $subid,
                'content' => $content
            ];

            // 开启事务
            $this->CommentModel->startTrans();

            // 如果评价存在就更新评论
            if (!empty($comments)) {

                // 更新数据
                $result = $this->CommentModel->isUpdate(true)->save([
                    'content' => $content
                ], [
                    'busid' => $busid,
                    'subid' => $subid
                ]);

                // 如果更新失败
                if ($result === FALSE) {
                    $this->CommentModel->rollback();
                    $this->error('更新评论失败');
                    exit;
                }

                // 提交事务
                $this->CommentModel->commit();
                $this->success('更新评论成功', url('home/index/more', ['subid' => $subid]));
                exit;
            }

            // 插入数据
            $result = $this->CommentModel->save($data);

            // 如果插入失败
            if ($result === FALSE) {

                // 回滚事务
                $this->CommentModel->rollback();
                $this->error('评论失败');
                exit;
            }

            // 提交事务
            $this->CommentModel->commit();
            $this->success('评论成功', url('home/index/more', ['subid' => $subid]));
            exit;
        }
        return $this->view->fetch();
    }
}