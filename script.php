<?php
/**
 * @package StalkerPortalPlaylistExtractor
 * @version 1.2.0
 * @author @its_akshay08
 * @license Proprietary
 */

// Configuration Management
class StalkerPortalConfig {
    private static $instance = null;
    private $configData = [];

    private function __construct() {
        $this->loadConfiguration();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadConfiguration() {
        $this->configData = [
            'host' => "iptv.initv.de",
            'mac' => "00:1A:79:F9:31:90",
            'deviceid' => "5FBA53B26E1370DEBE36159527DEEA068130BE4D04DE9087F42A4295DF1796FF",
            'deviceid2' => "119BCECBC7B11A92CB5030297C5F31C53EBD4B2377F951912D067D7C6796ECE9",
            'serial' => "52FB09EAABCF9",
            'sig' => "",
            'portal_url' => ''
        ];
        $this->configData['portal_url'] = "http://{$this->configData['host']}/stalker_portal/c/";
    }

    public function get($key) {
        return $this->configData[$key] ?? null;
    }
}

// Logging Utility
class PlaylistLogger {
    private static $logFile = 'playlist_generator.log';

    public static function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        file_put_contents(self::$logFile, $logEntry, FILE_APPEND);
    }
}

// Advanced HTTP Client
class StalkerPortalHttpClient {
    private $config;
    private $curlOptions = [];

    public function __construct(StalkerPortalConfig $config) {
        $this->config = $config;
        $this->initializeCurlDefaults();
    }

    private function initializeCurlDefaults() {
        $this->curlOptions = [
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ];
    }

    public function executeRequest($url, $headers = [], $method = 'GET') {
        try {
            $ch = curl_init($url);
            
            foreach ($this->curlOptions as $option => $value) {
                curl_setopt($ch, $option, $value);
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 (KHTML, like Gecko) MAG200 stbapp ver: 2 rev: 250 Safari/533.3');
            
            $response = curl_exec($ch);
            
            if ($response === false) {
                throw new Exception("Curl Error: " . curl_error($ch));
            }
            
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return [
                'response' => $response,
                'http_code' => $httpCode
            ];
        } catch (Exception $e) {
            PlaylistLogger::log($e->getMessage(), 'ERROR');
            return null;
        }
    }
}

// Main Playlist Generator
class StalkerPortalPlaylistGenerator {
    private $config;
    private $httpClient;
    private $handshakeToken;
    private $currentTimestamp;

    public function __construct() {
        $this->config = StalkerPortalConfig::getInstance();
        $this->httpClient = new StalkerPortalHttpClient($this->config);
        $this->currentTimestamp = time();
    }

    private function generateHeaders($token = null) {
        $host = $this->config->get('host');
        $mac = $this->config->get('mac');
        $portal_url = $this->config->get('portal_url');

        $baseHeaders = [
            "Cookie: mac=$mac; stb_lang=en; timezone=GMT",
            "Referer: $portal_url",
            "User-Agent: Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 (KHTML, like Gecko) MAG200 stbapp ver: 2 rev: 250 Safari/533.3",
            "X-User-Agent: Model: MAG254; Link:"
        ];

        if ($token) {
            $baseHeaders[] = "Authorization: Bearer $token";
            $baseHeaders[] = "X-User-Agent: Model: MAG250; Link:Wifi";
        }

        return $baseHeaders;
    }

    public function generatePlaylist($base_url) {
        try {
            $handshakeResult = $this->performHandshake();
            $profileResult = $this->getProfile($handshakeResult['real']);
            $channelsResult = $this->getAllChannels($handshakeResult['real']);
            $genresResult = $this->getGenres($handshakeResult['real']);

            if (!$channelsResult || !$genresResult) {
                throw new Exception("Failed to retrieve channel or genre information");
            }

            $m3uPlaylist = $this->constructM3uPlaylist($channelsResult,$genresResult,$base_url);

            $this->savePlaylist($m3uPlaylist);
        } catch (Exception $e) {
            PlaylistLogger::log($e->getMessage(), 'CRITICAL');
            echo "An error occurred: " . $e->getMessage();
        }
    }

    private function performHandshake() {
        $host = $this->config->get('host');
        $url = "http://{$host}/stalker_portal/server/load.php?type=stb&action=handshake&prehash=false&JsHttpRequest=1-xml";
        $headers = $this->generateHeaders();
        
        $result = $this->httpClient->executeRequest($url, $headers);
        $response = json_decode($result['response'], true);

        return [
            'token' => $response['js']['random'],
            'real' => $response['js']['token']
        ];
    }

