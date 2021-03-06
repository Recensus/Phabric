<?php

use Behat\Behat\Context\ClosuredContextInterface,
    Behat\Behat\Context\TranslatedContextInterface,
    Behat\Behat\Context\BehatContext,
    Behat\Behat\Exception\PendingException;
use Behat\Gherkin\Node\PyStringNode,
    Behat\Gherkin\Node\TableNode,
    Behat\Behat\Event\SuiteEvent;
use Phabric\Factory as pFactory;
use Phabric\Phabric;

require_once 'PHPUnit/Framework/Assert/Functions.php';

/**
 * Features context.
 */
class FeatureContext extends BehatContext {

    /**
     * The Phabric Bus
     *
     * @var Phabric\Bus
     */
    private $phabric;

    /**
     * The Databse Connection.
     *
     * @var Doctrine\DBAL\Connection
     */
    private static $db;

    /**
     * Initializes context.
     * Every scenario gets it's own context object.
     *
     * @param   array   $parameters     context parameters (set them up through behat.yml)
     */
    public function __construct(array $parameters) {
        
        $config = new \Doctrine\DBAL\Configuration();

        self::$db = \Doctrine\DBAL\DriverManager::getConnection(array(
                    'dbname' => $parameters['database']['dbname'],
                    'user' => $parameters['database']['username'],
                    'password' => $parameters['database']['password'],
                    'host' => $parameters['database']['host'],
                    'driver' => $parameters['database']['driver'],
                ));
        
        $datasource = new \Phabric\Datasource\Doctrine(self::$db, $parameters['Phabric']['entities']);

        $this->phabric = new Phabric($datasource);

        $this->phabric->createEntitiesFromConfig($parameters['Phabric']['entities']);

        $this->phabric->addDataTransformation(
                'UKTOMYSQLDATE', function($date) {
                    $date = \DateTime::createFromFormat('d/m/Y H:i', $date);
                    return $date->format('Y-m-d H:i:s');
                }
        );

        $this->phabric->addDataTransformation(
                'ATTENDEELOOKUP', function($attendeeName, $bus) {
                    $ent = $bus->getEntity('attendee');

                    $id = $ent->getNamedItemId($attendeeName);

                    return $id;
                });

        $this->phabric->addDataTransformation(
                'SESSIONLOOKUP', function($sessionName, $bus) {
                    $ent = $bus->getEntity('session');

                    $id = $ent->getNamedItemId($sessionName);
                    
                    return $id;
                });

        $this->phabric->addDataTransformation(
                'UPDOWNTOINT', function($action) {
                    $action = strtoupper($action);
                    switch ($action) {
                        case 'UP':
                            return +1;
                            break;
                        case 'DOWN':
                            return -1;
                        case 'NO VOTE':
                            return 0;
                    }
                });

        $this->phabric->addDataTransformation(
                'SNAKECASE', function($name) {
                    return str_replace(' ', '_', strtolower($name));
                });
    }

    /**
     * @BeforeScenario
     */
    public function functionsetDB() {
        $sql = file_get_contents(__DIR__ . '/../../fixture.sql');
        self::$db->query($sql);
    }

    /**
     * @BeforeSuite
     */
    public static function prepare(SuiteEvent $event) {
        
    }

    /**
     * @Given /^The following events exist$/
     */
    public function theFollowingEventsExist(TableNode $table) {
        $this->phabric->insertFromTable('event', $table);
    }

    /**
     * @When /^I select all records from the event table$/
     */
    public function iSelectAllRecordsFromTheEventTable() {
        $sql = 'SELECT * FROM event';

        $rows = self::$db->fetchAll($sql);
        
        $this->qResult = $rows;
    }

    /**
     * @Then /^I should see the following records$/
     */
    public function iShouldSeeTheFollowingRecords(TableNode $table) {
        
        // Get the col names
        $topRow = reset($this->qResult);

        // Col names - id
        $cols = array_keys($topRow);
        array_shift($cols);

        $actualResults = array($cols);

        foreach ($this->qResult as $row) {
            // Remove the id from the results
            array_shift($row);
            $actualResults[] = array_values($row);
        }

        $expectedResults = $table->getRows();

        assertEquals($expectedResults, $actualResults);
    }
    
        /**
     * @Given /^the following sessions exist$/
     */
    public function theFollowingSessionsExist(TableNode $table)
    {
        $this->phabric->insertFromTable('session', $table);
    }

    /**
     * @Given /^the following attendees exist$/
     */
    public function theFollowingAttendeesExist(TableNode $table)
    {
        $this->phabric->insertFromTable('attendee', $table);
    }

    /**
     * @Given /^the following votes exist$/
     */
    public function theFollowingVotesExist(TableNode $table)
    {   
        $this->phabric->insertFromTable('vote', $table);
    }
    
    /**
     * @Then /^the session "([^"]*)" should have a score of (\d+)$/
     */
    public function theSessionShouldHaveAScoreOf($session, $score)
    {
        $sesPh = $this->phabric->getEntity('session');
        $sessionId = $sesPh->getNamedItemId($session);
        
        $sql = 'SELECT sum(vote) as votes FROM vote WHERE session_id = :id';
        $stmt = self::$db->prepare($sql);
        $stmt->bindValue(':id', $sessionId);
        
        $stmt->execute();
        
        $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        assertequals($score, $result[0]);
    }
    
    /**
     * @When /^The following events are updated$/
     */
    public function theFollowingEventsAreUpdated(TableNode $table)
    {
        $this->phabric->updateFromTable('event', $table);
    }
    
        /**
     * @When /^I reset Phabric$/
     */
    public function iResetPhabric()
    {
       $this->phabric->reset();
    }

    /**
     * @Then /^there sould be not data in the "([^"]*)" table$/
     */
    public function thereSouldBeNotDataInTheTable($table)
    {
       $query = 'SELECT count(*) as records FROM ' . $table;
       
       $st = self::$db->query($query);
       
       $result = $st->fetch();
       
       if($result['records'] > 0)
       {
           throw new Exception("The $table table should be empty. It contains " . $result['records'] . ' records');
       }
    }
    
    /**
     * @Given /^data was loaded independantley of Phabric$/
     */
    public function dataWasLoadedIndependantleyOfPhabric()
    {
        $data = array(
            'name' => 'PBC11',
            'datetime'=> '2011-10-28 09:00:00',
            'venue' => 'Barcellona',
            'description' => 'HOT conf'
        );
        
        $db = self::$db->insert('event', $data);
        
    }
    
    
    /**
     * @When /^I use phabric to update data not managed by phabric$/
     */
    public function iUsePhabricToUpdateDataNotManagedByPhabric(TableNode $table)
    {
        $this->phabric->updateFromTable('event', $table);
    }
    
    /**
     * @Given /^The events described in the featurecontext class exist$/
     */
    public function theEventsDescribedInTheFeaturecontextClassExist()
    {
        $data = array(
           array(
               "Name" => "PHPNW",
               "Date" => "08/10/2011 09:00",
               "Venue" => "Ramada Hotel",
               "Desc" => "An awesome conf!"
           ), 
           array(
               "Name" => "PHPUK",
               "Date" => "27/02/2012 09:00",
               "Venue" => "London Business Center",
               "Desc" => "Quite good conf."
           )
        );
        
         $this->phabric->insertFromArray('event', $data);
    }


}
