<?php

namespace MongoDB;

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Cursor;
use MongoDB\Driver\Manager;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\Server;
use MongoDB\Driver\WriteConcern;
use MongoDB\Exception\InvalidArgumentException;
use MongoDB\Exception\UnexpectedTypeException;
use MongoDB\Model\IndexInfoIterator;
use MongoDB\Model\IndexInput;
use MongoDB\Operation\Aggregate;
use MongoDB\Operation\CreateIndexes;
use MongoDB\Operation\Count;
use MongoDB\Operation\Distinct;
use MongoDB\Operation\DropCollection;
use MongoDB\Operation\DropIndexes;
use MongoDB\Operation\Find;
use MongoDB\Operation\FindOne;
use MongoDB\Operation\FindOneAndDelete;
use MongoDB\Operation\FindOneAndReplace;
use MongoDB\Operation\FindOneAndUpdate;
use MongoDB\Operation\ListIndexes;
use Traversable;

class Collection
{
    /* {{{ consts & vars */
    protected $manager;
    protected $ns;
    protected $wc;
    protected $rp;

    protected $dbname;
    protected $collname;
    /* }}} */


    /**
     * Constructs new Collection instance.
     *
     * This class provides methods for collection-specific operations, such as
     * CRUD (i.e. create, read, update, and delete) and index management.
     *
     * @param Manager        $manager        Manager instance from the driver
     * @param string         $namespace      Collection namespace (e.g. "db.collection")
     * @param WriteConcern   $writeConcern   Default write concern to apply
     * @param ReadPreference $readPreference Default read preference to apply
     */
    public function __construct(Manager $manager, $namespace, WriteConcern $writeConcern = null, ReadPreference $readPreference = null)
    {
        $this->manager = $manager;
        $this->ns = (string) $namespace;
        $this->wc = $writeConcern;
        $this->rp = $readPreference;

        list($this->dbname, $this->collname) = explode(".", $namespace, 2);
    }

    /**
     * Return the collection namespace.
     *
     * @param string
     */
    public function __toString()
    {
        return $this->ns;
    }

    /**
     * Executes an aggregation framework pipeline on the collection.
     *
     * Note: this method's return value depends on the MongoDB server version
     * and the "useCursor" option. If "useCursor" is true, a Cursor will be
     * returned; otherwise, an ArrayIterator is returned, which wraps the
     * "result" array from the command response document.
     *
     * @see Aggregate::__construct() for supported options
     * @param array $pipeline List of pipeline operations
     * @param array $options  Command options
     * @return Traversable
     */
    public function aggregate(array $pipeline, array $options = array())
    {
        $readPreference = new ReadPreference(ReadPreference::RP_PRIMARY);
        $server = $this->manager->selectServer($readPreference);
        $operation = new Aggregate($this->dbname, $this->collname, $pipeline, $options);

        return $operation->execute($server);
    }

