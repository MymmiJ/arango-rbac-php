<?php

namespace ArangoRbac;

class CreateDocWithParentException extends \Exception {};
class AmbiguousIDRequestException extends \Exception {};
class ResetException extends \Exception {};

abstract class Entity {
    protected $connection;
    const COLLECTION_NAME   = 'UnspecifiedEntity';
    const HAS               = 'Has';
    const OWNED_BY          = 'UnspecifiedEntityParent';
    const OWNS              = null;
    const INHERITS_FROM     = 'InheritsUnspecifiedEntity';
    const VIEW_NAME         = 'v_unspecified_entities';

    public function __construct(\ArangoDBClient\Connection $connection) {
        $this->connection = $connection;
        $this->documentHandler = new \ArangoDBClient\DocumentHandler($this->connection);
        $this->edgeHandler = new \ArangoDBClient\EdgeHandler($this->connection);
    }

    /**
     * @param string $title Title
     * @param string $description Description
     * @param string|int|null (optional) $parentId = null Parent ID
     * @param array (optional) $additionalFeatures = [] extra features of the entity
     * @return string|int $id
     */
    public function add($title, $description, $parentId = null, $additionalFeatures = []) {
        if(!isset($parentId)) {
            $parentId = $this->returnId('root');
        }
        if(!empty($parentId)) {
            $parent = $this->documentHandler->getById(static::COLLECTION_NAME, $parentId);
            $document = \ArangoDBClient\Document::createFromArray([
                'title' => $title,
                'description' => $description,
                'path' => $parent->get('path') . $title . '/'
            ] + $additionalFeatures);
        } else {
            $document = \ArangoDBClient\Document::createFromArray([
                'title' => $title,
                'description' => $description,
                'path' => "/root/"
            ] + $additionalFeatures);
        }

        $this->documentHandler->save(static::COLLECTION_NAME, $document);
        if(isset($parentId) && !empty($parentId)) {
            try {
                $inheritsFrom = new \ArangoDBClient\Edge();
                // This goes 'the wrong way round' because a 'parent' actually inherits from their child in RBAC
                // It is a parent node rather than a parent in the sense of inheritance.
                $inheritsFrom->setFrom($document->getId());
                $inheritsFrom->setTo($parentId);
                $this->edgeHandler->saveEdge(static::INHERITS_FROM, $document->getId(), $parentId, $inheritsFrom);
            } catch(\Exception $e) {
                // ArangoDB v 3.4.5 does not support transactions on clusters, therefore we manually roll back
                $name = static::COLLECTION_NAME;
                $this->documentHandler->remove($document);
                throw new CreateDocWithParentException("Error attempting to add new document:\n\n Failed to save edge to $name parent $parentId \n\n Error Message: {$e->getMessage()}. Check that $parentId exists.");
            }
        }

        return $document->getId();
    }
    
    /**
     * @param string $path Path e.g. '/techradar_administrator/techradar_editor/'
     * @param array (optional) $descriptions = [] optional Descriptions
     * @return int $count Number of entities created
     */
    public function addPath($path, $descriptions = []) {
        $path = '/root' . $path;
        $explodedPath = explode('/', trim($path,'/'));
        $currentPath = '/';
        $count = -1;
        $parent = null;
        foreach($explodedPath as $segment) {
            $exists = $this->titleId($segment);
            $currentPath .= "$segment/";
            if($exists === null) {
                // create new
                $description = isset($descriptions[$count]) ? $descriptions[$count] : $segment;
                $this->add($segment, $description, $parent);
                $parent = $this->titleId($segment);
            } else {
                // modify existing
                $description = isset($descriptions[$count]) ? $descriptions[$count] : '';
                $this->edit($exists, '', '', $currentPath);
                $parent = $exists;
            }

            ++$count;
        }

        return $count;
    }

