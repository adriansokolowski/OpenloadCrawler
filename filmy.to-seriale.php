<?php

// Copyright 2017 AS

set_time_limit(0);

error_reporting(E_ALL);
ini_set('display_errors', 1);

function openloadRemoteUpload($id) {
    $openload = [
        'login' => '928f13effa4bec5f',
        'key' => 'hQcK5erM'
    ];

    $url = 'https://openload.co/f/' . $id;

    $upload = file_get_contents('https://api.openload.co/1/remotedl/add?login=' . $openload['login'] . '&key=' . $openload['key'] . '&url=' . $url . '&folder=2462559');

    $id = isset(json_decode($upload, true)['result']['id']) ? json_decode($upload, true)['result']['id'] : false;

    if(!$id) return false;

    $status = file_get_contents('https://api.openload.co/1/remotedl/status?login=' . $openload['login'] . '&key=' . $openload['key'] . '&id=' . $id);

    $newId = array_values(json_decode($status, true)['result'])[0]['extid'];

    return empty($newId) ? $id : $newId;
}

require_once 'application/config/config.php';

$options = array(
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'
);
$db = new Pdo(DB_TYPE . ':host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8', DB_USER, DB_PASS, $options);

$query = $db->prepare("SELECT series_id, title FROM series");
$query->execute();
$series = $query->fetchAll();

?>
<style type="text/css">
  body {
    font-family: Arial;
    font-size: 12px;
  }
  input, select {
    width: 500px;
    padding: 10px;
  }
</style>
<p>
Przykład:<br><br>
ID: 1<br>
URL: http://filmy.to/serial/Jak_poznalem_wasza_matke-2005_s01e01,9620<br>
</p>
<form action="" method="post">
  <select name="series_id" id="">
  	<?php foreach($series as $series): ?>
	<option value="<?= $series['series_id'] ?>"><?= $series['title'] ?></option>
	<?php endforeach ?>
  </select><br><br>
  <input type="text" name="url" placeholder="Link do serialu na filmy.to"><br><br>
  <input type="submit" name="submit" value="Dodaj"><br><br>
</form>

<?php