    /**
     * Adds a full set of write operations into a bulk and executes it
     *
     * The syntax of the $bulk array is:
     *     $bulk = [
     *         [
     *             'METHOD' => [
     *                 $document,
     *                 $extraArgument1,
     *                 $extraArgument2,
     *             ],
     *         ],
     *         [
     *             'METHOD' => [
     *                 $document,
     *                 $extraArgument1,
     *                 $extraArgument2,
     *             ],
     *         ],
     *     ]
     *
     *
     * Where METHOD is one of
     *     - 'insertOne'
     *           Supports no $extraArgument
     *     - 'updateMany'
     *           Requires $extraArgument1, same as $update for Collection::updateMany()
     *           Optional $extraArgument2, same as $options for Collection::updateMany()
     *     - 'updateOne'
     *           Requires $extraArgument1, same as $update for Collection::updateOne()
     *           Optional $extraArgument2, same as $options for Collection::updateOne()
     *     - 'replaceOne'
     *           Requires $extraArgument1, same as $update for Collection::replaceOne()
     *           Optional $extraArgument2, same as $options for Collection::replaceOne()
     *     - 'deleteOne'
     *           Supports no $extraArgument
     *     - 'deleteMany'
     *           Supports no $extraArgument
     *
     * @example Collection-bulkWrite.php Using Collection::bulkWrite()
     *
     * @see Collection::getBulkOptions() for supported $options
     *
     * @param array $ops    Array of operations
     * @param array $options Additional options
     * @return WriteResult
     */
    public function bulkWrite(array $ops, array $options = array())
    {
        $options = array_merge($this->getBulkOptions(), $options);

        $bulk = new BulkWrite($options["ordered"]);
        $insertedIds = array();

        foreach ($ops as $n => $op) {
            foreach ($op as $opname => $args) {
                if (!isset($args[0])) {
                    throw new InvalidArgumentException(sprintf("Missing argument#1 for '%s' (operation#%d)", $opname, $n));
                }

                switch ($opname) {
                case "insertOne":
                    $insertedId = $bulk->insert($args[0]);

                    if ($insertedId !== null) {
                        $insertedIds[$n] = $insertedId;
                    } else {
                        $insertedIds[$n] = is_array($args[0]) ? $args[0]['_id'] : $args[0]->_id;
                    }

                    break;

                case "updateMany":
                    if (!isset($args[1])) {
                        throw new InvalidArgumentException(sprintf("Missing argument#2 for '%s' (operation#%d)", $opname, $n));
                    }
                    $options = array_merge($this->getWriteOptions(), isset($args[2]) ? $args[2] : array(), array("multi" => true));
                    $firstKey = key($args[1]);
                    if (!isset($firstKey[0]) || $firstKey[0] != '$') {
                        throw new InvalidArgumentException("First key in \$update must be a \$operator");
                    }

                    $bulk->update($args[0], $args[1], $options);
                    break;

                case "updateOne":
                    if (!isset($args[1])) {
                        throw new InvalidArgumentException(sprintf("Missing argument#2 for '%s' (operation#%d)", $opname, $n));
                    }
                    $options = array_merge($this->getWriteOptions(), isset($args[2]) ? $args[2] : array(), array("multi" => false));
                    $firstKey = key($args[1]);
                    if (!isset($firstKey[0]) || $firstKey[0] != '$') {
                        throw new InvalidArgumentException("First key in \$update must be a \$operator");
                    }

                    $bulk->update($args[0], $args[1], $options);
                    break;

                case "replaceOne":
                    if (!isset($args[1])) {
                        throw new InvalidArgumentException(sprintf("Missing argument#2 for '%s' (operation#%d)", $opname, $n));
                    }
                    $options = array_merge($this->getWriteOptions(), isset($args[2]) ? $args[2] : array(), array("multi" => false));
                    $firstKey = key($args[1]);
                    if (isset($firstKey[0]) && $firstKey[0] == '$') {
                        throw new InvalidArgumentException("First key in \$update must NOT be a \$operator");
                    }

                    $bulk->update($args[0], $args[1], $options);
                    break;

                case "deleteOne":
                    $options = array_merge($this->getWriteOptions(), isset($args[1]) ? $args[1] : array(), array("limit" => 1));
                    $bulk->delete($args[0], $options);
                    break;

                case "deleteMany":
                    $options = array_merge($this->getWriteOptions(), isset($args[1]) ? $args[1] : array(), array("limit" => 0));
                    $bulk->delete($args[0], $options);
                    break;

                default:
                    throw new InvalidArgumentException(sprintf("Unknown operation type called '%s' (operation#%d)", $opname, $n));
                }
            }
        }

        $writeResult = $this->manager->executeBulkWrite($this->ns, $bulk, $this->wc);

        return new BulkWriteResult($writeResult, $insertedIds);
    }

    /**
     * Gets the number of documents matching the filter.
     *
     * @see Count::__construct() for supported options
     * @param array $filter  Query by which to filter documents
     * @param array $options Command options
     * @return integer
     */
    public function count(array $filter = array(), array $options = array())
    {
        $operation = new Count($this->dbname, $this->collname, $filter, $options);
        $server = $this->manager->selectServer(new ReadPreference(ReadPreference::RP_PRIMARY));

        return $operation->execute($server);
    }

    /**
     * Create a single index for the collection.
     *
     * @see Collection::createIndexes()
     * @param array|object $key     Document containing fields mapped to values,
     *                              which denote order or an index type
     * @param array        $options Index options
     * @return string The name of the created index
     */
    public function createIndex($key, array $options = array())
    {
        return current($this->createIndexes(array(array('key' => $key) + $options)));
    }

