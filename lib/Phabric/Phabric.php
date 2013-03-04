<?php
namespace Phabric;
use Phabric\Datasource\IDatasource;
use Behat\Gherkin\Node\TableNode;

/**
 * This file is part of the Phabric.
 * (c) Ben Waine <ben@ben-waine.co.uk>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * The Phabric object manages the registration of translations for use on all
 * subscribing instances of Phabric. It also allows the creation of relational
 * data by providing one instance of Phabric with access to all others via a single interface.
 *
 * @package    Phabric
 * @author     Ben Waine <ben@ben-waine.co.uk>
 */
class Phabric
{
    const REG_ENTITIES = 'phabric-entities';
    const REG_DATA_TRANSFORMATIONS = 'phabric-data-transformations';

    /**
     * Registry object.
     *
     * @var Phabric\Registry
     */
    protected $registry = null;

    /**
     * Datasource used to insert / update records into.
     *
     * @var \Doctrine\Connection
     */
    protected $datasource;

    /**
     * Initialises an instance of the Phabric class.
     *
     * @param $ds The Datasource
     *
     * @return void
     */
    public function __construct(IDatasource $ds)
    {
        $this->datasource = $ds;
        $this->registry = new Registry();
    }

    /**
     * Creates and registers a Phabric entity.
     *
     * @param string $name   Name to register the entity with.
     * @param array  $config Configuration array.
     *
     * @return \Phabric\Entity
     */
    public function createEntity($name, $config = null)
    {
        $entity = new Entity($this->datasource, $this, $config);

        $this->addEntity($name, $entity);

        return $entity;
    }

    /**
     * Creates multiple entities from config array.
     * The keys of the array are used as the names of the entities.
     *
     * @param array $config
     *
     * @return void
     */
    public function createEntitiesFromConfig(array $config)
    {
        foreach($config as $name => $enConf)
        {

            $enConf = array_merge($enConf, array('entityName' => $name));

            $this->createEntity($name, $enConf);
        }
    }

    /**
     * Registeres an lambda function against a named key for use in subscribed
     * Phabric instances.
     *
     * @param string   $name
     * @param function $translation
     *
     * @return void
     */
    public function addDataTransformation($name, $transformation)
    {
        if (!\is_callable($transformation))
        {
            throw new \InvalidArgumentException("Transformation [$name] passed to " .
                    __METHOD__ . ' is not callable');
        }

        $this->registry->add(static::REG_DATA_TRANSFORMATIONS, $name, $transformation);
    }

    /**
     * Get a named data translation for use in a subscribed phabric instance.
     *
     * @param string $name
     *
     * @return function
     */
    public function getDataTransformation($name)
    {
        $transformation = $this->registry->get(static::REG_DATA_TRANSFORMATIONS, $name);

        if (null === $transformation)
        {
            throw new \InvalidArgumentException("Data transformation function [$name] " .
                    'is not registered');
        }

        return $transformation;
    }

    /**
     * Registers an entity by name for retrieval later by other phabric
     * instances.
     *
     * @param string Entity name
     * @param Entity $phabric
     *
     * @return void
     */
    public function addEntity($name, Entity $phabric)
    {
        $this->registry->add(static::REG_ENTITIES, $name, $phabric);
    }

    /**
     * Get a named Phabric entity from the registered entities.
     *
     * @param string $name
     *
     * @throws \InvalidArgumentException
     *
     * @return Phabric\Entity
     */
    public function getEntity($name)
    {
        $entity = $this->registry->get(static::REG_ENTITIES, $name);

        if (null === $entity)
        {
            throw new \InvalidArgumentException('Entity not registered');
        }

        return $entity;
    }

    /**
     * A convience method taking the name of a previously created entity and
     * a TableNode. Data is inserted into the data source as if calling
     * 'createFromTable' on the named entity directly.
     *
     * @param string    $entityName Name of a previously created entity.
     * @param TableNode $table
     * @param boolean   $default    Determines if default data values should be applied.
     *
     */
    public function insertFromTable($entityName, TableNode $table, $default = true)
    {
        $entity = $this->getEntity($entityName);

        if($entity instanceof Entity)
        {
            return $entity->insertFromTable($table, $default);
        }
        else
        {
            throw new \RuntimeException('Specified entity name does not map to registered entity');
        }
    }

    /**
     * A convience method taking the name of a previously created entity and
     * an array. Data is inserted into the data source as if calling
     * 'insertFromArray' on the named entity directly.
     *
     * @param string    $entityName Name of a previously created entity.
     * @param TableNode $table
     * @param boolean   $default    Determines if default data values should be applied.
     *
     */
    public function insertFromArray($entityName, array $dataArray, $default = true)
    {
        $entity = $this->getEntity($entityName);

        if($entity instanceof Entity)
        {
            return $entity->insertFromArray($dataArray, $default);
        }
        else
        {
            throw new \RuntimeException('Specified entity name does not map to registered entity');
        }
    }

    /**
     * A convience method taking the name of a previously created entity and a
     * TableNode. Data is used to update previously inserted database records.
     *
     * @param Entity    $entityName
     * @param TableNode $table
     *
     * @throws \RuntimeException When a record previously not inserted is specified
     *
     * @return void
     */
    public function updateFromTable($entityName, TableNode $table)
    {
        $entity = $this->getEntity($entityName);

        try
        {
            $entity->updateFromTable($table);
        }
        catch (\Exception $e)
        {
            // @todo: proper check for a type of Exception
            $entity->insertFromTable($table);
        }
    }

    /**
     * Resets all inserts and updates made by Phabric.
     *
     * @return void
     */
    public function reset()
    {
        $this->datasource->reset();
    }

}

