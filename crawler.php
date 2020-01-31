<?php

set_time_limit(0);

// error_reporting(E_ALL);
// ini_set('display_errors', 1);

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

// Test:
// $_POST['from'] = 1;
// $_POST['to'] = 1;
$_POST['type'] = 1;

// Zakres:
if (empty($_POST['from']) || empty($_POST['to'])) exit();

$from = preg_replace('@[^0-9]@', '', $_POST['from']);
$to = preg_replace('@[^0-9]@', '', $_POST['to']);
$type = preg_replace('@[^0-9]@', '', $_POST['type']);

if (empty($from) || empty($to)) exit();

// Załącznie plików:
require_once '../../application/config/config.php';
require_once '../../application/libs/Custom/Filmweb.php';
require_once '../../application/libs/Custom/abeautifulsite/SimpleImage.php';

// $data = (new Libs\Custom\Filmweb('avatar', '2018'))->results();

// print_r($data);

// exit;

date_default_timezone_set(TIMEZONE);

$crawler = new Crawler($type);
file_put_contents('log.txt', null);
file_put_contents('log.txt', sprintf('Rozpoczęto dodawanie (%s) <br><br>', date('H:i:s')), FILE_APPEND);
for ($i = $from; $i <= $to; $i++) {

    $website = file_get_contents('http://filmy.to/filmy/' . $i);
    preg_match_all('@<td>.*?<a href="/film/(.*?)">@s', $website, $match);

    foreach ($match[1] as $key => $value) {
        $url = 'http://filmy.to/film/' . $value;
        try {
            $title = $crawler->parse($url);
            file_put_contents('log.txt', sprintf('<span style="color: green">' . $title . '</span> (%s) <br><br>', date('H:i:s')), FILE_APPEND);

        } catch (Exception $e) {
            var_dump($e->getMessage());
        }
    }
}
file_put_contents('log.txt', sprintf('Zakończono dodawanie (%s) <br><br>', date('H:i:s')), FILE_APPEND);


// $crawler->parse('http://filmy.to/film/Rekiny_wojny-2016,2833');

class Crawler
{

    private $db, $type, $id;

    public function __construct($type)
    {
        $options = array(
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'
        );
        $this->db = new Pdo(DB_TYPE . ':host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8', DB_USER, DB_PASS, $options);

        $this->type = $type;
    }

    public function parse($url)
    {
        $this->url = $url;

        $website = file_get_contents($this->url);

        preg_match('@<h3>(.*?)</h3>@', $website, $title);
        preg_match('@<h3>.*?</h3> / (.*?)<a.*?>@si', $website, $titleEng);
        preg_match('@<div id="plot">(.*?)</div>@s', $website, $description);
        preg_match('@<a href="/filmy/1\?rok_od=.*?&amp;rok_do=.*?">(.*?)</a>@s', $website, $year);

        if (isset($title[1]) && isset($description[1]) && isset($year[1])) {

            $files = $this->files($this->url);
            // $files = $this->files($website);

            $data = array(
                'title' => trim($title[1]),
                'description' => trim($description[1]),
                'year' => trim($year[1])
            );

            try {
                $desc = $data['description'];
                $data = (new Libs\Custom\Filmweb($data['title'], $data['year']))->results();
                if (!empty($data['youtube'])) {
                    preg_match("@(youtube.com|youtu.be)\/(watch)?(\?v=)?(\S+)?@", $data['youtube'], $match);
                    $data['trailer'] = $match[4];
                }
                if ($data['description'] == 'Ten film nie ma jeszcze zarysu fabuły.') {
                    $data['description'] = $desc;
                }
                // $data['description'] = str_replace('filmy.to', '', $data['description']);
            } catch (Exception $e) {
                throw new Exception('FILMWEB ERROR');
            }


            $this->save($data, $files);

            
            // print_r($data);
            // print_r($files);
            // exit;

            return $data['title'];
        } else {
            throw new Exception('PARSE ERROR');
        }
    }

