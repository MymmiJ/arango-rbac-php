<?php

// DO NOT RUN THIS FILE IF CONNECTED TO PRODUCTION DATABASE

namespace ArangoDBClient;

use ArangoRbac\Rbac;

require __DIR__ . '/init.php';

$n = 100;

try {
    unset($connectionOptions[ConnectionOptions::OPTION_TRACE]);

    $connection        = new Connection($connectionOptions);
    $collectionHandler = new CollectionHandler($connection);
    $handler           = new DocumentHandler($connection);

    $rbac = new Rbac($connection);

    try {
        $collectionHandler->drop('Role');
        $collectionHandler->drop('User');
        $collectionHandler->drop('InheritsRole');
        $collectionHandler->drop('Permission');
        $collectionHandler->drop('HasPermission');
        $collectionHandler->drop('InheritsPermission');
        $collectionHandler->drop('HasRole');
        $collectionHandler->drop('InheritsUser');
    } catch (\Exception $e) {
        // meh
    }

    $roles = new Collection('Role');
    $users = new Collection('User');
    $permission = new Collection('Permission');
    $inheritsCollection = new EdgeCollection('InheritsRole');
    $hasPermissions = new EdgeCollection('HasPermission');
    $inheritsPermission = new EdgeCollection('InheritsPermission');
    $hasRoles = new EdgeCollection('HasRole');
    $inheritsUsers = new EdgeCollection('InheritsUser');
    $collectionHandler->create($roles);
    $collectionHandler->create($users);
    $collectionHandler->create($permission);
    $collectionHandler->create($inheritsCollection);
    $collectionHandler->create($inheritsPermission);
    $collectionHandler->create($hasPermissions);
    $collectionHandler->create($hasRoles);
    $collectionHandler->create($inheritsUsers);

    echo "creating preset documents" . PHP_EOL;

    $docCount = $rbac->Roles->addPath('/techradar_administrator/techradar_editor/techradar_contributor');
    echo "got here";
    $role = $rbac->Roles->returnId('/techradar_administrator/techradar_editor/techradar_contributor/');
    $permission = $rbac->Permissions->add("GET.techradar.ArticleController.searchAction", "Search TechRadar");
    $assignOne = json_encode($rbac->Permissions->assign($role, $permission));
    $assignTwo = json_encode($rbac->Roles->assign($role, $permission));

    echo "Assign one: $assignOne" . PHP_EOL . PHP_EOL;
    echo "Assign two: $assignTwo" . PHP_EOL . PHP_EOL;

    $hasPermissionBool = json_encode($rbac->Roles->hasPermission($role, $permission));
    echo "Permission Assigned properly: $hasPermissionBool". PHP_EOL . PHP_EOL;

    $parentRole = $rbac->Roles->returnId('/techradar_administrator/techradar_editor/');
    $hasPermissionParent = json_encode($rbac->Roles->hasPermission($parentRole, $permission));

    echo "Parent Permission Detected properly: $hasPermissionParent". PHP_EOL . PHP_EOL;

    $rbac->Roles->addPath('/techradar_administrator/techradar_editor/techradar_contributor/techradar_lowly_peon');
    $childRole = $rbac->Roles->returnId('/techradar_administrator/techradar_editor/techradar_contributor/techradar_lowly_peon/');

    $hasPermissionChild = json_encode($rbac->Roles->hasPermission($childRole, $permission));
    echo "Child Permission should be false, is: $hasPermissionChild" . PHP_EOL . PHP_EOL . PHP_EOL;

    $children = json_encode($rbac->Roles->children($rbac->Roles->returnId('root')));
    echo "Children: $children" . PHP_EOL . PHP_EOL;

    $descendants = json_encode($rbac->Roles->descendants($rbac->Roles->returnId('root')));
    echo "Descendants: $descendants" . PHP_EOL . PHP_EOL;

    echo "Preset Document Count: $docCount" . PHP_EOL . PHP_EOL;

    $permCount = $rbac->Permissions->count();
    echo "Permissions count: $permCount" . PHP_EOL . PHP_EOL;

    $depth = $rbac->Roles->depth($rbac->Roles->returnId('techradar_contributor'));
    echo "Depth: $depth" . PHP_EOL . PHP_EOL;

    $parent = json_encode($rbac->Roles->parentNode($rbac->Roles->returnId('techradar_contributor')));

    echo "Parent: $parent" . PHP_EOL . PHP_EOL;

    $rbac->Roles->remove($rbac->Roles->returnId('techradar_editor'));

    $allPermissions = json_encode($rbac->Roles->Permissions('techradar_administrator'));
    echo "Getting all permissions for root: $allPermissions" . PHP_EOL . PHP_EOL;
    
    $time = microtime(true);

    echo 'preset documents took ' . (microtime(true) - $time) . ' s' . PHP_EOL;

    echo "creating $n documents" . PHP_EOL;
    $time = microtime(true);

    // Testing basic functionality
    // for ($i = 0; $i < $n; ++$i) {
    //     $title = "role$i";
    //     $description = "role$i description";

    //     $rbac->Roles->add($title, $description);
    // }

    // for($i = $n; $i < 2*$n; ++$i) {

    //     $parentI = $i - $n;
    //     $title = "role$i";
    //     $description = "role$i description";
    //     $parentId = $rbac->Roles->returnId("role$parentI");
    //     $rbac->Roles->add($title, $description, $parentId);

    //     echo 'Role: ' . json_encode($rbac->Roles->returnId($title)) . PHP_EOL;

    //     echo 'Parent: ' . json_encode($rbac->Roles->returnId("role$parentI")) . PHP_EOL;
    // }

    echo $n . ' documents took ' . (microtime(true) - $time) . ' s' . PHP_EOL;

} catch (ConnectException $e) {
    print $e . PHP_EOL;
} catch (ServerException $e) {
    print $e . PHP_EOL;
} catch (ClientException $e) {
    print $e . PHP_EOL;
}
