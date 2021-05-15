<?php

namespace ArangoRbac;

class Roles extends Entity {
    const COLLECTION_NAME   = 'Role';
    const HAS               = 'HasPermission';
    const OWNED_BY          = 'User';
    const OWNS              = 'Permission';
    const INHERITS_FROM     = 'InheritsRole';
    const VIEW_NAME         = 'v_roles';
    protected $connection;

    public function __construct(\ArangoDBClient\Connection $connection) {
        parent::__construct($connection);
    }

    /**
     * @param string $role
     * @param string $permission
     * @return bool $hasPermission ?
     */
    public function hasPermission($role, $permission) {
        $ownedById = $this->returnId($role, static::COLLECTION_NAME);
        $ownedById = $ownedById ? $ownedById : $role;

        $ownedId = $this->returnId($permission, static::OWNS);
        $ownedId = $ownedId ? $ownedId : $permission;
        
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        $query      = "WITH @@collection, @@ownedCollection
                        \nFOR d
                        \nIN OUTBOUND SHORTEST_PATH
                            \n@v1 TO @v2
                            \n@@edgeCollection, INBOUND @@inheritsCollection
                        \nRETURN d";
        $statement->setQuery($query);
        $statement->bind('@collection', static::COLLECTION_NAME);
        $statement->bind('@ownedCollection',static::OWNS);
        $statement->bind('v1', $ownedById);
        $statement->bind('v2', $ownedId);
        $statement->bind('@edgeCollection', static::HAS);
        $statement->bind('@inheritsCollection', static::INHERITS_FROM);
        $result = $statement->execute();
        $response = $result->getAll();

        $hasPermission = count($response) > 0;

        return $hasPermission;
    }

    /**
     * @param string|int $id
     * @param bool (optional) $recursive = false
     * @return bool $success
     */
    public function remove($id, $recursive = false) {
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        if(true === $recursive) {
            $id = $this->parentNode($id)['id'];
            $query      = "WITH @@collection
                            \nFOR d, e IN 1..100000 INBOUND
                            \nDOCUMENT(@_id)
                            \n@@edgeCollection
                            \nREMOVE d IN @@collection
                            \nREMOVE e IN @@edgeCollection
                            \nRETURN d";
            $statement->setQuery($query);
            $statement->bind('@collection', static::COLLECTION_NAME);
            $statement->bind('_id', $id);
            $statement->bind('@edgeCollection', static::INHERITS_FROM);
        } else {
            $parentId = $this->parentNode($id)['id'];
            $removedTitle = $this->getTitle($id);
            $pathsToUpdateQuery = "WITH @@collection
                                    \nFOR d, e IN 1..100000 INBOUND
                                    \nDOCUMENT(@_id)
                                    \n@@edgeCollection
                                    \nRETURN { 'document': d, 'edge': e }";
            $pathsToUpdateStatement = new \ArangoDBClient\Statement($this->connection, []);
            $pathsToUpdateStatement->setQuery($pathsToUpdateQuery);
            $pathsToUpdateStatement->bind('@collection', static::COLLECTION_NAME);
            $pathsToUpdateStatement->bind('_id', $id);
            $pathsToUpdateStatement->bind('@edgeCollection', static::INHERITS_FROM);
            $pathsToUpdateResult = $pathsToUpdateStatement->execute();
            $pathsToUpdate = $pathsToUpdateResult->getAll();

            foreach($pathsToUpdate as $documentEdgePair) {
                $document = $documentEdgePair->get('document');
                $thisId = $document['_id'];
                $currentPath = $document['path'];
                $editedPath = str_replace("/$removedTitle/", '/', $currentPath);
                $this->edit($thisId, '', '', $editedPath);

                $edge = $documentEdgePair->get('edge');
                // If edge points to main ID, update it
                $destination = $edge['_to'];
                if($destination === $id) {
                    if(false === $parentId) {
                        $this->edgeHandler->removeById(static::INHERITS_FROM, $edge['_id']);
                    } else {
                        $edge['_to'] = $parentId;
                        $this->edgeHandler->updateById(static::INHERITS_FROM, $edge['_id'], \ArangoDBClient\Edge::createFromArray($edge));
                    }
                }
            }

            $query      = "FOR d IN @@collection
                            \nFILTER d._id == @_id
                            \nREMOVE d IN @@collection
                            \nRETURN d";
            $statement->setQuery($query);
            $statement->bind('@collection', static::COLLECTION_NAME);
            $statement->bind('_id', $id);
        }

        $result = $statement->execute();
        $response = $result->getAll();
        return count($response) > 0;
    }

    /**
     * @param string|int $role Title|Path|ID
     * @param bool (optional) $onlyIDs
     * @return null|array of [$permissionID, $Title, $Path]|$permissionID
     */
    public function permissions($role, $onlyIDs = true) {
        $ownedById = $this->returnId($role, 'Role');
        $ownedById = $ownedById ? $ownedById : $role;
        
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        $query      = "WITH @@collection, @@ownedCollection
                        \nFOR d, e IN 0..1000000 OUTBOUND
                            \n@_id
                            \nINBOUND @@inheritsCollection, @@edgeCollection
                            \nFILTER IS_DOCUMENT(d) AND IS_SAME_COLLECTION(@owned, d)
                            \nRETURN d";
        $statement->setQuery($query);
        $statement->bind('@collection', static::COLLECTION_NAME);
        $statement->bind('@ownedCollection', static::OWNS);
        $statement->bind('owned', static::OWNS);
        $statement->bind('_id', $ownedById);
        $statement->bind('@edgeCollection', static::HAS);
        $statement->bind('@inheritsCollection', static::INHERITS_FROM);
        $result = $statement->execute();
        $response = $result->getAll();

        if($onlyIDs === true) {
            $response = array_map(
                function($doc) {
                    return $doc->getId();
                },
                $response
            );
        }

        return $response;
    }

    /**
     * @param string|int $id
     * @param bool (optional) $returnDocs
     * @return int $count - total number of permissions unassigned to this role
     */
    public function unassignPermissions($id, $returnDocs = false) {
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        $query      = "WITH @@ownedCollection
            \nFOR d, e IN 1..1 OUTBOUND
                \n@_id
                \n@@edgeCollection
                \nREMOVE d IN @@ownedCollection
                \nRETURN d";
        $statement->setQuery($query);
        $statement->bind('@ownedCollection', static::OWNS);
        $statement->bind('_id', $id);
        $statement->bind('@edgeCollection', static::HAS);
        $result = $statement->execute();
        $response = $result->getAll();

        if(false === $returnDocs) {
            return count($response);
        } else {
            return $response;
        }

    }

    /**
     * @param string|int $id
     * @return int $count - total number of users unassigned to this role
     */
    public function unassignUsers($id) {
            // TODO: complete users class first
    }
}