    /**
     * Create one or more indexes for the collection.
     *
     * Each element in the $indexes array must have a "key" document, which
     * contains fields mapped to an order or type. Other options may follow.
     * For example:
     *
     *     $indexes = [
     *         // Create a unique index on the "username" field
     *         [ 'key' => [ 'username' => 1 ], 'unique' => true ],
     *         // Create a 2dsphere index on the "loc" field with a custom name
     *         [ 'key' => [ 'loc' => '2dsphere' ], 'name' => 'geo' ],
     *     ];
     *
     * If the "name" option is unspecified, a name will be generated from the
     * "key" document.
     *
     * @see http://docs.mongodb.org/manual/reference/command/createIndexes/
     * @see http://docs.mongodb.org/manual/reference/method/db.collection.createIndex/
     * @param array[] $indexes List of index specifications
     * @return string[] The names of the created indexes
     * @throws InvalidArgumentException if an index specification is invalid
     */
    public function createIndexes(array $indexes)
    {
        $operation = new CreateIndexes($this->dbname, $this->collname, $indexes);
        $server = $this->manager->selectServer(new ReadPreference(ReadPreference::RP_PRIMARY));

        return $operation->execute($server);
    }

    /**
     * Deletes a document matching the $filter criteria.
     * NOTE: Will delete ALL documents matching $filter
     *
     * @see http://docs.mongodb.org/manual/reference/command/delete/
     *
     * @param array $filter The $filter criteria to delete
     * @return DeleteResult
     */
    public function deleteMany(array $filter)
    {
        $wr = $this->_delete($filter, 0);

        return new DeleteResult($wr);
    }

    /**
     * Deletes a document matching the $filter criteria.
     * NOTE: Will delete at most ONE document matching $filter
     *
     * @see http://docs.mongodb.org/manual/reference/command/delete/
     *
     * @param array $filter The $filter criteria to delete
     * @return DeleteResult
     */
    public function deleteOne(array $filter)
    {
        $wr = $this->_delete($filter);

        return new DeleteResult($wr);
    }

    /**
     * Finds the distinct values for a specified field across the collection.
     *
     * @see Distinct::__construct() for supported options
     * @param string $fieldName Field for which to return distinct values
     * @param array  $filter    Query by which to filter documents
     * @param array  $options   Command options
     * @return mixed[]
     */
    public function distinct($fieldName, array $filter = array(), array $options = array())
    {
        $operation = new Distinct($this->dbname, $this->collname, $fieldName, $filter, $options);
        $server = $this->manager->selectServer(new ReadPreference(ReadPreference::RP_PRIMARY));

        return $operation->execute($server);
    }

    /**
     * Drop this collection.
     *
     * @return object Command result document
     */
    public function drop()
    {
        $operation = new DropCollection($this->dbname, $this->collname);
        $server = $this->manager->selectServer(new ReadPreference(ReadPreference::RP_PRIMARY));

        return $operation->execute($server);
    }

    /**
     * Drop a single index in the collection.
     *
     * @param string $indexName Index name
     * @return object Command result document
     * @throws InvalidArgumentException if $indexName is an empty string or "*"
     */
    public function dropIndex($indexName)
    {
        $indexName = (string) $indexName;

        if ($indexName === '*') {
            throw new InvalidArgumentException('dropIndexes() must be used to drop multiple indexes');
        }

        $operation = new DropIndexes($this->dbname, $this->collname, $indexName);
        $server = $this->manager->selectServer(new ReadPreference(ReadPreference::RP_PRIMARY));

        return $operation->execute($server);
    }

    /**
     * Drop all indexes in the collection.
     *
     * @return object Command result document
     */
    public function dropIndexes()
    {
        $operation = new DropIndexes($this->dbname, $this->collname, '*');
        $server = $this->manager->selectServer(new ReadPreference(ReadPreference::RP_PRIMARY));

        return $operation->execute($server);
    }

    /**
     * Finds documents matching the query.
     *
     * @see Find::__construct() for supported options
     * @see http://docs.mongodb.org/manual/core/read-operations-introduction/
     * @param array $filter  Query by which to filter documents
     * @param array $options Additional options
     * @return Cursor
     */
    public function find(array $filter = array(), array $options = array())
    {
        $operation = new Find($this->dbname, $this->collname, $filter, $options);
        $server = $this->manager->selectServer(new ReadPreference(ReadPreference::RP_PRIMARY));

        return $operation->execute($server);
    }

