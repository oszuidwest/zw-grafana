<?php

// Configuration
$icecastURL = "https://icecast.zuidwestfm.nl";
$host = "icecastzwfm";
$timestamp = microtime(true) * 1000;
$icecastUsername = "admin";
$icecastPassword = "";
$influxDBURL = "http://localhost:8086/api/v2/write";
$influxDBToken = "";
$influxDBOrg = "";
$influxDBBucket = "";
$influxDBPrecision = "ms";

// SourceListmounts class holds single listener elements from Icecast XML
class SourceListmounts {
    public $mount;
    public $listeners;
    public $connected;
    public $contentType;

    public function __construct($mount, $listeners, $connected, $contentType) {
        $this->mount = $mount;
        $this->listeners = $listeners;
        $this->connected = $connected;
        $this->contentType = $contentType;
    }
}

// Listmounts class represents the main structure of Icecast XML
class Listmounts {
    public $sources = [];

    public function __construct($sources) {
        $this->sources = $sources;
    }
}

function main($icecastURL, $host, $timestamp, $icecastUsername, $icecastPassword, $influxDBURL, $influxDBToken, $influxDBOrg, $influxDBBucket, $influxDBPrecision) {
    // Execute the checkIcecastListmounts function
    $buffer = checkIcecastListmounts($icecastURL, $host, $timestamp, $icecastUsername, $icecastPassword, $influxDBURL, $influxDBToken, $influxDBOrg, $influxDBBucket, $influxDBPrecision);

    // Print the buffer content
    if ($buffer) {
        echo "Received buffer to send to InfluxDB:\n";
        echo $buffer;
    }
}

function checkIcecastListmounts($icecastURL, $host, $timestamp, $icecastUsername, $icecastPassword, $influxDBURL, $influxDBToken, $influxDBOrg, $influxDBBucket, $influxDBPrecision) {
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => $icecastURL . '/admin/listmounts',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $icecastUsername . ':' . $icecastPassword,
    ]);

    $response = curl_exec($curl);
    $httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $startTime = microtime(true);

    curl_close($curl);

    if ($httpStatus != 200) {
        echo "Error: Received HTTP status $httpStatus\n";
        return null;
    }

    $xml = simplexml_load_string($response);
    if ($xml === false) {
        echo "Error: Failed to parse XML\n";
        return null;
    }

    $buffer = "";

    $buffer .= sprintf("response,host=%s value=%f %d\n", $host, (microtime(true) - $startTime) * 1000, $timestamp);

    $total = 0;
    foreach ($xml->source as $source) {
        $mount = (string)$source['mount'];
        $listeners = (int)$source->listeners;

        $buffer .= sprintf("listeners,host=%s,mount=%s value=%di %d\n", $host, $mount, $listeners, $timestamp);

        // Prepare data for InfluxDB
        $point = sprintf("listeners,host=%s,mount=%s value=%di %d", $host, $mount, $listeners, $timestamp);
        sendToInfluxDB($point, $influxDBURL, $influxDBToken, $influxDBOrg, $influxDBBucket, $influxDBPrecision);

        $total += $listeners;
    }

    // Write total listeners count to InfluxDB
    $pointTotal = sprintf("listenerstotal,host=%s value=%di %d", $host, $total, $timestamp);
    sendToInfluxDB($pointTotal, $influxDBURL, $influxDBToken, $influxDBOrg, $influxDBBucket, $influxDBPrecision);

    return $buffer;
}

function sendToInfluxDB($point, $influxDBURL, $influxDBToken, $influxDBOrg, $influxDBBucket, $influxDBPrecision) {
    $url = "$influxDBURL?org=$influxDBOrg&bucket=$influxDBBucket&precision=$influxDBPrecision";

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Token $influxDBToken",
            "Content-Type: text/plain; charset=utf-8"
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $point,
    ]);

    $response = curl_exec($curl);
    $httpStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    if ($httpStatus != 204) {
        echo "Error: Failed to write to InfluxDB, HTTP status $httpStatus\n";
        echo "Response: $response\n";
    }
}

// Call the main function
main($icecastURL, $host, $timestamp, $icecastUsername, $icecastPassword, $influxDBURL, $influxDBToken, $influxDBOrg, $influxDBBucket, $influxDBPrecision);

?>
