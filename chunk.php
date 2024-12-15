<?php

header("Connection: keep-alive");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Expose-Headers: Content-Length, Content-Range");
header("Access-Control-Allow-Headers: Range");
header("Accept-Ranges: bytes");

$ts = $_GET['ts'];

$headers = [
    'Accept: image/gif, image/x-bitmap, image/jpeg, image/pjpeg, text/html, application/xhtml+xml',
    'Cookie: *ga=GA1.2.549903363.1545240628; *gid=GA1.2.82939664.1545240628',
    'Connection: Keep-Alive',
    'Content-type: application/x-www-form-urlencoded;charset=UTF-8'
];

$userAgent = 'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; .NET CLR 1.0.3705; .NET CLR 1.1.4322; Media Center PC 4.0)';

$curl = curl_init($ts);
curl_setopt_array($curl, [
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_HEADER => false,
    CURLOPT_USERAGENT => $userAgent,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true
]);

$response = curl_exec($curl);
curl_close($curl);
echo $response;
?>