    private function getProfile($token) {
        $host = $this->config->get('host');
        $serial = $this->config->get('serial');
        $deviceid = $this->config->get('deviceid');
        $deviceid2 = $this->config->get('deviceid2');
        $sig = $this->config->get('sig');

        $url = "http://{$host}/stalker_portal/server/load.php?type=stb&action=get_profile&hd=1&ver=ImageDescription: 0.2.18-r14-pub-250; ImageDate: Fri Jan 15 15:20:44 EET 2016; PORTAL version: 5.5.0; API Version: JS API version: 328; STB API version: 134; Player Engine version: 0x566&num_banks=2&sn={$serial}&stb_type=MAG254&image_version=218&video_out=hdmi&device_id={$deviceid}&device_id2={$deviceid2}&signature={$sig}&auth_second_step=1&hw_version=1.7-BD-00&not_valid_token=0&client_type=STB&hw_version_2=7c431b0aec69b2f0194c0680c32fe4e3&timestamp={$this->currentTimestamp}&api_signature=263&metrics={\"mac\":\"{$this->config->get('mac')}\",\"sn\":\"{$serial}\",\"model\":\"MAG254\",\"type\":\"STB\",\"uid\":\"{$deviceid}\"}&JsHttpRequest=1-xml";
        
        $headers = $this->generateHeaders($token);
        $result = $this->httpClient->executeRequest($url, $headers);

        return $result['response'];
    }

    private function getAllChannels($token) {
        $host = $this->config->get('host');
        $url = "http://{$host}/stalker_portal/server/load.php?type=itv&action=get_all_channels";
        
        $headers = $this->generateHeaders($token);
        $result = $this->httpClient->executeRequest($url, $headers);

        return $result['response'];
    }

    private function getGenres($token) {
        $host = $this->config->get('host');
        $url = "http://{$host}/stalker_portal/server/load.php?type=itv&action=get_genres";
        
        $headers = $this->generateHeaders($token);
        $result = $this->httpClient->executeRequest($url, $headers);

        return $result['response'];
    }

    private function constructM3uPlaylist($channelInfoJson, $genreInfoJson, $base_script_url) {
        $channelData = json_decode($channelInfoJson, true);
        $genreData = json_decode($genreInfoJson, true);
        $host = $this->config->get('host');

        $playlist = "#EXTM3U\n\n";
        $baseLogoUrl = "http://$host/misc/logos/320/";

        foreach ($channelData['js']['data'] as $channel) {
            try {
                $channelName = $channel['name'];
                $cmdData = $channel['cmds'][0];
                $channeltvg = $channel['xmltv_id'];
                $channelId = $cmdData['id'];
                $tvGenreId = $channel['tv_genre_id'];
                $groupTitle = $this->getGroupTitle($tvGenreId, $genreData);

                $playlist .= "#EXTINF:-1 tvg-id=\"$channeltvg\" tvg-logo=\"$baseLogoUrl$channelId.jpg\" group-title=\"$groupTitle\",$channelName\n";
                
                $playlist .= "$base_script_url?id=$channelId\n\n";
                 
            } catch (Exception $e) {
                PlaylistLogger::log("Error processing channel $channelName: " . $e->getMessage(), 'WARNING');
            }
        }

        return $playlist;
    }

    private function getGroupTitle($tvGenreId, $genreData) {
        foreach ($genreData['js'] as $genre) {
            if ($genre['id'] == (string)$tvGenreId) {
                return str_replace("\\", "", $genre['title']);
            }
        }
      return $this->config->get('host'); // Return host if no genre found
    }

    private function savePlaylist($playlist) {
        $host = $this->config->get('host');
        $filename = "playlist ({$host}).m3u";
        file_put_contents($filename, $playlist);
        echo "M3U playlist generated and saved as <a href=\"{$filename}\">{$filename}</a>";
    }
}

// Error Handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    PlaylistLogger::log("Error [$errno]: $errstr in $errfile on line $errline", 'ERROR');
});

// Shutdown Handler
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
        PlaylistLogger::log("Fatal Error: " . print_r($error, true), 'FATAL');
    }
});


$currentDir = dirname(__FILE__);
$hosting_host = $_SERVER['HTTP_HOST'];
$scriptDir = str_replace($_SERVER['DOCUMENT_ROOT'], '', $currentDir);
$base_url = "https://" . $hosting_host . $scriptDir . '/stream.php';
// Main Execution
try {
    $generator = new StalkerPortalPlaylistGenerator();
    $generator->generatePlaylist($base_url);
} catch (Exception $e) {
    PlaylistLogger::log($e->getMessage(), 'FATAL');
    echo "An unexpected error occurred.";
}
//echo $base_url;
// Script by @its_akshay08
?>