if(isset($_POST['submit'])) {

    function files($singleURL)
    {

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $singleURL);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; rv:19.0) Gecko/20100101 Firefox/19.0');
        curl_setopt($curl, CURLOPT_HEADER, 1);
        $website = curl_exec($curl);

        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $website, $allCookies);
        $cookies = array();
        foreach($allCookies[1] as $item) {
            parse_str($item, $cookie);
            $cookies = array_merge($cookies, $cookie);
        }

		$result = [];

        preg_match('@"view": "(.*?)"@', $website, $view);
        $view = trim($view[1]);

        preg_match('@<meta property="provision" content="(.*?)">@', $website, $provision);
        $provision = trim($provision[1]);

        $csrftoken = $cookies['csrftoken'];

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'http://filmy.to/ajax/provision/' . $provision);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; rv:19.0) Gecko/20100101 Firefox/19.0');
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'X-CSRFToken: ' . $csrftoken,
            'X-Requested-With: XMLHttpRequest'
        ));
        $website = curl_exec($curl);
        curl_close($curl);

        preg_match_all('@data-url="(.*?)"@', $website, $url);
        preg_match_all('@<span class="label label-default">(.*?)</span>@', $website, $version);
        preg_match_all('@title="Jakość: (.*?)"@', $website, $quality);

		foreach ($url[1] as $key => $value) {
		  preg_match('@https://openload.co/embed/(.*?)&@', $value, $openload);

		  if(isset($openload[1])) {
		    if(isset($result[$version[1][$key]][0])) continue;

		    $v = $version[1][$key];

		    if($v == 'Dubbing PL') {
                $v = 2;
            } elseif($v == 'Lektor PL') {
                $v = 1;
            } elseif($v == 'Film polski') {
                $v = 4;
            } elseif($v == 'Napisy PL') {
                $v = 3;
            } else {
                $v = 1;
            }

            $q = $quality[1][$key];

        	if($q == 'Niska') {
                $q = 1;
            } elseif($q == 'Średnia') {
                $q = 2;
            } elseif($q == 'Wysoka') {
                $q = 3;
            } else {
                $q = 1;
            }

		    $result[$v][] = [
				'domain' => 'openload.co',
				'file' => $openload[1],
				'version' => $v,
				'quality' => $q
		    ];

		  }
		}

		return $result;
    }

    function insertFiles($files, $episode_id)
    {
    	global $db;

        if(!is_array($files)) return false;

        $flat = [];

		foreach ($files as $key => $value) {

		  foreach ($value as $v) {
		    $flat[] = [
		      'domain' => $v['domain'],
		      'file' => $v['file'],
		      'version' => $v['version'],
		      'quality' => $v['quality']
		    ];
		  }
		}

		$query = $db->prepare("SELECT filmy_to_links FROM episode WHERE episode_id = ?");
        $query->execute(array($episode_id));

        $filmy_to_links = $query->fetch()['filmy_to_links'];

        if(empty($filmy_to_links)) {
        	$filmy_to_links = [];
        } else {
        	$filmy_to_links = unserialize($filmy_to_links);
        }

        foreach ($flat as $key => $value) {
        	if(!in_array($value['file'], array_column($filmy_to_links, 'file'))) {
				$query = $db->prepare("INSERT INTO link (user_id, episode_id, domain, file, version, quality, created_date) VALUES (:user_id, :episode_id, :domain, :file, :version, :quality, NOW())");
                $query->execute(array(
                    'user_id' => 1,
                    'episode_id' => $episode_id,
                    'domain' => $value['domain'],
                    'file' => openloadRemoteUpload($value['file']),
                    'version' => $value['version'],
                    'quality' => $value['quality']
                ));

        	}
        }

        $query = $db->prepare("UPDATE episode SET filmy_to_links = ? WHERE episode_id = ?");
    	$query->execute(array(serialize($flat), $episode_id));
    }

	$curl = curl_init();
	curl_setopt($curl, CURLOPT_URL, $_POST['url']);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; rv:19.0) Gecko/20100101 Firefox/19.0');
	$website = curl_exec($curl);
	preg_match_all('@<option.*?value="(.*?)">Sezon .*?</option>@s', $website, $url);
	$url = $url[1];

    $allUrls = array();

    foreach ($url as $key => $value) {
        $website = file_get_contents('http://filmy.to' . $value);
        $website = explode('<h3>Odcinki:</h3>', $website)[1];
        preg_match_all('@<a href="(.*?)" class=".*?">.*?<span class="ep_nr.*?">.*?:</span><span class="ep_tyt.*?">.*?</span>.*?</a>@si', $website, $episode);
        foreach ($episode[1] as $k => $v) {
            if(empty($v)) continue;
            $allUrls[] = 'http://filmy.to' . $v;
        }
    }

	foreach($allUrls as $kkk => $singleURL) {
		$data = array();
		$files = array();

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $singleURL);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; rv:19.0) Gecko/20100101 Firefox/19.0');
		$website = curl_exec($curl);

        preg_match('@<option selected="selected" value="/serial/.*?">Sezon (.*?)</option>@s', $website, $season);
        $data['season'] = $season[1];

        preg_match_all('@<a href="/serial/.*?" class="active.*?">.*?<span class="ep_nr.*?">(.*?):</span><span class="ep_tyt.*?">(.*?)</span>.*?</a>@s', $website, $info);


        if(!isset($info[1][0])) continue;

        $data['episode'] = $info[1][0];

        $data['title'] = trim($info[2][0]);

        $data['series_id'] = $_POST['series_id'];

        if($data['season'] > 0) {
        	$data['season'] = ltrim($data['season'], '0');
        }

         if($data['episode'] > 0) {
        	$data['episode'] = ltrim($data['episode'], '0');
        }

        if(empty($data['episode']) || empty($data['season'])) continue;

        try{
            $query = $db->prepare("SELECT episode_id FROM episode WHERE series_id = ? AND episode = ? AND season = ?");
            $query->execute(array($data['series_id'], $data['episode'], $data['season']));

        } catch(Exception $e){
            echo $e->getMessage();

            continue;
        }

        $files = files($singleURL);

        // print_r($files);
        // exit;


        if(!is_array($files) || empty($files)) continue;

        if ($query->rowCount() > 0) {
        	$episode_id = $query->fetch()['episode_id'];
			insertFiles($files, $episode_id);
        } else {
			$query = $db->prepare("INSERT INTO episode (user_id, series_id, title, season, episode, visible, created_date) VALUES (:user_id, :series_id, :title, :season, :episode, :visible, NOW())");
            $query->execute(array(
                'user_id' => 1,
                'series_id' => $data['series_id'],
                'title' => $data['title'],
                'season' => $data['season'],
                'episode' => $data['episode'],
                'visible' => 1
            ));
            $episode_id = $db->lastInsertId();
            insertFiles($files, $episode_id);
        }
	}
	echo 'Odcinki zostały dodane.';
}
?>
