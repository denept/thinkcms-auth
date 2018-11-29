<?php
// +----------------------------------------------------------------------
// | [ Only to facilitate the creation of it]
// +----------------------------------------------------------------------
// | Personal development
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: denept <denept@talknept.com>
// +----------------------------------------------------------------------
namespace thinkcms\auth;

defined('VIEW_PATH') or define('VIEW_PATH', __DIR__ . DIRECTORY_SEPARATOR.'view'. DIRECTORY_SEPARATOR);

use think\facade\Cache;

use think\facade\Config;
use think\Loader;
use think\facade\Request;
use think\facade\Session;
use thinkcms\auth\controller\Rbac;
use thinkcms\auth\model\ActionLog;
use thinkcms\auth\model\AuthAccess;
use thinkcms\auth\model\AuthRoleUser;
use thinkcms\auth\model\Menu;

class Auth
{
    const  PATH                 = __DIR__;
    public $log                 = true;
    public $noNeedCheckRules    = [];           //不需要检查的路由规则

    public function __construct()
    {
        $this->param        = Request::param();
        $this->module       = Request::module();
        $this->controller   = Request::controller();
        $this->action       = Request::action();

    }

    /**
     * 加载控制器方法
     * @access public
     * @param  string  $name 方法名
     * @return mixed
     */
    public function autoload($name){

        $controller = new Rbac($this->request);

        if(strtolower($this->controller) == 'auth' && method_exists($controller,$name)){
          return  call_user_func([$controller, $name]);
        }

       return false;
    }

    /**
     * 权限认证
     * @access public
     * @return mixed
     */
    public function auth(){
        $uid                = self::sessionGet('user.id');
        $controller         = Loader::parseName($this->controller,1); //字符串命名风格转换
        //大写字母转_小写
        $controller         = strtolower(trim(preg_replace("/[A-Z]/", "_\\0", $this->controller), "_"));
        $rule               = strtolower("{$this->module}/{$controller}/{$this->action}");
        //如果用户角色是1，则无需判断
        if(empty($uid)){
            return false;
        }
        if($uid == 1){
            self::actionLog($rule);
            return true;
        }
        //无需认证
        $noNeedCheckRules   = array_merge($this->noNeedCheckRules,[$this->module.'/auth/openfile',$this->module.'/auth/cache']);
        if( !in_array($rule,$noNeedCheckRules) ){
            return self::authCheck($rule,'or');
        }else{
            return true;
        }

    }

    /**
     * 菜单权限检查
     * @access public
     * @return array
     */
    public static function menuCheck(){

        $uid = self::sessionGet('user.id');

        if(empty($uid)){
            return false;
        }
        $where['status'] = 1;

        if($uid != 1){
            $authMenu        = self::authMenu('',false);

            if(is_array($authMenu)){ //授权菜单ID
               $where['id']=['in',array_keys($authMenu)];
            }
        }

        $menu       = Menu::where($where)->order(["sort" => "asc",'id'=>'asc'])->column('*','id');

        return $menu;
    }


    /**
     * 行为日志检查
     * @access public
     * @param  string          $rule        日志规则
     * @return array
     */
    private function actionLog($rule){

        //是否需要打开 行为日志检查
        if($this->log === false){
            return true;
        }

        $logMenu    = Cache::get('logMenu');
        if(empty($logMenu)){    //缓存日志24小时
            $logMenu    = Menu::actionLogMenu();
            Cache::set('logMenu',$logMenu,86400);
        }

        $menu       =   isset($logMenu[$rule])?$logMenu[$rule]:'';

        $log        = [];
        if(empty($menu)){
            return true;
        }

        //子集行为日志菜单匹配
        if(isset($menu['child'])){
           foreach($menu['child'] as $v){
             if(!empty($v['rule_param'])){
                 $condition = '';
                 $command   = preg_replace('/\{(\w*?)\}/', '$this->param[\'\\1\']', $v['rule_param']);
                 @(eval('$condition=(' . $command . ');'));
                 if ($condition and $v['request'] == $this->request->method()) {
                     $log = $v;
                 }
             }
           }
        }

        //父集行为日志菜单匹配
        if(empty($log)){
            if($menu['request'] == $this->request->method()){
                $log    = $menu;
            }
        }

        if(!empty($log)){
            return self::createLog($log['log_rule'],$log['name']);
        }

        return true;
    }


