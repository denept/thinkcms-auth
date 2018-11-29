# thinkphp5.1 权限认证 RBAC

## 安装
~~~
> composer require denept/thinkcms-auth
~~~
## v1.0.0
## 配置 1.0.0
~~~
'thinkcms' =>[
        'style_directory' => '/static/admin/',
        'session_prefix'  => 'auth_',
  ]
~~~

可以不配置  配置以后Js css文件需要放到配置的目录里

## 手动加入日志  1.0.0
~~~
    $auth = new Auth();
    $auth->admin = $list['user_name'];
    $auth->createLog('管理员<spen style=\'color: #1dd2af;\'>[ {name} ]</spen>偷偷的进入后台了,','后台登录');
~~~

## 视图调用
~~~
     public function _empty($name)
        {
            $auth =  new \thinkcms\auth\Auth();
            $auth = $auth->autoload($name);
            if($auth){
                if(isset($auth['code'])){
                    return json($auth);
                }elseif(isset($auth['file'])){
                    return $auth['file'];
                }
                $this->view->engine->layout(false);
                return $this->fetch($auth[0],$auth[1]);
            }
            return abort(404,'页面不存在');
        }
~~~
在模块中创建一个Auth控制器，把_empty方法复制上去，这样就可以访问以下视图

* /auth/role.html           角色列表
* /auth/roleAdd.html        角色添加
* /auth/roleEdit.html       角色修改
* /auth/authorize/id/2.html 权限设置
* /auth/menu.html           菜单列表
* /auth/menuAdd.html        菜单增加
* /auth/menuEdit.html       菜单修改
* /auth/log.html            行为日志
* /auth/viewLog.html        查看日志
* /auth/clear.html          清空日志
* /auth/adminAuthorize.html 独立权限

## 权限认证
~~~
     public function __construct()
        {
            parent::__construct();
            $auth                   = new Auth();
            $auth->noNeedCheckRules = ['index/index/index','index/index/home'];
            $auth->log              = true;                 // 日志开关默认true
            $user                   = $auth::is_login();

            if($user){//用户登录状态
                $this->uid = $user['uid'];
                if(!$auth->auth()){
                    return $this->error("你没有权限访问！");
                }
            }else{
                return $this->error("您还没有登录！",url("publics/login"));
            }
        }
~~~
这里在公共控制器上加入验证即可

##管理员独立权限
~~~
 url('auth/adminAuthorize',['id' => '用户ID','name'=>'用户昵称'])
~~~
## 授权菜单
~~~
 Auth::menuCheck();
~~~
这个方法返回授权及非隐藏的所有菜单，这样我们后台的菜单就可以根据管理员的权限来来展示授权的目录 


## mysql文件
~~~
dbo_action_log.sql
dbo_auth_access.sql
dbo_auth_role.sql
dbo_auth_role_user.sql
dbo_auth_rule.sql
dbo_menu.sql
~~~

