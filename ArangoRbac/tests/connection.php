<?php

namespace ArangoDBClient;

require __DIR__ . '/init.php';

$n = 100;

try {
    unset($connectionOptions[ConnectionOptions::OPTION_TRACE]);

    $connection        = new Connection($connectionOptions);
    $collectionHandler = new CollectionHandler($connection);
    $handler           = new DocumentHandler($connection);

    try {
        $collectionHandler->drop('test');
    } catch (\Exception $e) {
        // meh
    }

    $collection = new Collection('test');
    $collectionHandler->create($collection);

    echo "creating $n documents" . PHP_EOL;
    $time = microtime(true);

    // Testing basic functionality
    for ($i = 0; $i < $n; ++$i) {
        $document = new Document(['value' => 'test' . $i]);

        $handler->save('test', $document);
    }

    echo 'took ' . (microtime(true) - $time) . ' s' . PHP_EOL;

} catch (ConnectException $e) {
    print $e . PHP_EOL;
} catch (ServerException $e) {
    print $e . PHP_EOL;
} catch (ClientException $e) {
    print $e . PHP_EOL;
}