    /**
     * 创建行为日志
     * @param  string       $logrule    行为日志规则
     * @param  string       $title      标题
     * @param  int          $uid        执行者ID
     * @return array
     */
    public function  createLog($logrule,$title){
        $uid    = self::sessionGet('user.id');
        $param  = $this->param;
        $condition = '';
        $command   = preg_replace('/\{(\w*?)\}/', '{$param[\'\\1\']}', $logrule);
        @(eval('$condition= (string)("' . $command . '");'));
        //dump($condition);die;
        $data   = [
            'action_ip'     => ip2long($this->request->ip()),
            'username'      => self::sessionGet('user.nickname'),
            'create_time'   => time(),
            'log_url'       => '/'.$this->request->pathinfo(),
            'log'           => $condition,
            'user_id'       => $uid,
            'title'         => $title
        ];
        return ActionLog::create($data);
    }

    /**
     * 检查路由权限
     * @access public static
     * @param  string       $path       路由
     * @param  array       $param      参数
     * @return bool
     */
    public static function checkPath($path,$param=[]){
        $uid        = self::sessionGet('user.id');
        if($uid == 1){
            return true;
        }

        $authMenu   = Cache::get('authMenu_'.$uid);

        if(!$authMenu){ //存入缓存 授权菜单
            $authMenu   = self::authMenu();
            Cache::set('authMenu_'.$uid,$authMenu,600);
        }

        $count      = count(explode('/',$path));
        if($count == 2){
            $module = Request::instance()->module();
            $path   = "$module/$path";
        }

        $path = strtolower($path);

        //是否为超级管理员角色
        if($path === true ){
            return true;
        }else if($path === false){
            return false;
        }

        //超级管理员角色跳过路由验证
        if($authMenu === true){
            return true;
        }

        //验证路由
        foreach ($authMenu as $v){
            if($v['rule_name'] == $path){
                if(empty($v['rule_param'])){  //验证规则为空,表示所有通过
                    return true;
                }else{                       //如有验证规则,根据规则验证
                    $condition = false;
                    $command = preg_replace('/\{(\w*?)\}/', '$param[\'\\1\']', $v['rule_param']);
                    @(eval('$condition=(' . $command . ');'));
                    if($condition){
                        return true;
                    }
                }
            }
        }



        return false;
    }

    /**
     * 检查权限
     * @access protected
     * @param  string          $url   路由
     * @param  string          $relation
     * @return mixed
     */
    protected function authCheck($url,$relation='or'){

        $rule   = array($url);
        $list   = []; //保存验证通过的规则名)
        $param  = $this->param;

        $rules = self::authMenu(["AuthAccess.rule_name"=>["in",$rule]]);

        //是否为超级管理员角色
        if($rules === true){
            //行为日志
            self::actionLog($url);
            return true;
        }else if($rules === false){
            return false;
        }

        foreach ($rules as $rule){
            if (!empty($rule['rule_param'])) { //根据rule_param进行验证
                $condition  = false;

                $command = preg_replace('/\{(\w*?)\}/', '$param[\'\\1\']', $rule['rule_param']);

                @(eval('$condition=(' . $command . ');'));
                if ($condition) {
                    $list[] = strtolower($rule['name']);
                }

            }else{
                $list[] = strtolower($rule['name']);
            }
        }

        if ($relation == 'or' and !empty($list)) {

            //行为日志
            self::actionLog($url);
            return true;
        }

        $diff = array_diff($rule, $list);

        if ($relation == 'and' and empty($diff)) {

            return true;
        }

        return false;
    }