    function insertFiles($files, $movie_id)
    {

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

        $query = $this->db->prepare("SELECT filmy_to_links FROM movie WHERE movie_id = ?");
        $query->execute(array($movie_id));

        $filmy_to_links = $query->fetch()['filmy_to_links'];

        if(empty($filmy_to_links)) {
            $filmy_to_links = [];
        } else {
            $filmy_to_links = unserialize($filmy_to_links);
        }

        foreach ($flat as $key => $value) {
            if(!in_array($value['file'], array_column($filmy_to_links, 'file'))) {
                $query = $this->db->prepare("INSERT INTO link (user_id, movie_id, domain, file, version, quality, created_date) VALUES (:user_id, :movie_id, :domain, :file, :version, :quality, NOW())");
                $query->execute(array(
                    'user_id' => 1,
                    'movie_id' => $movie_id,
                    'domain' => $value['domain'],
                    'file' => openloadRemoteUpload($value['file']),
                    'version' => $value['version'],
                    'quality' => $value['quality']
                ));

            }
        }

        $query = $this->db->prepare("UPDATE movie SET filmy_to_links = ? WHERE movie_id = ?");
        $query->execute(array(serialize($flat), $movie_id));
    }

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

        $result = [];

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

    public function save($data, $files)
    {

        if(empty($data['year'])) return false;

        $query = $this->db->prepare("SELECT movie_id, title FROM movie WHERE title = ? AND year = ?");
        $query->execute(array($data['title'], $data['year']));

        if ($query->rowCount() > 0) {

            $movie = $query->fetch();

            $movie_id = $movie['movie_id'];

            $this->insertFiles($files, $movie_id);
            

            return 'Aktualizacja: ' . $movie['title'];
        }

        $query = $this->db->prepare("INSERT INTO movie (user_id, title, year, description, trailer, filmweb, created_date) VALUES (:user_id, :title, :year, :description, :trailer, :filmweb, NOW())");
        $query->execute(array(
            'user_id' => 1,
            'title' => $data['title'],
            'year' => $data['year'],
            'description' => str_replace('Filmy.to', 'kinostar.tv', str_replace('filmy.to', 'kinostar.tv', $data['description'])),
            'trailer' => !empty($data['trailer']) ? $data['trailer'] : '',
            'filmweb' => !empty($data['id']) ? $data['id'] : 0
        ));
        $movie_id = $this->db->lastInsertId();

        if ($movie_id) {
            if($data['poster'] == 'http://2.fwcdn.pl/gf/beta/ic/plugs/v01/fbPlug.jpg') {
                $data['poster'] = URL . 'public/dist/images/default.jpg';
            }
            $img = new Libs\Custom\abeautifulsite\SimpleImage($data['poster']);
            $img->thumbnail(250, 370)->save('../../public/static/poster/big/' . $movie_id . '.jpg');
            $img->thumbnail(100, 100)->save('../../public/static/poster/thumb/' . $movie_id . '.jpg');

            // Kategoria:
            foreach ($data['category'] as $key => $value) {
                $query = $this->db->prepare("SELECT category_id FROM category WHERE name = ?");
                $query->execute(array($value));
                $category_id = $query->fetch()['category_id'];

                if (empty($category_id)) {
                    $query = $this->db->prepare("INSERT INTO category (name) VALUES (:name)");
                    $query->execute(array(
                        'name' => $value
                    ));
                    $category_id = $this->db->lastInsertId();
                }

                $query = $this->db->prepare("INSERT INTO movie_to_category (movie_id, category_id) VALUES (:movie_id, :category_id)");
                $query->execute(array(
                    'movie_id' => $movie_id,
                    'category_id' => $category_id
                ));
            }

            // File:
            $this->insertFiles($files, $movie_id);

            // $query = $this->db->prepare("SELECT link_id FROM link WHERE domain = ? AND file = ?");
            // $query->execute(array('openload.co', $file));
            // if ($query->rowCount() == 0) {

            //     $query = $this->db->prepare("SELECT remote FROM movie WHERE movie_id = ?");
            //     $query->execute(array($movie_id));

            //     if ($query->fetch()['remote'] < 3) {

            //         $query = $this->db->prepare("INSERT INTO link (user_id, movie_id, domain, file, version, quality, created_date) VALUES (:user_id, :movie_id, :domain, :file, :version, :quality, NOW())");
            //         $query->execute(array(
            //             'user_id' => 1,
            //             'movie_id' => $movie_id,
            //             'domain' => 'openload.co',
            //             'file' => $file,
            //             'version' => $this->version,
            //             'quality' => 2
            //         ));
                    
            //         $query = $this->db->prepare("UPDATE movie SET remote = remote + 1 WHERE movie_id = ?");
            //         $query->execute(array($movie_id));
            //     }
            // }

            // Full:

            // Zdjęcie:
            if (!empty($data['photos'])) {
                $img = new Libs\Custom\abeautifulsite\SimpleImage($data['photos']);
                $img->thumbnail(1000, 300, false)->save('../../public/static/photo/' . $movie_id . '.jpg');
            }
            // Osoby:
            if (!empty(explode(',', $data['direction'])[0])) {
                foreach (explode(',', $data['direction']) as $key => $value) {
                    $query = $this->db->prepare("SELECT person_id FROM person WHERE name = ?");
                    $query->execute(array($value));

                    $query = $this->db->prepare("INSERT INTO movie_to_person (movie_id, person_id, type) VALUES (:movie_id, :person_id, :type)");
                    $query->execute(array(
                        'movie_id' => $movie_id,
                        'person_id' => $this->checkPerson($value),
                        'type' => 1
                    ));
                }
            }
            if (!empty(explode(',', $data['screenplay'])[0])) {
                foreach (explode(',', $data['screenplay']) as $key => $value) {
                    $query = $this->db->prepare("SELECT person_id FROM person WHERE name = ?");
                    $query->execute(array($value));

                    $query = $this->db->prepare("INSERT INTO movie_to_person (movie_id, person_id, type) VALUES (:movie_id, :person_id, :type)");
                    $query->execute(array(
                        'movie_id' => $movie_id,
                        'person_id' => $this->checkPerson($value),
                        'type' => 2
                    ));
                }
            }
            if (!empty(explode(',', $data['cast'])[0])) {
                foreach (explode(',', $data['cast']) as $key => $value) {
                    $query = $this->db->prepare("SELECT person_id FROM person WHERE name = ?");
                    $query->execute(array($value));

                    $query = $this->db->prepare("INSERT INTO movie_to_person (movie_id, person_id, type) VALUES (:movie_id, :person_id, :type)");
                    $query->execute(array(
                        'movie_id' => $movie_id,
                        'person_id' => $this->checkPerson($value),
                        'type' => 3
                    ));
                }
            }
            // Kraj:
            if (!empty(explode(',', $data['country'])[0])) {
                foreach (explode(',', $data['country']) as $key => $value) {
                    $query = $this->db->prepare("SELECT country_id FROM country WHERE name = ?");
                    $query->execute(array($value));

                    $query = $this->db->prepare("INSERT INTO movie_to_country (movie_id, country_id) VALUES (:movie_id, :country_id)");
                    $query->execute(array(
                        'movie_id' => $movie_id,
                        'country_id' => $this->checkCountry($value)
                    ));
                }
            }
        }

    }

    public function checkPerson($name)
    {
        $query = $this->db->prepare("SELECT person_id FROM person WHERE name = ?");
        $query->execute(array($name));

        if ($query->rowCount() == 0) {
            $query = $this->db->prepare("INSERT INTO person (name) VALUES (:name)");
            $query->execute(array(
                ':name' => $name
            ));
            $person_id = $this->db->lastInsertId();
        } else {
            $person_id = $query->fetch()['person_id'];
        }
        return $person_id;
    }

    public function checkCountry($name)
    {
        $query = $this->db->prepare("SELECT country_id FROM country WHERE name = ?");
        $query->execute(array($name));

        if ($query->rowCount() == 0) {
            $query = $this->db->prepare("INSERT INTO country (name) VALUES (:name)");
            $query->execute(array(
                ':name' => $name
            ));
            $country_id = $this->db->lastInsertId();
        } else {
            $country_id = $query->fetch()['country_id'];
        }
        return $country_id;
    }

}