<?php

namespace ArangoRbac;

class Permissions extends Entity {
    const COLLECTION_NAME   = 'Permission';
    const HAS               = 'HasPermission';
    const OWNED_BY          = 'Role';
    const OWNS              = null;
    const INHERITS_FROM     = 'InheritsPermission';
    const VIEW_NAME         = 'v_permissions';
    protected $connection;

    public function __construct(\ArangoDBClient\Connection $connection) {
        parent::__construct($connection);
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
            $edgesToUpdateQuery = "WITH @@collection
                                    \nFOR d, e IN 1..100000 INBOUND
                                    \nDOCUMENT(@_id)
                                    \n@@edgeCollection
                                    \nRETURN { 'document': d, 'edge': e }";
            $edgesToUpdateStatement = new \ArangoDBClient\Statement($this->connection, []);
            $edgesToUpdateStatement->setQuery($edgesToUpdateQuery);
            $edgesToUpdateStatement->bind('@collection', static::COLLECTION_NAME);
            $edgesToUpdateStatement->bind('_id', $id);
            $edgesToUpdateStatement->bind('@edgeCollection', static::INHERITS_FROM);
            $edgesToUpdateResult = $edgesToUpdateStatement->execute();
            $edgesToUpdate = $edgesToUpdateResult->getAll();

            foreach($edgesToUpdate as $documentEdgePair) {
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
     * @param string|int $permission Title|Path|ID
     * @param bool (optional) $onlyIDs
     * @return null|array of [$roleID, $Title, $Path]|$roleID|null
     */
    public function roles($permission, $onlyIDs = true) {
        $ownedById = $this->returnId($permission, 'Role');
        $ownedById = $ownedById ? $ownedById : $permission;
        
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        $query      = "WITH @@collection, @@ownedCollection
                        \nFOR d, e IN 0..1000000 INBOUND
                            \n@_id
                            \n@@edgeCollection, OUTBOUND @@inheritsCollection
                            \nFILTER IS_DOCUMENT(d) AND IS_SAME_COLLECTION(@ownedBy, d)
                            \nRETURN d";
        $statement->setQuery($query);
        $statement->bind('@collection', static::COLLECTION_NAME);
        $statement->bind('@ownedCollection', static::OWNED_BY);
        $statement->bind('ownedBy', static::OWNED_BY);
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
     * @return int $count - total number of roles unassigned to this permission
     */
    public function unassignRoles($id, $returnDocs = false) {
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        $query      = "WITH @@ownedCollection
            \nFOR d, e IN 1..1 INBOUND
                \n@_id
                \n@@edgeCollection
                \nREMOVE d IN @@ownedCollection
                \nRETURN d";
        $statement->setQuery($query);
        $statement->bind('@ownedCollection', static::OWNED_BY);
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
}