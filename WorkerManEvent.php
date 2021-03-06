<?php
use Workerman\Connection\ConnectionInterface;
class WorkerManEvent{
    public static $onlyHttp = false;
    //有新的连接进入时， $fd 是连接的文件描述符
    public static function onConnect(ConnectionInterface $connection){
        $fd = $connection->id;
    }
    //接收到数据时回调此函数
    public static function onMessage(ConnectionInterface $connection, $data){
        /*if(SrvBase::$instance->getConfig('max_request',0)>0){
            static $request_count;
            // 业务处理略
            if(++$request_count > SrvBase::$instance->getConfig('max_request')) {
                // 请求数达到10000后退出当前进程，主进程会自动重启一个新的进程
                Worker::stopAll();
            }
        }*/

        //如果有http请求需要判断处理
        #$port = $connection->getLocalPort();
        if(self::$onlyHttp || (isset($connection->worker->type) && $connection->worker->type==SrvBase::TYPE_HTTP)){
            SrvBase::$isHttp = true;
            //重置
            $_SERVER = myphp::env('_server', $_SERVER); //使用初始的server 防止server一直增加数据
            myphp::setEnv('headers', $data->header());
            myphp::setEnv('rawBody', $data->rawBody()); //file_get_contents("php://input")
            $_COOKIE = $data->cookie();
            $_FILES = $data->file();
            $_GET = $data->get();
            $_POST = $data->post();
            $_REQUEST = array_merge($_GET, $_POST);
            $_SERVER['REMOTE_ADDR'] = $connection->getRemoteIp();
            $_SERVER['REQUEST_METHOD'] = $data->method();
            foreach ($data->header() as $k=>$v){
                $k = ($k == 'content-type' || $k == 'content-length' ? '' : 'HTTP_') . str_replace('-', '_', strtoupper($k));
                $_SERVER[$k] = $v;
            }
            //客户端的真实IP
            if($data->header('x-real-ip') || $data->header('x-forwarded-for')) { // HTTP_X_REAL_IP HTTP_X_FORWARDED_FOR
                Helper::$isProxy = true;
            }
            $_SERVER['HTTP_HOST'] = $data->host();
            $_SERVER['SCRIPT_NAME'] = '/index.php';
            $_SERVER['PHP_SELF'] = $data->path();
            $_SERVER["REQUEST_URI"] = $data->uri();
            $_SERVER['QUERY_STRING'] = $data->queryString();
            Log::DEBUG('[http]'.toJson($_REQUEST));
            if(!isset($_GET['c']) && isset($_POST['c'])) $_GET['c'] = $_POST['c'];
            if(!isset($_GET['a']) && isset($_POST['a'])) $_GET['a'] = $_POST['a'];
            if (Q('async%d')==1) { //异步任务
                $task_id = SrvBase::$instance->task([
                    '_SERVER'=>$_SERVER,
                    '_REQUEST'=>$_REQUEST,
                    '_GET'=>$_GET,
                    '_POST'=>$_POST
                ]);
                $response = new \Workerman\Protocols\Http\Response(200, [
                    'Content-Type'=>'application/json; charset=utf-8'
                ]);
                if($task_id===false){
                    $response->withBody(Helper::toJson(Control::fail('异步任务调用失败:'.SrvBase::err())));
                }else{
                    $response->withBody(Helper::toJson(Control::ok(['task_id'=>$task_id])));
                }
                $connection->send($response);
            } else {
                myphp::Run(function($code, $data, $header) use($connection){
                    $code = isset(myphp::$httpCodeStatus[$code]) ? $code : 200;
                    // 发送状态码
                    $response = new \Workerman\Protocols\Http\Response($code);
                    // 发送头部信息
                    $response->withHeaders($header);
                    // 发送内容
                    $response->withBody(is_string($data) ? $data : toJson($data));
                    $connection->send($response);
                }, false);
                //清除本次请求的数据
                myphp::setEnv('headers');
                myphp::setEnv('rawBody');
            }
        }else{
            $connection->send($data);
        }

    }
    //客户端连接关闭事件
    public static function onClose(ConnectionInterface $connection){
        if((isset($connection->worker->type) && $connection->worker->type==SrvBase::TYPE_HTTP)) return true;
        //todo
        return true;
    }
    //当连接的应用层发送缓冲区满时触发
    public static function onBufferFull(ConnectionInterface $connection){
        //echo "bufferFull and do not send again\n";
        $connection->pauseRecv(); //暂停接收
    }
    //当连接的应用层发送缓冲区数据全部发送完毕时触发
    public static function onBufferDrain(ConnectionInterface $connection){
        //echo "buffer drain and continue send\n";
        $connection->resumeRecv(); //恢复接收
    }
    //异步任务 在task_worker进程内被调用
    public static function onTask($task_id, $src_worker_id, $data){
        //重置
        $_SERVER = $data['_SERVER'];
        $_REQUEST = $data['_REQUEST'];
        $_GET = $data['_GET'];
        $_POST = $data['_POST'];
        myphp::Run(function($code, $data, $header) use($task_id){
            //is_string($data) ? $data : toJson($data)
            if(SwooleSrv::$isConsole) echo "AsyncTask Finish:Connect.task_id=" . $task_id . (is_string($data) ? $data : toJson($data)). PHP_EOL;
        }, false);
        unset($_SERVER, $_REQUEST, $_GET, $_POST);
        return true;
        //return 等同$server->finish($response); 这里没有return不会触发finish事件
    }
}