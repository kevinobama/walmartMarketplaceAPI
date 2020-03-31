<?php
    class Client{
        public $status=null;            // 返回的HTTP状态代码: 200, 404, ...
        public $header;
        public $content;

        public $agent="ClueHTTPClient";
        public $referer=null;
        public $custom_header=[];       // 类似 {AWS_AUTH:xxxxx, AWS_SECRET:yyyyyy}

        private $cookie_file;
        private $cookie=[];
        private $cache;
        private $curl;

        /**
         * Example of config file:
         *
         * $config=array(
         *      'cookie'=>'curl.cookie'
         * )
        */

        function __construct($config=array()){
            $default_config=array(
                'proxy'=>getenv("http_proxy"),
                'connect_timeout'=>15,
                'timeout'=>60,
                'debug'=>false,
            );

            $this->config=array_merge($default_config, $config);
        }

        function __destruct(){
            if($this->curl) curl_close($this->curl);
        }

        function __get($name){
            if($name=='status'){
                return curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
            }
        }

        function init_request($method, $url, $options=[]){
            $this->content=null;
            if($this->curl) curl_close($this->curl);

            $this->curl=curl_init();

            // DEBUG
            if($this->config['debug']){
                curl_setopt($this->curl, CURLINFO_HEADER_OUT, true);

                // CURLOPT_VERBOSE不能和CURLINFO_HEADER_OUT同时使用
                // if(@$this->config['verbose']){
                //  curl_setopt($this->curl, CURLOPT_VERBOSE, true);
                // }
            }

            // 目标地址
            curl_setopt($this->curl, CURLOPT_URL, $url);
            curl_setopt($this->curl, CURLOPT_HEADER, true);
            curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->curl, CURLOPT_FOLLOWLOCATION, true);

            // 超时设定
            curl_setopt($this->curl, CURLOPT_CONNECTTIMEOUT, $this->config['connect_timeout']);
            curl_setopt($this->curl, CURLOPT_TIMEOUT, $this->config['timeout']);

            switch($method){
                case 'GET':
                    break;

                case 'HEAD':
                    curl_setopt($this->curl, CURLOPT_NOBODY, true);
                    break;

                case 'POST':
                    curl_setopt($this->curl, CURLOPT_POST, true);
                    // PHP5.6 requires CurlFile
                    if(version_compare(PHP_VERSION, '5.6.0') < 0){
                        curl_setopt($this->curl, @CURLOPT_SAFE_UPLOAD, false);
                    }
                    break;

                case 'PUT':
                    curl_setopt($this->curl, CURLOPT_PUT, true);
                    // $this->custom_header['X-HTTP-Method-Override']='PUT';
                    break;

                default:
                    curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, $method);
            }

            // 代理连接
            if(preg_match('/^sock[45s]?:\/\/([a-z0-9\-_\.]+):(\d+)$/i', $this->config['proxy'], $m)){
                list($_, $proxy, $port)=$m;
                curl_setopt($this->curl, CURLOPT_PROXY, $proxy);
                curl_setopt($this->curl, CURLOPT_PROXYPORT, $port);

                // Use socks5-hostname to prevent GFW DNS attack
                if(!defined('CURLPROXY_SOCKS5_HOSTNAME')) define('CURLPROXY_SOCKS5_HOSTNAME', 7);
                curl_setopt($this->curl, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
                // curl_setopt($this->curl, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
            }
            elseif(preg_match('/^(http:\/\/)?([a-z0-9\-_\.]+):(\d+)/i', $this->config['proxy'], $m)){
                list($_, $scheme, $proxy, $port)=$m;

                curl_setopt($this->curl, CURLOPT_PROXY, $proxy);
                curl_setopt($this->curl, CURLOPT_PROXYPORT, $port);
            }

            // HTTPS
            if(@$this->config['ignore_certificate']){
                curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, false);
            }

            // HTTP认证
            if(isset($this->config['http_user']) && isset($this->config['http_pass'])){
                if(isset($this->config['http_auth'])) switch(strtoupper($this->config['http_auth'])){
                    case 'BASIC':
                        curl_setopt($this->curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                        break;
                    case 'DIGEST':
                        curl_setopt($this->curl, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
                        break;
                    case 'NTLM':
                        curl_setopt($this->curl, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
                        break;
                    case 'GSS':
                        curl_setopt($this->curl, CURLOPT_HTTPAUTH, CURLAUTH_GSSNEGOTIATE);
                        break;
                    default:
                        break;
                }

                curl_setopt($this->curl, CURLOPT_USERPWD, "{$this->config['http_user']}:{$this->config['http_pass']}");
            }

            if(isset($this->config['proxy_user']) && isset($this->config['proxy_pass'])){
                if(isset($this->config['proxy_auth'])) switch(strtoupper($this->config['proxy_auth'])){
                    case 'BASIC':
                        curl_setopt($this->curl, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
                        break;
                    case 'NTLM':
                        curl_setopt($this->curl, CURLOPT_PROXYAUTH, CURLAUTH_NTLM);
                        break;
                    // DIGEST和GSS可能无法支持
                    case 'DIGEST':
                        curl_setopt($this->curl, CURLOPT_PROXYAUTH, CURLAUTH_DIGEST);
                        break;
                    case 'GSS':
                        curl_setopt($this->curl, CURLOPT_PROXYAUTH, CURLAUTH_GSSNEGOTIATE);
                        break;
                    default:
                        break;
                }

                curl_setopt($this->curl, CURLOPT_PROXYUSERPWD, "{$this->config['proxy_user']}:{$this->config['proxy_pass']}");
            }

            // HTTP Referer
            if($this->referer){
                curl_setopt($this->curl, CURLOPT_REFERER, $this->referer);
            }

            // User Agent
            curl_setopt($this->curl, CURLOPT_USERAGENT, $this->agent);

            // Cookie存取
            if($this->cookie_file){
                curl_setopt($this->curl, CURLOPT_COOKIEFILE, $this->cookie_file);   // read
                if(!$this->cookie_readonly){
                    curl_setopt($this->curl, CURLOPT_COOKIEJAR, $this->cookie_file);    // write
                }
            }
            if($this->cookie){
                $pair=array();
                foreach($this->cookie as $k=>$v){
                    $pair[]="$k=$v";
                }
                curl_setopt($this->curl, CURLOPT_COOKIE, implode("; ", $pair));
            }

            // 自定义header
            if($this->custom_header){
                $headers=[];
                foreach($this->custom_header as $name=>$value){
                    $headers[]="$name: $value";
                }

                curl_setopt($this->curl, CURLOPT_HTTPHEADER, $headers);
            }

            // 额外的curl option
            foreach($options as $name=>$value){
                curl_setopt($this->curl, $name, $value);
            }
        }

        function enable_cache($cache_dir, $cache_ttl=86400){
            $this->cache=new CacheStore($cache_dir, $cache_ttl);
        }
        function disable_cache(){ $this->cache=null; }
        function destroy_cache($url){
            if($this->cache){
                $this->cache->destroy($url);
            }
        }

        function enable_cookie($cookie_file, $readonly=false){
            $this->cookie_file=$cookie_file;
            $this->cookie_readonly=$readonly;
        }

        function disable_cookie($cookie_file){ $this->cookie_file=null; }
        function set_cookie($cookies=array()){ $this->cookie=array_merge($this->cookie, $cookies); }
        function get_cookie(){
            $cookies=[];
            if(file_exists($this->cookie_file)){
                foreach(file($this->cookie_file) as $line){
                    if(preg_match('/^\..*\s+(\S+)\s+(\S+)$/', $line, $m)){
                        $cookies[$m[1]]=$m[2];
                    }
                }
            }

            return array_merge($cookies, $this->cookie);
        }

        function set_agent($agent){ $this->agent=$agent; }

        /**
         * 辅助函数
         */
        public function follow_url($url, $current=null){
            if(empty($url)) return $current;

            $parts=parse_url(trim($url));

            // Another host
            if(isset($parts['host'])) return $url;
            if(isset($parts['scheme'])) return $url;

            $current=parse_url($current ?: $this->referer);

            $path=isset($current['path']) ? explode("/",  $current['path']) : array("");
            if(isset($parts['path'])){
                // Jump to root if path begins with '/'
                if(strpos($parts['path'],'/')===0) $path=array();

                // Remove tip file
                if(count($path)>1) array_pop($path);

                // Normalize path
                foreach(explode("/", $parts['path']) as $p){
                    if($p=="."){
                        continue;
                    }
                    elseif($p=='..'){
                        if(count($path)>1) array_pop($path);
                        continue;
                    }
                    else{
                        array_push($path, $p);
                    }
                }
            }

            // Build url
            $result=array();
            $result[]=$current['scheme'].'://';
            $result[]=$current['host'];
            $result[]=isset($current['port']) ? $current['port'] : "";
            $result[]=implode("/", $path);
            $result[]=isset($parts['query']) ? '?'.$parts['query'] : "";
            $result[]=isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

            return implode("", $result);
        }


        function get($url, $param=[]){
            // 使用param数组合并url参数
            if($param){
                $info=parse_url($url);
                parse_str(@$info['query'], $query);
                $info['query']=http_build_query($param+$query);

                $url=$this->_build_url($info);
            }

            $this->init_request('GET', $url, [
                CURLOPT_ENCODING=>''
            ]);

            $this->cache_hit=false;

            // 尝试从cache获取
            if($this->cache){
                list($this->content, $meta)=$this->cache->get($url);
                $this->status=$meta['status'];
                $this->header=$meta['header'];

                if($this->content) $this->cache_hit=true;
            }

            if(!$this->content){
                $this->_parse_response(curl_exec($this->curl));

                $this->errno=curl_errno($this->curl);
                $this->error=curl_error($this->curl);

                if($this->errno==0 && $this->cache){
                    $this->cache->put($url, $this->content, ['status'=>$this->status, 'header'=>$this->header]);
                }
            }

            return $this->content;
        }

        function post($url, $data){
            // 如果想直接作为raw data提交
            // $this->custom_header['Content-Type']='text/plain';

            $this->init_request('POST', $url, [
                CURLOPT_POSTFIELDS=>$data
            ]);

            $this->_parse_response(curl_exec($this->curl));
            return $this->content;
        }

        function head($url){
            $this->init_request('HEAD', $url);
            $this->_parse_response(curl_exec($this->curl));
            return $this->header;
        }

        function delete($url){
            $this->init_request('DELETE', $url);

            $this->_parse_response(curl_exec($this->curl));
            return $this->content;
        }

        function options($url, $content){
            $content=is_string($content) ? $content : json_encode($content);

            $this->init_request('OPTIONS', $url, [
                CURLOPT_POSTFIELDS=>$content
            ]);

            $this->_parse_response(curl_exec($this->curl));
            return $this->content;
        }

        function put($url, $file){
            if(is_resource($file)){
                // 已经是文件，到最尾端，确定长度
                fseek($file, 0, SEEK_END);
            }
            else{
                $content=is_string($file) ? $file : json_encode($file);

                $file=fopen('php://temp', 'wb+');
                fputs($file, $content);
            }

            $size=ftell($file);
            fseek($file, 0);

            // 直接上传文件
            $this->init_request('PUT', $url, [
                CURLOPT_INFILE=>$file,
                CURLOPT_INFILESIZE=>$size
            ]);


            $this->_parse_response(curl_exec($this->curl));
            return $this->content;
        }

        function download($url, $dest){
            $file=fopen($dest, 'w');

            if($file){
                $this->init_request('GET', $url, [
                    CURLOPT_FILE=>$file,
                    CURLOPT_HEADER=>false,
                ]);

                curl_exec($this->curl);
                fclose($file);

                $this->errno=curl_errno($this->curl);
                $this->error=curl_error($this->curl);

                if($this->errno){
                    unlink($dest);
                }
            }
        }

        /**
         * 使Cache失效
         */
        function expire($url){
            if($this->cache) $this->cache->destroy($url);

        }

        /**
         * ParseURL的逆操作
         */
        private function _build_url(array $info){
            $url=(isset($info["scheme"])?$info["scheme"]."://":"").
                (isset($info["user"])?$info["user"].":":"").
                (isset($info["pass"])?$info["pass"]."@":"").
                (isset($info["host"])?$info["host"]:"").
                (isset($info["port"])?":".$info["port"]:"").
                (isset($info["path"])?$info["path"]:"").
                (isset($info["query"])?"?".$info["query"]:"").
                (isset($info["fragment"])?"#".$info["fragment"]:"");

            return $url;
        }

        private function _parse_response($response){
            $this->response=$response;
            $this->status=curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

            $this->header=[
                'url'=>curl_getinfo($this->curl, CURLINFO_EFFECTIVE_URL)        // 因为有自动跳转，获取真实有效的地址
            ];

            $this->referer=curl_getinfo($this->curl, CURLINFO_EFFECTIVE_URL);

            $this->request=curl_getinfo($this->curl, CURLINFO_HEADER_OUT);

            while(preg_match('/^HTTP\/([0-9.]+)\s+(\d+)\s*(.+?)\r\n.+?\r\n\r\n/ms', $response, $header)){
                $this->status=$header[2];

                // 识别HTTP错误代码
                if(in_array($this->status[0], ['4','5'])){
                    $this->error="HTTP $this->status ".trim($header[3]);
                    $this->errno=intval($this->status);
                }

                foreach(explode("\n", $header[0]) as $row){
                    if(preg_match('/^([a-z0-9-]+):(.+)$/i', $row, $m)){
                        $this->header[trim($m[1])]=trim($m[2]);

                        // 保存临时Cookie
                        if(trim($m[1])=='Set-Cookie'){
                            $sc=explode(";", trim($m[2]), 2);
                            list($n, $v)=explode("=", $sc[0], 2);

                            $this->cookie[$n]=$v;
                        }
                    }
                }

                // 去掉HTTP头部
                $response=substr($response, strlen($header[0]));
            }

            $this->content=$response;
        }
}