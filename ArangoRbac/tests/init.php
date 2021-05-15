<?php

namespace ArangoDBClient;

// Require autoload
require dirname(dirname(__DIR__)) . '/vendor/autoload.php';

/* set up a trace function that will be called for each communication with the server */
$traceFunc = function ($type, $data) {
    print 'TRACE FOR ' . $type . PHP_EOL;
    var_dump($data);
};

/* set up connection options */
$connectionOptions = [
    ConnectionOptions::OPTION_DATABASE => '_system',               // database name

    // normal unencrypted connection via TCP/IP
    ConnectionOptions::OPTION_ENDPOINT => 'tcp://localhost:8529',  // endpoint to connect to

    ConnectionOptions::OPTION_CONNECTION  => 'Keep-Alive',
    ConnectionOptions::OPTION_AUTH_TYPE   => 'Basic',

    // authentication parameters (note: must also start server with option `--server.disable-authentication false`)
    ConnectionOptions::OPTION_AUTH_USER   => 'root',
    ConnectionOptions::OPTION_AUTH_PASSWD => '',

    ConnectionOptions::OPTION_TIMEOUT       => 30,
    ConnectionOptions::OPTION_TRACE         => $traceFunc,
    ConnectionOptions::OPTION_CREATE        => false,
    ConnectionOptions::OPTION_UPDATE_POLICY => UpdatePolicy::LAST,
];