    /**
     * Finds a single document matching the query.
     *
     * @see FindOne::__construct() for supported options
     * @see http://docs.mongodb.org/manual/core/read-operations-introduction/
     * @param array $filter    The find query to execute
     * @param array $options   Additional options
     * @return object|null
     */
    public function findOne(array $filter = array(), array $options = array())
    {
        $operation = new FindOne($this->dbname, $this->collname, $filter, $options);
        $server = $this->manager->selectServer(new ReadPreference(ReadPreference::RP_PRIMARY));

        return $operation->execute($server);
    }

    /**
     * Finds a single document and deletes it, returning the original.
     *
     * The document to return may be null.
     *
     * @see FindOneAndDelete::__construct() for supported options
     * @param array|object $filter  Query by which to filter documents
     * @param array        $options Command options
     * @return object|null
     */
    public function findOneAndDelete($filter, array $options = array())
    {
        $operation = new FindOneAndDelete($this->dbname, $this->collname, $filter, $options);
        $server = $this->manager->selectServer(new ReadPreference(ReadPreference::RP_PRIMARY));

        return $operation->execute($server);
    }

    /**
     * Finds a single document and replaces it, returning either the original or
     * the replaced document.
     *
     * The document to return may be null. By default, the original document is
     * returned. Specify FindOneAndReplace::RETURN_DOCUMENT_AFTER for the
     * "returnDocument" option to return the updated document.
     *
     * @see FindOneAndReplace::__construct() for supported options
     * @param array|object $filter      Query by which to filter documents
     * @param array|object $replacement Replacement document
     * @param array        $options     Command options
     * @return object|null
     */
    public function findOneAndReplace($filter, $replacement, array $options = array())
    {
        $operation = new FindOneAndReplace($this->dbname, $this->collname, $filter, $replacement, $options);
        $server = $this->manager->selectServer(new ReadPreference(ReadPreference::RP_PRIMARY));

        return $operation->execute($server);
    }

    /**
     * Finds a single document and updates it, returning either the original or
     * the updated document.
     *
     * The document to return may be null. By default, the original document is
     * returned. Specify FindOneAndUpdate::RETURN_DOCUMENT_AFTER for the
     * "returnDocument" option to return the updated document.
     *
     * @see FindOneAndReplace::__construct() for supported options
     * @param array|object $filter  Query by which to filter documents
     * @param array|object $update  Update to apply to the matched document
     * @param array        $options Command options
     * @return object|null
     */
    public function findOneAndUpdate($filter, $update, array $options = array())
    {
        $operation = new FindOneAndUpdate($this->dbname, $this->collname, $filter, $update, $options);
        $server = $this->manager->selectServer(new ReadPreference(ReadPreference::RP_PRIMARY));

        return $operation->execute($server);
    }

    /**
     * Retrieves all Bulk Write options with their default values.
     *
     * @return array of available Bulk Write options
     */
    public function getBulkOptions()
    {
        return array(
            "ordered" => false,
        );
    }

    /**
     * Return the collection name.
     *
     * @return string
     */
    public function getCollectionName()
    {
        return $this->collname;
    }

    /**
     * Return the database name.
     *
     * @return string
     */
    public function getDatabaseName()
    {
        return $this->dbname;
    }

    /**
     * Return the collection namespace.
     *
     * @see http://docs.mongodb.org/manual/faq/developers/#faq-dev-namespace
     * @return string
     */
    public function getNamespace()
    {
        return $this->ns;
    }

    /**
     * Retrieves all Write options with their default values.
     *
     * @return array of available Write options
     */
    public function getWriteOptions()
    {
        return array(
            "ordered" => false,
            "upsert"  => false,
            "limit"   => 1,
        );
    }

    /**
     * Inserts the provided documents
     *
     * @see http://docs.mongodb.org/manual/reference/command/insert/
     *
     * @param array[]|object[] $documents The documents to insert
     * @return InsertManyResult
     */
    public function insertMany(array $documents)
    {
        $options = array_merge($this->getWriteOptions());

        $bulk = new BulkWrite($options["ordered"]);
        $insertedIds = array();

        foreach ($documents as $i => $document) {
            $insertedId = $bulk->insert($document);

            if ($insertedId !== null) {
                $insertedIds[$i] = $insertedId;
            } else {
                $insertedIds[$i] = is_array($document) ? $document['_id'] : $document->_id;
            }
        }

        $writeResult = $this->manager->executeBulkWrite($this->ns, $bulk, $this->wc);

        return new InsertManyResult($writeResult, $insertedIds);
    }