    /**
     * Assign a permission to a role
     * @param string|int $role Title|Path|ID
     * @param string|int $permission Title|Path|ID
     * @param bool (optional) $returnId bool - return ID or simple success result
     * @return bool|string $success|$id
     */
    public function assign($role, $permission, $returnId = false) {
        $assignation = new \ArangoDBClient\Edge();        

        $ownedById = $this->returnId($role, 'Role');
        $ownedById = $ownedById ? $ownedById : $role;

        $ownedId = $this->returnId($permission, 'Permission');
        $ownedId = $ownedId ? $ownedId : $permission;

        $assignation->setFrom($ownedById);
        $assignation->setTo($ownedId);
        
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        $query      = "FOR d IN @@collection\nFILTER d._from == @_from AND d._to == @_to\nLIMIT 2\nRETURN d._id";
        $statement->setQuery($query);
        $statement->bind('@collection', static::HAS);
        $statement->bind('_from', $ownedById);
        $statement->bind('_to', $ownedId);
        $result = $statement->execute();
        $response = $result->getAll();

        $edgeExists = count($response) > 0;

        if($edgeExists) {
            return false;
        }

        $id = $this->edgeHandler->saveEdge(static::HAS, $ownedById, $ownedId, $assignation);
        if($returnId) {
            return $id;
        } else {
            return true;
        }
    }

    /**
     * @param string|int $id
     * @return array $children
     */
    public function children($id) {
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        $query      = "FOR d IN @@collection
                            \nFILTER d._id == @_id
                            \nFOR v IN INBOUND d @@inheritance
                        RETURN KEEP( v, 'title', 'description', '_id' )";
        $statement->setQuery($query);
        $statement->bind('@collection', static::COLLECTION_NAME);
        $statement->bind('@inheritance', static::INHERITS_FROM);
        $statement->bind('_id', $id);
        $result = $statement->execute();
        $response = $result->getAll();
        return $response;
    }

    /**
     * @return int $total Count total of this entity
     */
    public function count() {
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        $query      = "RETURN LENGTH(@@collection)";
        $statement->setQuery($query);
        $statement->bind('@collection', static::COLLECTION_NAME);
        $result = $statement->execute();
        $response = $result->getAll();
        return $response[0];
    }

    /**
     * @param string|int $id
     * @return int $depth How deep an entity is in the User/Role/Permission tree
     */
    public function depth($id) {
        // Beginning from the root node
        $root = $this->returnId('root');
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        $query      = "WITH @@collection
                \nLET SHORTEST = (
                \n    FOR d IN INBOUND SHORTEST_PATH
                \n    @v1 TO @v2
                \n    @@edgeCollection
                \n    RETURN d.title
                \n)
                \nRETURN LENGTH(SHORTEST)";
        $statement->setQuery($query);
        $statement->bind('@collection', static::COLLECTION_NAME);
        $statement->bind('v1', $root);
        $statement->bind('v2', $id);
        $statement->bind('@edgeCollection', static::INHERITS_FROM);
        $result = $statement->execute();
        $response = $result->getAll();
        return $response[0];
        
    }

    /**
	 * Returns descendants of a node, with their depths in integer
	 *
	 * @param string|int $id
	 * @return array with keys as titles and Title, ID, Depth and Description
	 */
	function descendants($id){
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        $query      = "FOR d IN @@collection
                            \nFILTER d._id == @_id
                            \nFOR v, e, p IN 1..100000 INBOUND d @@inheritance
                            \nLET DEPTH=LENGTH(p.edges)
                        RETURN MERGE(KEEP( v, 'title', 'description', '_id' ), { 'depth': DEPTH })";
        $statement->setQuery($query);
        $statement->bind('@collection', static::COLLECTION_NAME);
        $statement->bind('@inheritance', static::INHERITS_FROM);
        $statement->bind('_id', $id);
        $result = $statement->execute();
        $response = $result->getAll();
        $response = array_map(
            function($doc) {
                $result = new \stdClass();
                $result->{$doc->title} = $doc;
                return $result;
            },
            $response
        );
        return $response;
    }
    
    /**
     * Edits an Entity, changing Title and/or Description and/or path
     * 
     * @param string|int $id
     * @param string (optional) $newTitle
     * @param string (optional) $newDescription
     * @param string (optional) $path
     * @param array (optional) $additionalFeatures
     * @return bool $success
     */
    public function edit(
        $id,
        $newTitle = '',
        $newDescription = '',
        $path = '',
        $additionalFeatures = []
    ) {
        $document = new \ArangoDBClient\Document();
        if(!empty($newTitle)){
            $document->set('title', $newTitle);
        }
        if(!empty($newDescription)){
            $document->set('description', $newDescription);
        }
        if(!empty($path)) {
            //Remove root before editing to avoid duplicates
            $editedPath = preg_replace('/^(\/root\/)(.*)/', '$2', $path);
            //Then add it
            $document->set('path', "/root/$editedPath");
        }

        foreach($additionalFeatures as $feature => $value) {
            $document->set($feature, $value);
        }

        $this->documentHandler->updateById(static::COLLECTION_NAME, $id, $document);

        return true;
    }