    /**
     * 权限访问清单
     * @access private
     * @param array     $where 查询附加条件
     * @param bool      $default  隐藏的菜单
     * @return array
     */
    private static function authMenu($where=[],$default = true){
        $uid        = self::sessionGet('user.id');
        $utype      = self::sessionGet('user.user_type');
        $rule       = [];
        $roleId     = AuthRoleUser::innerAuthRole($uid, $utype);

        if(in_array(1,$roleId)){
            return true;
        }
        $roleId     = implode(',',$roleId);
        //角色权限 or 管理员权限
        if($default === true){
            $rule   = AuthAccess::innerAuthRule($roleId,$uid,$where);
           // dump($rule);die;
        }else if($default === false){

            $rule       = AuthAccess::where($where)
                ->where('(type="admin_url" and role_id in('.$roleId.') and role_type="'.$utype.'")or(type="admin" and role_id =:uid and role_type="'.$utype.'")',['uid'=>$uid])->column('*','menu_id');
        }

        if(empty($rule)){
            return false;
        }
        return $rule;
    }


    /**
     * 检测用户是否登录
     * @return mixed
     */
    public static function is_login(){
        $user = self::sessionGet('user');
        if (empty($user)) {
            return false;
        } else {
            if ((time()-$user['time']) > 7200){ //如果登录时间超过2小时则重新登录
                return  false;
            } else {
                if ((time()-$user['time']) > 3600) { //如果登录时间超过1小时则刷新登录时间
                    $user['time'] = time();
                    $session_prefix = Config::get('thinkcms.session_prefix');
                    Session::set($session_prefix.'user',$user);
                    Session::set($session_prefix.'user_sign',self::data_auth_sign($user));
                }
                return  self::sessionGet('user_sign') == self::data_auth_sign($user) ? $user : false;
            }
        }
    }

    /**
     * 用户登入
     * @access private static
     * @param  int      $uid 用户ID
     * @param  string   $nickname 用户昵称
     * @return array
     */
    public static function login($uid,$nickname, $userinfo, $appid ='', $id = ''){
        if(empty($uid) && empty($nickname)){
            return false;
        }
        // $user = self::sessionGet('user');
        $session_prefix = Config::get('thinkcms.session_prefix');
        $user           = [
                            'id'       => $uid,
                            'user_type'       => $userinfo['user_type'],
                            'nickname'  => $nickname,
                            'appid' => $userinfo['appid'],
                            'role' => $userinfo['role'],
                            'token' => $userinfo['token'],
                            'user_phone' => $userinfo['user_phone'],
                            'time'      => time()
                        ];
        if($id != '')
        {
            Session::init([
                'id'         => $id,
                'auto_start'     => true,
            ]);
        }
        Session::set($session_prefix.'user',$user);
        Session::set($session_prefix.'user_sign',self::data_auth_sign($user));
        $users = self::sessionGet('user');
        return true;
    }

    /**
     * 注销
     * @access private static
     * @return bool
     */
    public static function logout(){
        $session_prefix = Config::get('thinkcms.session_prefix');
        Session::delete($session_prefix.'user');
        Session::delete($session_prefix.'user_sign');
        return true;
    }

    /**
     * 数据签名认证
     * @access private static
     * @param  array  $data 被认证的数据
     * @return string       签名
     */
    private static  function data_auth_sign($data) {
        $code = http_build_query($data); //url编码并生成query字符串
        $sign = sha1($code); //生成签名
        return $sign;
    }


    /**
     * 读取session
     * @access private static
     * @param  string  $path 被认证的数据
     * @return mixed
     */
    private static function sessionGet($path =''){
        $session_prefix = Config::get('thinkcms.session_prefix');
        $user           = Session::get($session_prefix.$path);
        return $user;
    }


}