    /**
     * Inserts the provided document
     *
     * @see http://docs.mongodb.org/manual/reference/command/insert/
     *
     * @param array|object $document The document to insert
     * @return InsertOneResult
     */
    public function insertOne($document)
    {
        $options = array_merge($this->getWriteOptions());

        $bulk = new BulkWrite($options["ordered"]);
        $id    = $bulk->insert($document);
        $wr    = $this->manager->executeBulkWrite($this->ns, $bulk, $this->wc);

        if ($id === null) {
            $id = is_array($document) ? $document['_id'] : $document->_id;
        }

        return new InsertOneResult($wr, $id);
    }

    /**
     * Returns information for all indexes for the collection.
     *
     * @see ListIndexes::__construct() for supported options
     * @return IndexInfoIterator
     */
    public function listIndexes(array $options = array())
    {
        $operation = new ListIndexes($this->dbname, $this->collname, $options);
        $server = $this->manager->selectServer(new ReadPreference(ReadPreference::RP_PRIMARY));

        return $operation->execute($server);
    }

    /**
     * Replace one document
     *
     * @see http://docs.mongodb.org/manual/reference/command/update/
     * @see Collection::getWriteOptions() for supported $options
     *
     * @param array $filter   The document to be replaced
     * @param array $update   The document to replace with
     * @param array $options  Additional options
     * @return UpdateResult
     */
    public function replaceOne(array $filter, array $update, array $options = array())
    {
        $firstKey = key($update);
        if (isset($firstKey[0]) && $firstKey[0] == '$') {
            throw new InvalidArgumentException("First key in \$update must NOT be a \$operator");
        }
        $wr = $this->_update($filter, $update, $options + array("multi" => false));

        return new UpdateResult($wr);
    }

    /**
     * Update one document
     * NOTE: Will update ALL documents matching $filter
     *
     * @see http://docs.mongodb.org/manual/reference/command/update/
     * @see Collection::getWriteOptions() for supported $options
     *
     * @param array $filter   The document to be replaced
     * @param array $update   An array of update operators to apply to the document
     * @param array $options  Additional options
     * @return UpdateResult
     */
    public function updateMany(array $filter, $update, array $options = array())
    {
        $wr = $this->_update($filter, $update, $options + array("multi" => true));

        return new UpdateResult($wr);
    }

    /**
     * Update one document
     * NOTE: Will update at most ONE document matching $filter
     *
     * @see http://docs.mongodb.org/manual/reference/command/update/
     * @see Collection::getWriteOptions() for supported $options
     *
     * @param array $filter   The document to be replaced
     * @param array $update   An array of update operators to apply to the document
     * @param array $options  Additional options
     * @return UpdateResult
     */
    public function updateOne(array $filter, array $update, array $options = array())
    {
        $firstKey = key($update);
        if (!isset($firstKey[0]) || $firstKey[0] != '$') {
            throw new InvalidArgumentException("First key in \$update must be a \$operator");
        }
        $wr = $this->_update($filter, $update, $options + array("multi" => false));

        return new UpdateResult($wr);
    }

    /**
     * Internal helper for delete one/many documents
     * @internal
     */
    final protected function _delete($filter, $limit = 1)
    {
        $options = array_merge($this->getWriteOptions(), array("limit" => $limit));

        $bulk  = new BulkWrite($options["ordered"]);
        $bulk->delete($filter, $options);
        return $this->manager->executeBulkWrite($this->ns, $bulk, $this->wc);
    }

    /**
     * Internal helper for replacing/updating one/many documents
     * @internal
     */
    protected function _update($filter, $update, $options)
    {
        $options = array_merge($this->getWriteOptions(), $options);

        $bulk  = new BulkWrite($options["ordered"]);
        $bulk->update($filter, $update, $options);
        return $this->manager->executeBulkWrite($this->ns, $bulk, $this->wc);
    }
}