    /**
     * @param string|int $id
     * @return string $description
     */
    public function getDescription($id) {
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        $query      = "FOR d IN @@collection
                            \nFILTER d._id == @_id
                            \nRETURN d.description";
        $statement->setQuery($query);
        $statement->bind('@collection', static::COLLECTION_NAME);
        $statement->bind('_id', $id);
        $result = $statement->execute();
        $response = $result->getAll();
        return $response[0];
    }

    /**
     * @param string|int $id
     * @return string $path
     */
    public function getPath($id) {
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        $query      = "FOR d IN @@collection
                        \nFILTER d._id == @_id
                        \nRETURN d.path";
        $statement->setQuery($query);
        $statement->bind('@collection', static::COLLECTION_NAME);
        $statement->bind('_id', $id);
        $result = $statement->execute();
        $response = $result->getAll();
        
        //Remove root before returning
        $editedResponse = preg_replace('/^(\/root\/)(.*)/', '/$2', $response[0]);

        return $editedResponse;
    }

    /**
     * @param string|int $id
     * @return string $title
     */
    public function getTitle($id) {
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        $query      = "FOR d IN @@collection
                            \nFILTER d._id == @_id
                            \nRETURN d.title";
        $statement->setQuery($query);
        $statement->bind('@collection', static::COLLECTION_NAME);
        $statement->bind('_id', $id);
        $result = $statement->execute();
        $response = $result->getAll();
        return $response[0];
    }

    /**
     * @param string|int $id
     * @return bool|array including Title, Description, ID
     */
    public function parentNode($id) {
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        $query      = "FOR d IN @@collection
                            \nFILTER d._id == @_id
                                \nFOR v IN 1..1 OUTBOUND d @@edgeCollection
                               \nRETURN KEEP(v, 'title', 'description', '_id')";
        $statement->setQuery($query);
        $statement->bind('@collection', static::COLLECTION_NAME);
        $statement->bind('_id', $id);
        $statement->bind('@edgeCollection', static::INHERITS_FROM);
        $result = $statement->execute();
        $response = $result->getAll();

        if(count($response) > 0) {
            $response = $result->getAll()[0];

            $editedResponse = ['title' => $response->get('title'), 'description' => $response->get('description'), 'id' => $response->getId()];
        } else {
            $editedResponse = false;
        }
        return $editedResponse;
    }

    /**
     * Returns the ID of a path
     * 
     * @param string $path Path
     * @return int|null $id = null if ID does not exist
     */
    public function pathId($path) {
        $pathVar = rtrim("/root$path", '/') . '/';
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        $query      = "FOR d IN @@collection\nFILTER d.path == @path\nLIMIT 2\nRETURN d._id";
        $statement->setQuery($query);
        $statement->bind('@collection', static::COLLECTION_NAME);
        $statement->bind('path', $pathVar);
        $result = $statement->execute();
        $response = $result->getAll();
        if(count($response) > 1) {
            throw new AmbiguousIDRequestException("Error: two IDs returned for ID request ({$response[0]['_key']}, {$response[1]['_key']}), duplicate paths found for $path");
        } else if(count($response) === 1) {
            return $response[0];
        } else {
            return null;
        }
    }

    /**
     * Returns the ID from either a Title or a Path
     * 
     * @param string $entity Title|Path
     * @param string (optional) $collection Collection
     * @return int|null $id = null if ID does not exist
     */
    public function returnId($entity, $collection = false) {
        if($collection === false) {
            $collection = static::COLLECTION_NAME;
        }
        $pathVar = rtrim("/root$entity", '/') . '/';
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        $query      = "FOR d IN @@collection\nFILTER d.title == @title OR d.path == @path\nLIMIT 2\nRETURN d._id";
        $statement->setQuery($query);
        $statement->bind('@collection', $collection);
        $statement->bind('title', $entity);
        $statement->bind('path', $pathVar);
        $result = $statement->execute();
        $response = $result->getAll();
        if(count($response) > 1) {
            throw new AmbiguousIDRequestException("Error: two IDs returned for ID request ({$response[0]['_key']}, {$response[1]['_key']}), consider using titleId or pathId methods");
        } else if(count($response) === 1) {
            return $response[0];
        } else {
            return null;
        }
    }

