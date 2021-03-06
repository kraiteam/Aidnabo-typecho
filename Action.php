<?php
include_once 'Utils.php';

class Aidnabo_Action extends Typecho_Widget implements Widget_Interface_Do
{
    public $db;
    public $request;
    public $response;
    public $options;
    public $option;

    /**
     * Aidnabo_Action constructor.
     * @param $request
     * @param $response
     * @param null $params
     * @throws Typecho_Db_Exception
     * @throws Typecho_Exception
     */
    public function __construct($request, $response, $params = null)
    {
        parent::__construct($request, $response, $params);
        $this->db = Typecho_Db::get();
        $this->request = $request;
        $this->response = $response;
        $this->options = Typecho_Widget::widget('Widget_Options');
        $this->option = $this->options->plugin('Aidnabo');
    }

    /**
     * 密码处理
     * @param $password
     * @param $pwd
     * @return bool
     * @throws Typecho_Db_Exception
     * @throws Typecho_Exception
     * @noinspection PhpUndefinedMethodInspection
     * @noinspection SpellCheckingInspection
     */
    public static function hashValidate($password, $pwd)
    {
        $action = new Aidnabo_Action(
            Typecho_Request::getInstance(),
            Typecho_Response::getInstance()
        );
        /** 判断是否是XmlRpc */
        $isXmlRpc = strpos($action->request->getPathInfo(), "action/xmlrpc") !== false;
        $name = $isXmlRpc ? Typecho_Cookie::get('__typecho_xmlrpc_name') : $action->request->get('name');
        /** 检验 */
        $user = $action->db->fetchRow($action->db->select()
            ->from('table.users')
            ->join('table.users_aid', 'table.users.uid = table.users_aid.uid', Typecho_Db::LEFT_JOIN)
            ->where((strpos($name, '@') ? 'table.users.mail' : 'table.users.name') . ' = ?', $name)
            ->limit(1));
        /** 安全密码登录状态 */
        $securityLogin = false;
        $hashValidate = false;
        if ($isXmlRpc) {
            /** 判断是否强制匹配union */
            if ($user['unionSafe'] == 1) {
                if (!hash_equals(Typecho_Cookie::get('__typecho_xmlrpc_union'), $user['union'])) {
                    return false;
                }
            }
            $securityLogin = $user['rpcSafe'] == 1;
            /** 判断是否强制安全密码登录或者是安全密码登录 */
            if ($securityLogin or preg_match("/^[a-f0-9]{32}$/", $password)) {
                $securityLogin = true;
                $hashValidate = hash_equals($password, md5($pwd));
            }
        } else {
            /** 判断是否启用了二步验证码 */
            if ($user['gauthSafe'] == 1 && Aidnabo_Action::gauthEnable()) {
                $oneCode = intval($action->request->get('otp'));
                if ($oneCode > 0) {
                    $Authenticator = new GoogleAuthenticator();
                    if (!$Authenticator->verifyCode($user['gauthKey'], $oneCode, 4)) {
                        return false;
                    }
                } else {
                    return false;
                }
            }
        }
        if (!$securityLogin) {
            if ('$P$' == substr($pwd, 0, 3)) {
                $hasher = new PasswordHash(8, true);
                $hashValidate = $hasher->CheckPassword($password, $pwd);
            } else {
                $hashValidate = Typecho_Common::hashValidate($password, $pwd);
            }
        }
        return $hashValidate;
    }

    /**
     * 二步验证码，总开关
     * 如果存在 gauthSafe.txt 文件，将不生效 (用于紧急登陆后台)
     *
     * @return bool
     */
    public static function gauthEnable()
    {
        return !file_exists(__DIR__ . "/gauthSafe.txt");
    }

    /**
     * GoogleAuthLogin
     */
    public static function GoogleAuthLogin()
    {
        if (strpos(Typecho_Request::getInstance()->getBaseUrl(), 'login.php') !== false && Aidnabo_Action::gauthEnable()) {
            $db = Typecho_Db::get();
            $num = $db->fetchRow(
                $db->select('count(1) AS count')->where('gauthSafe = ?', '1')->from('table.users_aid')
            )['count'];
            /** 如果存在账户开启了二步验证，则输出脚本 **/
            if ($num > 0):
                ?>
                <script>
                    $(document).ready(function () {
                        $("form p:nth-of-type(2)").append('<p><label for="authCode" class="sr-only">两步验证码</label><input type="text" id="otp" name="otp" value="" placeholder="两步验证码" class="text-l w-100" autofocus=""></p>');
                    });
                </script>
            <?php
            endif;
        }
    }

    /**
     * XmlRpc upgrade
     */
    public function upgrade()
    {
        if (empty($this->request->versionName)) {
            return;
        }
        $vn = trim($this->request->versionName);
        if (version_compare($vn, '1.3', '<')) {
            $this->throwJson(array(
                false,
                "非法版本号"
            ));
        }
        if ($this->option->rpcUpdateSafe == 0) {
            $this->throwJson(array(
                false,
                "你已关闭RPC更新能力"
            ));
        }
        // 判断时间戳是否在有效期
        if (time() > $this->request->timestamp + 60) {
            $this->throwJson(array(
                false,
                "参数timestamp异常"
            ));
        }
        // 鉴权
        if (!hash_equals($this->request->sign, md5($this->option->rpcKey . $this->request->timestamp))) {
            $this->throwJson(array(
                false,
                "RPC密匙错误"
            ));
        }
        try {
            $aar = Aidnabo_Utils::curlDownFile($vn);
            if ($aar[0]) {
                $aar = Aidnabo_Utils::unzip($vn);
            }
            $this->throwJson($aar);
        } catch (Exception $e) {
            $this->throwJson(array(
                false,
                "异常"
            ));
        }
    }

    /**
     * throwJson
     * @param $message
     */
    public function throwJson($message)
    {
        $this->response->setContentType('application/json');
        echo json_encode(array(
            "state" => $message[0],
            "msg" => $message[1]
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function execute()
    {

    }

    public function action()
    {

    }
}