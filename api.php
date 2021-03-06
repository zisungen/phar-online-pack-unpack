<?php
chdir(__DIR__);
include('function.php');
$cfg = include('config.php');
if(isset($_GET)){
	if($_GET['mode']=='upload'){  //上传模式
		$id = md5($_FILES["file"]["name"].microtime());
		file_put_contents('upload.log',json_encode(array('id'=>$id,'name'=>$_FILES["file"]["name"]))."\n",FILE_APPEND);
		if(preg_match('/\.phar$/', $_FILES["file"]["name"])){ //解包
			copy($_FILES["file"]["tmp_name"], 'cache/'.$id.'.phar');
			extractphar($id);
			echo json_encode(array(
				'id'=>$id,
				'method'=>'unpack',
				'url'=>'api.php?mode=download&type=zip&id='.$id.'&filename='.urlencode(preg_replace('/\.phar$/','.zip', $_FILES["file"]["name"])),
				'viewurl'=>'api.php?mode=view&id='.$id,
				'progress'=>false
			));
			@unlink($filepath);
		} elseif(preg_match('/\.zip$/', $_FILES["file"]["name"])){//异步打包
			//ob_start();
			copy($_FILES["file"]["tmp_name"], 'cache/'.$id.'.zip');
			$filepath = 'cache/'.$id.'.zip';
			mkdir('cache/'.$id);
			echo json_encode(array(
				'id'=>$id,
				'method'=>'pack',
				'url'=>'api.php?mode=download&type=phar&id='.$id.'&filename='.urlencode(preg_replace('/\.zip$/','.phar', $_FILES["file"]["name"])),
				'progress'=>true
				));
			file_put_contents('progress/'.$id.'.json', json_encode(['p' => '0', 't' => time()]));
			//echo str_repeat(' ', 1024*256);
			if(isset($_GET['wait'])){//等待模式
				makephar($id, isset($_GET['highspeed']));
			} elseif($cfg['packmode']==0){//缓冲区控制
				$size = ob_get_length();
				header("Content-Length: $size");
				header('Connection: close');
				ob_end_flush();
				//ob_flush();
				flush();
				makephar($id, isset($_GET['highspeed']));
			} else {//自激活
				$ch = curl_init();
				//设置模目标
				if(isset($_GET['highspeed'])){
					curl_setopt($ch, CURLOPT_URL, $_SERVER['HTTP_HOST'].':'.$_SERVER["SERVER_PORT"].str_replace('index.php','',$_SERVER['PHP_SELF']).'/api.php?mode=makephar&password='.md5($cfg['password'].php_uname()).'&id='.$id.'&highspeed');
				} else {
					curl_setopt($ch, CURLOPT_URL, $_SERVER['HTTP_HOST'].':'.$_SERVER["SERVER_PORT"].str_replace('index.php','',$_SERVER['PHP_SELF']).'/api.php?mode=makephar&password='.md5($cfg['password'].php_uname()).'&id='.$id);
				}
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_HEADER, 0);
				curl_setopt($ch, CURLOPT_TIMEOUT,2);//激活自动断开时间
				//执行激活
				$output = curl_exec($ch);
				//关闭连接
				curl_close($ch);
			}
		}
	} elseif($_GET['mode']=='download'){
		$file = 'cache/'.preg_replace('/(\\|\/)/','',$_GET['id']).'.'.preg_replace('/(\\|\/)/','',$_GET['type']);
		if(file_exists($file)){
			header('Content-Type:application/zip'); //发送指定文件MIME类型的头信息
			header('Content-Disposition:attachment; filename="'.$_GET['filename'].'"'); //发送描述文件的头信息，附件和文件名
			header('Content-Length:'.filesize($file)); //发送指定文件大小的信息，单位字节
			readfile($file);
		} else {
			echo 'File Not Found.';
		}
	} elseif($_GET['mode']=='makezip'){
		$id = md5(microtime()+rand(0,100));
		$ret = makezip($id, $_POST);
		echo json_encode(array('id'=>$id,'url'=>'api.php?mode=download&type=zip&id='.$id.'&filename='.urlencode(preg_replace('/\.zip$/','', $ret['file']).'等'.$ret['count'].'个文件.zip')));
	} elseif($_GET['mode']=='makephar'){
		if($_GET['password']==md5($cfg['password'].php_uname())){
			makephar($_GET['id'], isset($_GET['highspeed']));
		}
	} elseif($_GET['mode'] == 'view'){
		include('view.php');
	} elseif($_GET['mode'] == 'cron'){
		$filecount = 0;
		$dircount = 0;
		foreach(glob('cache/*') as $file){
			if(basename($file) == '.htaccess'){
				continue;
			}
			if(filemtime($file) < time() - 600){
				if(is_dir($file)){
					deldir($file);
					$dircount ++;
				} else {
					@unlink($file);
					$filecount ++;
				}
			}
		}
		echo 'Remove '.$filecount.' out of date files and ' . $dircount . ' dirs';
	} elseif($_GET['mode'] == 'progress'){
		$id = str_replace(['\\', '/'], ['', ''], $_GET['id']);
		if(file_exists('progress/'.$id.'.json')){
			$data = file_get_contents('progress/'.$id.'.json');
			$data = json_decode($data, true);
			if($data['t'] < (time() - 30)){
				echo 'false';
				@unlink('progress/'.$id.'.json');
			} elseif($data['p'] == 'true'){
				echo 'true';
				@unlink('progress/'.$id.'.json');
			} else {
				echo $data['p'];
			}
		}
	}
} else {
	header('Location: .');
}