    /**
     * Returns the ID of a title
     * 
     * @param string $title Title
     * @return int|null $id = null if ID does not exist
     */
    public function titleId($title) {
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        $query      = "FOR d IN @@collection\nFILTER d.title == @title\nLIMIT 2\nRETURN d._id";
        $statement->setQuery($query);
        $statement->bind('@collection', static::COLLECTION_NAME);
        $statement->bind('title', $title);
        $result = $statement->execute();
        $response = $result->getAll();
        if(count($response) > 1) {
            throw new AmbiguousIDRequestException("Error: two IDs returned for ID request ({$response[0]['_key']}, {$response[1]['_key']}), duplicate titles found for $title");
        } else if(count($response) === 1) {
            return $response[0];
        } else {
            return null;
        }
    }

    /**
     * Unassign a a permission from a role
     * @param string|int $role Title|Path|ID
     * @param string|int $permission Title|Path|ID
     * @return bool $success
     */
    public function unassign($role, $permission) {
        $ownedById = $this->returnId($role, 'Role');
        $ownedById = $ownedById ? $ownedById : $role;

        $ownedId = $this->returnId($permission, 'Permission');
        $ownedId = $ownedId ? $ownedId : $permission;
        
        $statement  = new \ArangoDBClient\Statement($this->connection, []);
        $query      = "FOR d IN @@collection\nFILTER d._from == @_from AND d._to == @_to\nLIMIT 100\nRETURN d._id";
        $statement->setQuery($query);
        $statement->bind('@collection', static::HAS);
        $statement->bind('_from', $ownedById);
        $statement->bind('_to', $ownedId);
        $result = $statement->execute();
        $response = $result->getAll();

        $edgeExists = count($response) > 0;

        if($edgeExists) {
            // There should only be one, but:
            foreach($response as $edge) {
                $id = $edge->getId();
                $this->edgeHandler->removeById(static::HAS, $id);
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * Caution: removes all entities of this type
     * @param bool $ensure - safeguarding variable to prevent accidents
     * @param bool $returnDocs - whether to return all deleted documents or a simple count
     * @return int|array $count|$deleted - number of deleted entities
     * @throws ResetException
     */
    public function reset($ensure = false, $returnDocs = false) {
        // Can never be too careful with resets - hence Yoda conditional
        if(true === $ensure) {
            $statement  = new \ArangoDBClient\Statement($this->connection, []);
            $query      = "FOR d IN @@collection
                            \nREMOVE d IN @@collection
                            \nRETURN d";
            $statement->setQuery($query);
            $statement->bind('@collection', static::COLLECTION_NAME);
            $result = $statement->execute();
            $response = $result->getAll();

            if($returnDocs) {
                return $response;
            } else {
                return count($response);
            }
        } else {
            throw new ResetException('Reset Error: Pass a parameter $ensure = true in order to confirm reset.');
        }
    }

    /**
     * Caution: removes all (non-inherited) relationships from this entity
     * @param bool $ensure - safeguarding variable to prevent accidents
     * @return int $count - number of deleted entities
     * @throws ResetException
     */
    public function resetAssignments($ensure = false, $returnDocs = false) {
        // Can never be too careful with resets - hence Yoda conditional
        if(true === $ensure) {
            $statement  = new \ArangoDBClient\Statement($this->connection, []);
            $query      = "FOR d IN @@collection
                            \nREMOVE d IN @@collection
                            \nRETURN d";
            $statement->setQuery($query);
            $statement->bind('@collection', static::HAS);
            $result = $statement->execute();
            $response = $result->getAll();

            if($returnDocs) {
                return $response;
            } else {
                return count($response);
            }
        } else {
            throw new ResetException('Reset Error: Pass a parameter $ensure = true in order to confirm reset.');
        }
    }

}